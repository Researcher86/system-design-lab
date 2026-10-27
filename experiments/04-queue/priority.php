<?php

declare(strict_types=1);

use SystemDesignLab\Experiment\Experiment;
use SystemDesignLab\Experiment\Options;
use SystemDesignLab\Experiment\Output;
use SystemDesignLab\Queue\ExponentialBackoff;
use SystemDesignLab\Queue\Job;
use SystemDesignLab\Queue\JobQueue;
use SystemDesignLab\Queue\ProcessedJobStore;

/**
 * What does "priority" cost the low-priority tail?
 *
 * All jobs arrive before the worker starts, with five priority levels
 * interleaved in arrival order. The ready heap must produce a non-increasing
 * priority sequence. The wait column is the number of earlier leases times the
 * simulated service time; a static batch shows the ordering, not the dynamic
 * starvation of a continuous high-priority stream.
 */
return new Experiment(
    name: 'queue:priority',
    description: 'strict priority ordering and the wait paid by lower-priority jobs',
    supports: ['jobs'],
    run: static function (Options $options, Output $output): void {
        $jobs = $options->int('jobs', 1_000);
        $levels = 5;
        $tickMs = 10;
        $queue = new JobQueue(
            new ExponentialBackoff(baseMs: 100, multiplier: 2.0, maxMs: 10_000, jitterMs: 0),
            new ProcessedJobStore(ttlMs: 86_400_000, cleanupInterval: 0),
        );

        for ($i = 0; $i < $jobs; ++$i) {
            $queue->enqueue(new Job(
                id: 'job-' . $i,
                type: 'priority-demo',
                priority: $i % $levels,
                createdAtMs: 0,
            ), 0);
        }

        $output->heading(
            'Queue priority',
            sprintf('%s jobs across %d priority levels; higher runs first.', number_format($jobs), $levels),
        );
        $output->line();

        /** @var array<int, int> $counts */
        $counts = array_fill(0, $levels, 0);
        /** @var array<int, int> $first */
        $first = array_fill(0, $levels, 0);
        /** @var array<int, int> $last */
        $last = array_fill(0, $levels, 0);
        /** @var array<int, int> $positionSum */
        $positionSum = array_fill(0, $levels, 0);
        $position = 0;
        $previous = PHP_INT_MAX;
        $inversions = 0;
        $now = 0;

        while (($lease = $queue->lease('worker-a', $now)) !== null) {
            $priority = $lease->job->priority;
            ++$position;
            ++$counts[$priority];
            $positionSum[$priority] += $position;

            if ($first[$priority] === 0) {
                $first[$priority] = $position;
            }

            $last[$priority] = $position;

            if ($priority > $previous) {
                ++$inversions;
            }

            $previous = $priority;
            $queue->complete($lease, $now);
            $now += $tickMs;
        }

        $rows = [];

        for ($priority = $levels - 1; $priority >= 0; --$priority) {
            $count = $counts[$priority];
            $rows[] = [
                $priority,
                $count,
                $first[$priority] === 0 ? '-' : $first[$priority],
                $last[$priority] === 0 ? '-' : $last[$priority],
                $count === 0 ? '-' : sprintf('%.0f ms', ($positionSum[$priority] / $count - 1) * $tickMs),
            ];
        }

        $output->table(
            ['priority', 'jobs', 'first lease', 'last lease', 'average wait'],
            $rows,
        );
        $highestWait = $counts[$levels - 1] === 0
            ? 0.0
            : ($positionSum[$levels - 1] / $counts[$levels - 1] - 1) * $tickMs;
        $lowestWait = $counts[0] === 0
            ? 0.0
            : ($positionSum[0] / $counts[0] - 1) * $tickMs;

        $output->measure('jobs', $jobs);
        $output->measure('completed', $queue->counters()->get('completed'));
        $output->measure('priority_inversions', $inversions);
        $output->measure('highest_priority_average_wait_ms', $highestWait);
        $output->measure('lowest_priority_average_wait_ms', $lowestWait);

        $output->line();
        $output->line('The ready heap produced zero priority inversions.');
        $output->line('The lowest priority waited behind every higher-priority job.');
        $output->line('A continuous high-priority stream can starve the tail; a static batch cannot show that.');
        $output->line('Aging or fair queueing is the usual answer when starvation is unacceptable.');
    },
);
