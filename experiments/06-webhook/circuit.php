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
 * Does a circuit breaker save a delivery from an outage that outlasts its
 * retry budget?
 *
 * Without a circuit, every due delivery keeps retrying into the outage and
 * burns its attempts; a recovery after the last attempt does not help. With a
 * circuit, only one probe per cooldown reaches the endpoint, and the rest of
 * the queue waits for the breaker. The endpoint is down for `fail-ms`.
 */
return new Experiment(
    name: 'webhook:circuit',
    description: 'retry budget exhaustion with and without a circuit breaker during an outage',
    supports: ['events', 'fail-ms', 'max-attempts', 'delay-ms', 'circuit-threshold', 'cooldown-ms'],
    run: static function (Options $options, Output $output): void {
        $events = $options->int('events', 100);
        $failMs = $options->int('fail-ms', 5_000);
        $maxAttempts = $options->int('max-attempts', 6);
        $baseMs = $options->int('delay-ms', 100);
        $threshold = $options->int('circuit-threshold', 5);
        $cooldownMs = $options->int('cooldown-ms', 500);

        $output->heading(
            'Webhook circuit breaker',
            sprintf(
                '%s events, endpoint down for %s ms, max %d attempts, threshold %d, cooldown %d ms.',
                number_format($events),
                number_format($failMs),
                $maxAttempts,
                $threshold,
                $cooldownMs,
            ),
        );
        $output->line();

        /**
         * @return array{mode: string, attempts: int, downAttempts: int, opens: int, deferred: int, delivered: int, deadLettered: int, time: int}
         */
        $run = static function (bool $circuitEnabled) use ($events, $failMs, $maxAttempts, $baseMs, $threshold, $cooldownMs): array {
            $transport = new class ($failMs) implements WebhookTransport {
                public int $downAttempts = 0;

                public function __construct(
                    private readonly int $failUntilMs,
                ) {
                }

                public function send(WebhookRequest $request): WebhookResponse
                {
                    if ($request->timestampMs < $this->failUntilMs) {
                        ++$this->downAttempts;

                        return WebhookResponse::failure(503, 'endpoint down');
                    }

                    return WebhookResponse::success();
                }
            };
            $dispatcher = new WebhookDispatcher();
            $dispatcher->subscribe(new Subscription(
                'sub-circuit',
                'tenant-a',
                'https://example.test/circuit',
                'secret',
                ['link.created'],
            ));
            $worker = new WebhookWorker(
                $transport,
                new Signer(),
                new ExponentialBackoff(baseMs: $baseMs, multiplier: 2.0, maxMs: 10_000, jitterMs: 0),
                maxAttempts: $maxAttempts,
                circuitFailureThreshold: $circuitEnabled ? $threshold : 1_000_000,
                circuitCooldownMs: $circuitEnabled ? $cooldownMs : 0,
            );

            for ($index = 0; $index < $events; ++$index) {
                $event = new WebhookEvent(
                    id: 'event-' . $index,
                    tenantId: 'tenant-a',
                    type: 'link.created',
                    payload: '{"id":"link-' . $index . '"}',
                    createdAtMs: 0,
                );

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

            $counters = $worker->counters();

            return [
                'mode' => $circuitEnabled ? 'circuit' : 'no circuit',
                'attempts' => $counters->get('attempts'),
                'downAttempts' => $transport->downAttempts,
                'opens' => $counters->get('breakerOpens'),
                'deferred' => $counters->get('circuitDeferred'),
                'delivered' => $counters->get('delivered'),
                'deadLettered' => $counters->get('deadLettered'),
                'time' => $now,
            ];
        };

        $variants = [$run(false), $run(true)];
        $rows = [];

        foreach ($variants as $variant) {
            $rows[] = [
                $variant['mode'],
                number_format($variant['attempts']),
                number_format($variant['downAttempts']),
                number_format($variant['opens']),
                number_format($variant['deferred']),
                number_format($variant['delivered']),
                number_format($variant['deadLettered']),
                number_format($variant['time']) . ' ms',
            ];
            $output->measure('attempts_' . str_replace(' ', '_', $variant['mode']), $variant['attempts']);
            $output->measure('delivered_' . str_replace(' ', '_', $variant['mode']), $variant['delivered']);
        }

        $output->table(
            ['mode', 'attempts', 'down attempts', 'opens', 'deferred', 'delivered', 'dead-lettered', 'time'],
            $rows,
        );
        $output->line();
        $output->line('Without a circuit, every retry hits the down endpoint until attempts run out.');
        $output->line('A recovery after the last attempt does not rescue a dead-lettered delivery.');
        $output->line('The circuit lets one probe through per cooldown and defers the rest.');
        $output->line('The breaker consumes no attempt while open; a failed probe does.');
    },
);
