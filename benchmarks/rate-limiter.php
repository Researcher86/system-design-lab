<?php

declare(strict_types=1);

use SystemDesignLab\Benchmark\Benchmark;
use SystemDesignLab\RateLimiter\FixedWindowCounter;
use SystemDesignLab\RateLimiter\SlidingWindowCounter;
use SystemDesignLab\RateLimiter\SlidingWindowLog;
use SystemDesignLab\RateLimiter\TokenBucket;
use SystemDesignLab\RateLimiter\UnlimitedLimiter;

$tokenAllow = new TokenBucket(1_000_000, 1_000_000.0);
$fixedAllow = new FixedWindowCounter(1_000_000, 1_000_000_000);
$logAllow = new SlidingWindowLog(1, 1_000_000_000);
$counterAllow = new SlidingWindowCounter(1_000_000, 1_000_000_000);
$unlimited = new UnlimitedLimiter();

$tokenDenied = new TokenBucket(1, 0.0);
$fixedDenied = new FixedWindowCounter(1, 1_000_000_000);
$logDenied = new SlidingWindowLog(1, 1_000_000_000);
$counterDenied = new SlidingWindowCounter(1, 1_000_000_000);

foreach ([$tokenDenied, $fixedDenied, $logDenied, $counterDenied] as $limiter) {
    $limiter->attempt('blocked', 0);
}

return [
    new Benchmark(
        name: 'unlimited attempt',
        iterations: 20_000,
        run: static fn (): mixed => $unlimited->attempt('tenant-a', 0),
    ),
    new Benchmark(
        name: 'token bucket allow',
        iterations: 20_000,
        run: static function () use ($tokenAllow): mixed {
            static $index = 0;

            return $tokenAllow->attempt('bench-' . $index++, 0);
        },
    ),
    new Benchmark(
        name: 'fixed window allow',
        iterations: 20_000,
        run: static function () use ($fixedAllow): mixed {
            static $index = 0;

            return $fixedAllow->attempt('bench-' . $index++, 0);
        },
    ),
    new Benchmark(
        name: 'sliding log allow',
        iterations: 20_000,
        run: static function () use ($logAllow): mixed {
            static $index = 0;

            return $logAllow->attempt('bench-' . $index++, 0);
        },
    ),
    new Benchmark(
        name: 'sliding counter allow',
        iterations: 20_000,
        run: static function () use ($counterAllow): mixed {
            static $index = 0;

            return $counterAllow->attempt('bench-' . $index++, 0);
        },
    ),
    new Benchmark(
        name: 'token bucket deny',
        iterations: 20_000,
        run: static fn (): mixed => $tokenDenied->attempt('blocked', 0),
    ),
    new Benchmark(
        name: 'fixed window deny',
        iterations: 20_000,
        run: static fn (): mixed => $fixedDenied->attempt('blocked', 0),
    ),
    new Benchmark(
        name: 'sliding log deny',
        iterations: 20_000,
        run: static fn (): mixed => $logDenied->attempt('blocked', 0),
    ),
    new Benchmark(
        name: 'sliding counter deny',
        iterations: 20_000,
        run: static fn (): mixed => $counterDenied->attempt('blocked', 0),
    ),
];
