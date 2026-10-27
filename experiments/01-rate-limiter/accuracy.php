<?php

declare(strict_types=1);

use SystemDesignLab\Experiment\Experiment;
use SystemDesignLab\Experiment\Options;
use SystemDesignLab\Experiment\Output;
use SystemDesignLab\Foundation\DeterministicRandom;
use SystemDesignLab\RateLimiter\FixedWindowCounter;
use SystemDesignLab\RateLimiter\RateLimiter;
use SystemDesignLab\RateLimiter\SlidingWindowCounter;
use SystemDesignLab\RateLimiter\SlidingWindowLog;

/**
 * How much does the approximation cost in wrong decisions?
 *
 * An exact sliding log is the oracle. Every other policy is replayed against
 * the same arrival times, and each decision is compared with the oracle's
 * decision at that instant. The output counts false allows and false denies,
 * not just the admitted total.
 */
return new Experiment(
    name: 'rate:accuracy',
    description: 'false allows and false denies of fixed and two-window limiters against an exact log',
    supports: ['requests', 'limit', 'window-ms', 'seed'],
    run: static function (Options $options, Output $output): void {
        $requests = $options->int('requests', 2000);
        $limit = $options->int('limit', 10);
        $windowMs = $options->int('window-ms', 1000);
        $random = new DeterministicRandom($options->seed('book'));
        $times = [];
        $now = 0;

        for ($i = 0; $i < $requests; ++$i) {
            $now += $random->intBetween(0, 20);
            $times[] = $now;
        }

        $oracle = oracleDecisions($times, $limit, $windowMs);

        /** @var array<string, RateLimiter> $limiters */
        $limiters = [
            'sliding-log' => new SlidingWindowLog($limit, $windowMs),
            'fixed-window' => new FixedWindowCounter($limit, $windowMs),
            'sliding-counter' => new SlidingWindowCounter($limit, $windowMs),
        ];

        $output->heading('Rate limiter accuracy', 'The exact sliding log is the oracle; every mismatch is counted.');
        $output->line(sprintf('%d requests, limit=%d, window=%d ms, span=%d ms', $requests, $limit, $windowMs, $times[$requests - 1]));
        $output->line();

        $rows = [];

        foreach ($limiters as $name => $limiter) {
            $falseAllows = 0;
            $falseDenies = 0;
            $allowed = 0;

            foreach ($times as $index => $timestamp) {
                $actual = $limiter->attempt('tenant-a', $timestamp)->allowed;
                $expected = $oracle[$index];

                if ($actual) {
                    ++$allowed;
                }

                if ($actual && !$expected) {
                    ++$falseAllows;
                } elseif (!$actual && $expected) {
                    ++$falseDenies;
                }
            }

            $rows[] = [$name, $allowed, $falseAllows, $falseDenies];
            $output->measure(sprintf('%s_false_allows', $name), $falseAllows);
            $output->measure(sprintf('%s_false_denies', $name), $falseDenies);
            $output->measure(sprintf('%s_allowed', $name), $allowed);
        }

        $output->table(['strategy', 'allowed', 'false allows', 'false denies'], $rows);
        $output->line();
        $output->line('The sliding log is exact by construction; its row is the control.');
        $output->line('The fixed window pays for its cheap state at the boundaries.');
        $output->line('The two-window counter is an estimate, not a sliding log with a smaller map.');
    },
);

/**
 * @param list<int> $times
 *
 * @return list<bool>
 */
function oracleDecisions(array $times, int $limit, int $windowMs): array
{
    /** @var list<int> $accepted */
    $accepted = [];
    $decisions = [];

    foreach ($times as $timestamp) {
        $accepted = array_values(array_filter(
            $accepted,
            static fn (int $stamp): bool => $stamp > $timestamp - $windowMs,
        ));

        $allowed = count($accepted) < $limit;
        $decisions[] = $allowed;

        if ($allowed) {
            $accepted[] = $timestamp;
        }
    }

    return $decisions;
}
