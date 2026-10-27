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
 * How many sends does a failing endpoint consume before the delivery dies?
 *
 * Every third delivery fails on its first attempt, every third on its first
 * two, and every tenth fails forever. A retry is another HTTP attempt with the
 * same idempotency key, not a new delivery. The circuit breaker is effectively
 * disabled here so the retry counts stay visible; the circuit experiment tests
 * the open state separately.
 */
return new Experiment(
    name: 'webhook:retry',
    description: 'webhook attempts, exponential retry delays and dead letters',
    supports: ['events', 'max-attempts', 'delay-ms', 'jitter-ms'],
    run: static function (Options $options, Output $output): void {
        $events = $options->int('events', 1_000);
        $maxAttempts = $options->int('max-attempts', 4);
        $baseMs = $options->int('delay-ms', 100);
        $jitterMs = $options->int('jitter-ms', 0);

        $transport = new class () implements WebhookTransport {
            public int $requests = 0;

            /** @var array<string, int> */
            public array $attempts = [];

            public function send(WebhookRequest $request): WebhookResponse
            {
                ++$this->requests;
                $attempt = ($this->attempts[$request->idempotencyKey] ?? 0) + 1;
                $this->attempts[$request->idempotencyKey] = $attempt;
                $index = (int) substr($request->eventId, 6);
                $alwaysFails = $index % 10 === 0;
                $failures = $index % 3;

                if ($alwaysFails || $attempt <= $failures) {
                    return WebhookResponse::failure(500, $alwaysFails ? 'permanent failure' : 'transient failure');
                }

                return WebhookResponse::success();
            }
        };
        $dispatcher = new WebhookDispatcher();
        $dispatcher->subscribe(new Subscription(
            'sub-retry',
            'tenant-a',
            'https://example.test/retry',
            'secret',
            ['link.created'],
        ));
        $worker = new WebhookWorker(
            $transport,
            new Signer(),
            new ExponentialBackoff(baseMs: $baseMs, multiplier: 2.0, maxMs: 10_000, jitterMs: $jitterMs),
            maxAttempts: $maxAttempts,
            circuitFailureThreshold: 1_000_000,
            circuitCooldownMs: 0,
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
        $output->heading(
            'Webhook retries',
            sprintf(
                '%s events, max %d attempts, base delay %d ms, jitter %d ms.',
                number_format($events),
                $maxAttempts,
                $baseMs,
                $jitterMs,
            ),
        );
        $output->line();
        $output->table(
            ['metric', 'value'],
            [
                ['events', number_format($events)],
                ['deliveries', number_format($dispatcher->counters()->get('deliveriesCreated'))],
                ['delivered', number_format($counters->get('delivered'))],
                ['dead-lettered', number_format($counters->get('deadLettered'))],
                ['attempts', number_format($counters->get('attempts'))],
                ['failures', number_format($counters->get('failed'))],
                ['retries', number_format($counters->get('retried'))],
                ['total retry delay', number_format($counters->get('retryDelayMs')) . ' ms'],
                ['simulated time', number_format($now) . ' ms'],
                ['endpoint requests', number_format($transport->requests)],
                ['processed ids', number_format($worker->processed()->size())],
            ],
        );

        foreach (['events', 'delivered', 'deadLettered', 'attempts', 'failed', 'retried'] as $name) {
            $output->measure($name, $counters->get($name));
        }

        $output->measure('deliveries', $dispatcher->counters()->get('deliveriesCreated'));
        $output->measure('retry_delay_ms', $counters->get('retryDelayMs'));
        $output->measure('simulated_time_ms', $now);
        $output->line();
        $output->line('A retry is another attempt of the same delivery, not a new delivery.');
        $output->line('The same idempotency key travels with every attempt.');
        $output->line('The backoff doubles from the base delay and is capped.');
        $output->line('A delivery that exhausts its attempts leaves through the dead-letter list.');
    },
);
