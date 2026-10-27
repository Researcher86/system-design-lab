<?php

declare(strict_types=1);

use SystemDesignLab\Experiment\Experiment;
use SystemDesignLab\Experiment\Options;
use SystemDesignLab\Experiment\Output;
use SystemDesignLab\Queue\ExponentialBackoff;
use SystemDesignLab\Webhook\Signer;
use SystemDesignLab\Webhook\Subscription;
use SystemDesignLab\Webhook\WebhookDispatcher;
use SystemDesignLab\Webhook\WebhookEvent;
use SystemDesignLab\Webhook\WebhookRequest;
use SystemDesignLab\Webhook\WebhookResponse;
use SystemDesignLab\Webhook\WebhookTransport;
use SystemDesignLab\Webhook\WebhookWorker;

/**
 * What happens when the endpoint processes a request but the response is lost?
 *
 * The worker cannot know that the side effect happened, so it retries. The
 * endpoint keys its effect by the idempotency key and suppresses the second
 * attempt. A replay of the original event is then rejected by the worker's
 * processed-id store. This is at-least-once delivery with an idempotent
 * receiver, not exactly-once delivery.
 */
return new Experiment(
    name: 'webhook:duplicates',
    description: 'lost responses, endpoint idempotency and duplicate event replay',
    supports: ['events', 'max-attempts', 'delay-ms'],
    run: static function (Options $options, Output $output): void {
        $events = $options->int('events', 10_000);
        $maxAttempts = $options->int('max-attempts', 4);
        $baseMs = $options->int('delay-ms', 100);

        $output->heading(
            'Webhook duplicates',
            sprintf('%s events, a lost response on the first attempt, base delay %d ms.', number_format($events), $baseMs),
        );
        $output->line();

        /**
         * @return array{mode: string, attempts: int, effects: int, suppressed: int, retries: int, replays: int, delivered: int, deadLettered: int}
         */
        $run = static function (bool $loseResponse) use ($events, $maxAttempts, $baseMs): array {
            $transport = new class ($loseResponse) implements WebhookTransport {
                public int $requests = 0;

                public int $suppressed = 0;

                /** @var array<string, true> */
                public array $effects = [];

                public function __construct(
                    private readonly bool $loseFirstResponse,
                ) {
                }

                public function send(WebhookRequest $request): WebhookResponse
                {
                    ++$this->requests;

                    if (isset($this->effects[$request->idempotencyKey])) {
                        ++$this->suppressed;

                        return WebhookResponse::success();
                    }

                    $this->effects[$request->idempotencyKey] = true;

                    if ($this->loseFirstResponse && $request->attempt === 1) {
                        return WebhookResponse::failure(0, 'response lost after the side effect');
                    }

                    return WebhookResponse::success();
                }
            };
            $dispatcher = new WebhookDispatcher();
            $dispatcher->subscribe(new Subscription(
                'sub-duplicates',
                'tenant-a',
                'https://example.test/duplicates',
                'secret',
                ['link.created'],
            ));
            $worker = new WebhookWorker(
                $transport,
                new Signer(),
                new ExponentialBackoff(baseMs: $baseMs, multiplier: 2.0, maxMs: 10_000, jitterMs: 0),
                maxAttempts: $maxAttempts,
                circuitFailureThreshold: 1_000_000,
                circuitCooldownMs: 0,
            );
            $eventsList = [];

            for ($index = 0; $index < $events; ++$index) {
                $event = new WebhookEvent(
                    id: 'event-' . $index,
                    tenantId: 'tenant-a',
                    type: 'link.created',
                    payload: '{"id":"link-' . $index . '"}',
                    createdAtMs: 0,
                );
                $eventsList[] = $event;

                foreach ($dispatcher->dispatch($event) as $delivery) {
                    $worker->enqueue($delivery, 0);
                }
            }

            $now = 0;

            while ($worker->pendingCount() > 0) {
                $worker->deliverDue($now);
                $next = $worker->nextAvailableAtMs();

                if ($next === null) {
                    break;
                }

                $now = $next <= $now ? $now + 1 : $next;
            }

            $replays = 0;

            foreach ($eventsList as $event) {
                foreach ($dispatcher->dispatch($event) as $delivery) {
                    if (!$worker->enqueue($delivery, $now)) {
                        ++$replays;
                    }
                }
            }

            $counters = $worker->counters();

            return [
                'mode' => $loseResponse ? 'lost response' : 'clean response',
                'attempts' => $counters->get('attempts'),
                'effects' => count($transport->effects),
                'suppressed' => $transport->suppressed,
                'retries' => $counters->get('retried'),
                'replays' => $replays,
                'delivered' => $counters->get('delivered'),
                'deadLettered' => $counters->get('deadLettered'),
            ];
        };

        $variants = [$run(false), $run(true)];
        $rows = [];

        foreach ($variants as $variant) {
            $rows[] = [
                $variant['mode'],
                number_format($variant['attempts']),
                number_format($variant['effects']),
                number_format($variant['suppressed']),
                number_format($variant['retries']),
                number_format($variant['replays']),
                number_format($variant['delivered']),
                number_format($variant['deadLettered']),
            ];
            $output->measure('effects_' . str_replace(' ', '_', $variant['mode']), $variant['effects']);
            $output->measure('suppressed_' . str_replace(' ', '_', $variant['mode']), $variant['suppressed']);
        }

        $output->table(
            ['mode', 'attempts', 'unique effects', 'duplicate suppressed', 'retries', 'replays rejected', 'delivered', 'dead-lettered'],
            $rows,
        );
        $output->line();
        $output->line('A lost response turns one delivery into two HTTP attempts.');
        $output->line('The endpoint performs the side effect once because the key is stable.');
        $output->line('The worker rejects a replay of an already completed delivery inside the TTL.');
        $output->line('The guarantee is at-least-once plus idempotency, not exactly-once.');
    },
);
