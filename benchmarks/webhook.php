<?php

declare(strict_types=1);

use SystemDesignLab\Benchmark\Benchmark;
use SystemDesignLab\Queue\ExponentialBackoff;
use SystemDesignLab\Webhook\CircuitBreaker;
use SystemDesignLab\Webhook\Signer;
use SystemDesignLab\Webhook\Subscription;
use SystemDesignLab\Webhook\WebhookDelivery;
use SystemDesignLab\Webhook\WebhookDispatcher;
use SystemDesignLab\Webhook\WebhookEvent;
use SystemDesignLab\Webhook\WebhookRequest;
use SystemDesignLab\Webhook\WebhookResponse;
use SystemDesignLab\Webhook\WebhookTransport;
use SystemDesignLab\Webhook\WebhookWorker;

$signer = new Signer();
$payload = '{"event":"link.created","id":"link-1"}';
$signature = $signer->sign('secret', $payload, 0);

$oneDispatcher = new WebhookDispatcher();
$oneDispatcher->subscribe(new Subscription(
    'sub-1',
    'tenant-a',
    'https://example.test/one',
    'secret',
    ['link.created'],
));

$tenDispatcher = new WebhookDispatcher();

for ($index = 0; $index < 10; ++$index) {
    $tenDispatcher->subscribe(new Subscription(
        'sub-' . $index,
        'tenant-a',
        'https://example.test/hook-' . $index,
        'secret-' . $index,
        ['link.created'],
    ));
}

$closedBreaker = new CircuitBreaker(5, 1_000);
$openBreaker = new CircuitBreaker(1, 1_000_000);
$openBreaker->recordFailure(0);

$transport = new class () implements WebhookTransport {
    public function send(WebhookRequest $request): WebhookResponse
    {
        return WebhookResponse::success();
    }
};
$worker = new WebhookWorker(
    $transport,
    $signer,
    new ExponentialBackoff(baseMs: 100, multiplier: 2.0, maxMs: 10_000, jitterMs: 0),
    maxAttempts: 5,
    circuitFailureThreshold: 1_000,
    circuitCooldownMs: 1_000,
);
$workerSubscription = new Subscription(
    'sub-worker',
    'tenant-a',
    'https://example.test/worker',
    'secret',
    ['link.created'],
);

return [
    new Benchmark(
        name: 'sign',
        iterations: 100_000,
        run: static fn (): string => $signer->sign('secret', $payload, 1_700_000_000_000),
    ),
    new Benchmark(
        name: 'verify',
        iterations: 100_000,
        run: static fn (): bool => $signer->verify('secret', $payload, $signature, 0),
    ),
    new Benchmark(
        name: 'dispatch 1',
        iterations: 20_000,
        run: static function () use ($oneDispatcher): array {
            static $index = 0;

            return $oneDispatcher->dispatch(new WebhookEvent(
                'event-' . $index++,
                'tenant-a',
                'link.created',
                '{}',
            ));
        },
    ),
    new Benchmark(
        name: 'dispatch 10',
        iterations: 10_000,
        run: static function () use ($tenDispatcher): array {
            static $index = 0;

            return $tenDispatcher->dispatch(new WebhookEvent(
                'event-' . $index++,
                'tenant-a',
                'link.created',
                '{}',
            ));
        },
    ),
    new Benchmark(
        name: 'breaker allow closed',
        iterations: 100_000,
        run: static fn (): bool => $closedBreaker->allow(0),
    ),
    new Benchmark(
        name: 'breaker reject open',
        iterations: 100_000,
        run: static fn (): bool => $openBreaker->allow(0),
    ),
    new Benchmark(
        name: 'worker deliver success',
        iterations: 20_000,
        run: static function () use ($worker, $workerSubscription): mixed {
            static $index = 0;
            $event = new WebhookEvent(
                'event-' . $index,
                'tenant-a',
                'link.created',
                '{}',
            );
            $delivery = new WebhookDelivery(
                'delivery-' . $index,
                $event,
                $workerSubscription,
                0,
            );
            ++$index;
            $worker->enqueue($delivery, 0);

            return $worker->deliverDue(0);
        },
    ),
];
