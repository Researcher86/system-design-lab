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
 * When producers outrun consumers, the queue does not disappear; it grows.
 *
 * The experiment produces at a fixed rate and completes at another. Depth is
 * the pending work after each tick; age is the distance between now and the
 * oldest pending job. With producer > consumer both grow; with consumer >=
 * producer the queue drains. The failure moved from latency to backlog.
 */
return new Experiment(
    name: 'queue:backlog',
    description: 'queue depth and oldest-job age when the producer outruns the consumer',
    supports: ['ticks', 'tick-ms', 'producer-rate', 'consumer-rate'],
    run: static function (Options $options, Output $output): void {
        $ticks = $options->int('ticks', 100);
        $tickMs = $options->int('tick-ms', 1_000);
        $producerRate = $options->int('producer-rate', 100);
        $consumerRate = $options->int('consumer-rate', 80);
        $queue = new JobQueue(
            new ExponentialBackoff(baseMs: 100, multiplier: 2.0, maxMs: 10_000, jitterMs: 0),
            new ProcessedJobStore(ttlMs: 86_400_000, cleanupInterval: 0),
        );

        $output->heading(
            'Queue backlog',
            sprintf(
                '%d ticks of %d ms: produce %d, complete %d per tick.',
                $ticks,
                $tickMs,
                $producerRate,
                $consumerRate,
            ),
        );
        $output->line();

        $produced = 0;
        $consumed = 0;
        $maxDepth = 0;
        $rows = [];
        $oldestAge = 0;

        for ($tick = 0; $tick < $ticks; ++$tick) {
            $now = $tick * $tickMs;

            for ($i = 0; $i < $producerRate; ++$i) {
                $queue->enqueue(new Job(
                    id: 'job-' . $tick . '-' . $i,
                    type: 'backlog-demo',
                    priority: 0,
                    createdAtMs: $now,
                ), $now);
                ++$produced;
            }

            for ($i = 0; $i < $consumerRate; ++$i) {
                $lease = $queue->lease('worker-a', $now);

                if ($lease === null) {
                    break;
                }

                $queue->complete($lease, $now);
                ++$consumed;
            }

            $depth = $queue->pending();
            $maxDepth = max($maxDepth, $depth);
            $oldest = $queue->oldestPendingAtMs();
            $oldestAge = $oldest === null ? 0 : $now - $oldest;

            if ($tick % 10 === 0 || $tick === $ticks - 1) {
                $rows[] = [
                    $tick,
                    number_format($produced),
                    number_format($consumed),
                    number_format($depth),
                    number_format($oldestAge) . ' ms',
                ];
            }
        }

        $output->table(
            ['tick', 'produced', 'consumed', 'depth', 'oldest age'],
            $rows,
        );
        $output->measure('produced', $produced);
        $output->measure('consumed', $consumed);
        $output->measure('final_depth', $queue->pending());
        $output->measure('max_depth', $maxDepth);
        $output->measure('oldest_age_ms', $oldestAge);
        $output->measure('backlog_growth_per_tick', $queue->pending() / $ticks);

        $output->line();
        $output->line('Producer minus consumer is the per-tick growth when the queue is saturated.');
        $output->line('Depth says how much work is waiting; age says how long the oldest item has waited.');
        $output->line('A queue absorbs a burst by turning it into backlog and latency.');
        $output->line('With consumer >= producer the same run drains instead of growing.');
    },
);
