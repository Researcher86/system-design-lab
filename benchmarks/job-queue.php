<?php

declare(strict_types=1);

use SystemDesignLab\Benchmark\Benchmark;
use SystemDesignLab\Queue\ExponentialBackoff;
use SystemDesignLab\Queue\Job;
use SystemDesignLab\Queue\JobQueue;
use SystemDesignLab\Queue\ProcessedJobStore;

$backoff = new ExponentialBackoff(baseMs: 100, multiplier: 2.0, maxMs: 10_000, jitterMs: 0);

$enqueueQueue = new JobQueue($backoff, new ProcessedJobStore(ttlMs: 86_400_000, cleanupInterval: 0));
$delayedQueue = new JobQueue($backoff, new ProcessedJobStore(ttlMs: 86_400_000, cleanupInterval: 0));
$roundTripQueue = new JobQueue($backoff, new ProcessedJobStore(ttlMs: 86_400_000, cleanupInterval: 0));
$failureQueue = new JobQueue($backoff, new ProcessedJobStore(ttlMs: 86_400_000, cleanupInterval: 0));
$promoteQueue = new JobQueue($backoff, new ProcessedJobStore(ttlMs: 86_400_000, cleanupInterval: 0));

$oldestQueue = new JobQueue($backoff, new ProcessedJobStore(ttlMs: 86_400_000, cleanupInterval: 0));

for ($i = 0; $i < 1_000; ++$i) {
    $oldestQueue->enqueue(new Job(
        id: 'oldest-' . $i,
        type: 'benchmark',
        priority: 0,
        createdAtMs: $i,
    ), $i);
}

return [
    new Benchmark(
        name: 'enqueue ready',
        iterations: 10_000,
        run: static function () use ($enqueueQueue): bool {
            static $index = 0;

            return $enqueueQueue->enqueue(new Job(
                id: 'ready-' . $index,
                type: 'benchmark',
                priority: $index++ % 5,
                createdAtMs: 0,
            ), 0);
        },
    ),
    new Benchmark(
        name: 'enqueue delayed',
        iterations: 10_000,
        run: static function () use ($delayedQueue): bool {
            static $index = 0;

            return $delayedQueue->enqueue(new Job(
                id: 'delayed-' . $index,
                type: 'benchmark',
                priority: $index++ % 5,
                createdAtMs: 0,
                availableAtMs: 1_000_000,
            ), 0);
        },
    ),
    new Benchmark(
        name: 'enqueue lease complete',
        iterations: 20_000,
        run: static function () use ($roundTripQueue): bool {
            static $index = 0;
            $now = $index++;
            $roundTripQueue->enqueue(new Job(
                id: 'round-' . $now,
                type: 'benchmark',
                priority: 0,
                createdAtMs: $now,
            ), $now);
            $lease = $roundTripQueue->lease('benchmark', $now);

            if ($lease === null) {
                throw new RuntimeException('The round-trip queue lost its job');
            }

            return $roundTripQueue->complete($lease, $now);
        },
    ),
    new Benchmark(
        name: 'enqueue lease fail',
        iterations: 10_000,
        run: static function () use ($failureQueue): mixed {
            static $index = 0;
            $now = $index++;
            $failureQueue->enqueue(new Job(
                id: 'fail-' . $now,
                type: 'benchmark',
                priority: 0,
                createdAtMs: $now,
                maxAttempts: 1,
            ), $now);
            $lease = $failureQueue->lease('benchmark', $now);

            if ($lease === null) {
                throw new RuntimeException('The failure queue lost its job');
            }

            return $failureQueue->fail($lease, $now, 'benchmark failure');
        },
    ),
    new Benchmark(
        name: 'promote due',
        iterations: 10_000,
        run: static function () use ($promoteQueue): int {
            static $index = 0;
            $now = $index++;
            $promoteQueue->enqueue(new Job(
                id: 'promote-' . $now,
                type: 'benchmark',
                priority: 0,
                createdAtMs: 0,
                availableAtMs: $now + 1,
            ), $now);

            return $promoteQueue->promoteDue($now + 1);
        },
    ),
    new Benchmark(
        name: 'oldest pending',
        iterations: 100_000,
        run: static fn (): ?int => $oldestQueue->oldestPendingAtMs(),
    ),
];
