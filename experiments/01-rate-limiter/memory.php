<?php

declare(strict_types=1);

use SystemDesignLab\Experiment\Experiment;
use SystemDesignLab\Experiment\Options;
use SystemDesignLab\Experiment\Output;
use SystemDesignLab\Foundation\ByteFormatter;
use SystemDesignLab\RateLimiter\FixedWindowCounter;
use SystemDesignLab\RateLimiter\RateLimiter;
use SystemDesignLab\RateLimiter\SlidingWindowCounter;
use SystemDesignLab\RateLimiter\SlidingWindowLog;
use SystemDesignLab\RateLimiter\TokenBucket;

/**
 * The memory bill for the same limit.
 *
 * A sliding log stores accepted timestamps; a token bucket, a fixed window and
 * a two-window counter store one small record per key. The measurement is PHP
 * allocator memory, not RSS.
 */
return new Experiment(
    name: 'rate:memory',
    description: 'PHP memory per tracked key for four rate-limiting policies',
    supports: ['keys', 'limit', 'window-ms'],
    run: static function (Options $options, Output $output): void {
        $keys = $options->int('keys', 10_000);
        $limit = $options->int('limit', 10);
        $windowMs = $options->int('window-ms', 1000);
        $labels = [];

        for ($i = 0; $i < $keys; ++$i) {
            $labels[] = 'tenant-' . $i;
        }

        $output->heading('Rate limiter memory', sprintf('%d keys at limit=%d; PHP allocator memory, not RSS.', $keys, $limit));
        $output->line();

        $rows = [];

        /** @var array<string, callable(): RateLimiter> $factories */
        $factories = [
            'token-bucket' => static fn (): RateLimiter => new TokenBucket($limit, 10.0),
            'fixed-window' => static fn (): RateLimiter => new FixedWindowCounter($limit, $windowMs),
            'sliding-log' => static fn (): RateLimiter => new SlidingWindowLog($limit, $windowMs),
            'sliding-counter' => static fn (): RateLimiter => new SlidingWindowCounter($limit, $windowMs),
        ];

        foreach ($factories as $name => $factory) {
            gc_collect_cycles();
            $limiter = $factory();
            $before = memory_get_usage(false);

            foreach ($labels as $label) {
                for ($attempt = 0; $attempt < $limit; ++$attempt) {
                    $limiter->attempt($label, 0);
                }
            }

            gc_collect_cycles();
            $delta = memory_get_usage(false) - $before;
            $rows[] = [
                $name,
                ByteFormatter::format($delta),
                sprintf('%.1f', $delta / $keys),
                $limit === 1 ? 1 : $limiter->trackedKeys(),
            ];
            $output->measure(sprintf('%s_bytes', $name), $delta);
            $output->measure(sprintf('%s_bytes_per_key', $name), $delta / $keys);
        }

        $output->table(['strategy', 'total', 'bytes/key', 'keys'], $rows);
        $output->line();
        $output->line('The sliding log is exact, but it pays for every accepted timestamp.');
        $output->line('The other three keep one record per key; the two-window counter keeps no timestamp list.');
    },
);
