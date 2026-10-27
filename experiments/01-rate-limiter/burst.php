<?php

declare(strict_types=1);

use SystemDesignLab\Experiment\Experiment;
use SystemDesignLab\Experiment\Options;
use SystemDesignLab\Experiment\Output;
use SystemDesignLab\RateLimiter\FixedWindowCounter;
use SystemDesignLab\RateLimiter\RateLimiter;
use SystemDesignLab\RateLimiter\SlidingWindowCounter;
use SystemDesignLab\RateLimiter\SlidingWindowLog;
use SystemDesignLab\RateLimiter\TokenBucket;

/**
 * What a limiter does when clients bunch up at a window boundary.
 *
 * Six bursts of `limit` requests are placed just before and just after three
 * window boundaries. A fixed window admits both sides of a boundary; an exact
 * sliding log does not; a two-window counter approximates; a token bucket
 * smooths the refill after its initial burst.
 */
return new Experiment(
    name: 'rate:burst',
    description: 'boundary bursts through token bucket, fixed window, sliding log and sliding counter',
    supports: ['limit', 'window-ms', 'refill-per-second'],
    run: static function (Options $options, Output $output): void {
        $limit = $options->int('limit', 10);
        $windowMs = $options->int('window-ms', 1000);
        $refill = $options->float('refill-per-second', 10.0);
        $times = [];

        foreach ([999, 1000, 1999, 2000, 2999, 3000] as $timestamp) {
            for ($i = 0; $i < $limit; ++$i) {
                $times[] = $timestamp;
            }
        }

        /** @var list<RateLimiter> $limiters */
        $limiters = [
            new TokenBucket($limit, $refill),
            new FixedWindowCounter($limit, $windowMs),
            new SlidingWindowLog($limit, $windowMs),
            new SlidingWindowCounter($limit, $windowMs),
        ];

        $output->heading('Rate limiter boundary bursts', sprintf('%d attempts at six timestamps around a %d ms boundary.', count($times), $windowMs));
        $output->line(sprintf('limit=%d, refill=%s tokens/sec', $limit, $refill));
        $output->line();

        $rows = [];

        foreach ($limiters as $limiter) {
            $allowed = 0;
            $denied = 0;
            $acceptedAt = [];

            foreach ($times as $timestamp) {
                if ($limiter->attempt('tenant-a', $timestamp)->allowed) {
                    ++$allowed;
                    $acceptedAt[] = $timestamp;
                } else {
                    ++$denied;
                }
            }

            $rows[] = [
                $limiter->name(),
                $allowed,
                $denied,
                maxInWindow($acceptedAt, $windowMs),
                $limiter->trackedKeys(),
            ];

            $output->measure(sprintf('%s_allowed', $limiter->name()), $allowed);
            $output->measure(sprintf('%s_denied', $limiter->name()), $denied);
            $output->measure(sprintf('%s_max_in_window', $limiter->name()), maxInWindow($acceptedAt, $windowMs));
        }

        $output->table(
            ['strategy', 'allowed', 'denied', 'max in any window', 'keys'],
            $rows,
        );
        $output->line();
        $output->line('The exact sliding log never admits more than the limit in one window.');
        $output->line('The fixed window can spend the whole budget on both sides of a boundary.');
        $output->line('The two-window estimate makes a different trade-off; rate:accuracy counts its wrong decisions.');
        $output->line('A token bucket permits a burst of capacity, then refills at the configured rate.');
    },
);

/**
 * Largest number of accepted timestamps in any inclusive interval of
 * `$windowMs`. The timestamps arrive in non-decreasing order.
 *
 * @param list<int> $timestamps
 */
function maxInWindow(array $timestamps, int $windowMs): int
{
    $left = 0;
    $best = 0;

    foreach ($timestamps as $right => $timestamp) {
        while ($timestamps[$left] <= $timestamp - $windowMs) {
            ++$left;
        }

        $best = max($best, $right - $left + 1);
    }

    return $best;
}
