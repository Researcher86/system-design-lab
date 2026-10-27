<?php

declare(strict_types=1);

use SystemDesignLab\Experiment\Experiment;
use SystemDesignLab\Experiment\Options;
use SystemDesignLab\Experiment\Output;
use SystemDesignLab\Queue\ExponentialBackoff;
use SystemDesignLab\Queue\FailureStatus;
use SystemDesignLab\Queue\Job;
use SystemDesignLab\Queue\JobQueue;
use SystemDesignLab\Queue\ProcessedJobStore;

/**
 * How many deliveries does a failing job consume before it is dead-lettered?
 *
 * Every fifth job fails on every attempt; the others fail a deterministic
 * number of times and then succeed. The queue is driven by one worker, so the
 * retry delays are visible as simulated time. Attempts, failures, retries and
 * dead letters are counted separately: a retry is not a new job.
 */
return new Experiment(
    name: 'queue:retry',
    description: 'retry attempts, exponential delays and dead letters for a mixed job stream',
    supports: ['jobs', 'max-attempts'],
    run: static function (Options $options, Output $output): void {
        $jobs = $options->int('jobs', 1_000);
        $maxAttempts = $options->int('max-attempts', 3);
        $queue = new JobQueue(
            new ExponentialBackoff(baseMs: 100, multiplier: 2.0, maxMs: 10_000, jitterMs: 0),
            new ProcessedJobStore(ttlMs: 86_400_000, cleanupInterval: 0),
        );

        $output->heading(
            'Queue retries',
            sprintf('%s jobs, max %d attempts, every fifth job fails permanently.', number_format($jobs), $maxAttempts),
        );
        $output->line();

        $now = 0;
        $completed = 0;
        $deadLettered = 0;
        $totalDelay = 0;
        $completedIds = [];

        for ($i = 0; $i < $jobs; ++$i) {
            $job = new Job(
                id: 'job-' . $i,
                type: 'retry-demo',
                priority: 0,
                createdAtMs: $now,
                maxAttempts: $maxAttempts,
            );
            $queue->enqueue($job, $now);

            $alwaysFails = $i % 5 === 0;
            $failureBudget = $alwaysFails ? $maxAttempts : $i % $maxAttempts;

            while (true) {
                $lease = $queue->lease('worker-a', $now, 60_000);

                if ($lease === null) {
                    throw new RuntimeException('The queue lost a pending retry');
                }

                $attempt = $lease->job->attempts();

                if (!$alwaysFails && $attempt > $failureBudget) {
                    $queue->complete($lease, $now);
                    ++$completed;
                    $completedIds[] = $job->id;

                    break;
                }

                $outcome = $queue->fail($lease, $now, 'injected failure');

                if ($outcome->status === FailureStatus::DeadLettered) {
                    ++$deadLettered;

                    break;
                }

                $now += $outcome->retryDelayMs;
                $totalDelay += $outcome->retryDelayMs;
            }

            ++$now;
        }

        $replayed = 0;

        if ($completedIds !== []) {
            $replayed = $queue->enqueue(new Job(
                id: $completedIds[0],
                type: 'retry-demo',
                priority: 0,
                createdAtMs: $now,
                maxAttempts: $maxAttempts,
            ), $now) ? 0 : 1;
        }

        $counters = $queue->counters();
        $output->table(
            ['metric', 'value'],
            [
                ['jobs', number_format($jobs)],
                ['completed', number_format($completed)],
                ['dead-lettered', number_format($deadLettered)],
                ['lease attempts', number_format($counters->get('leased'))],
                ['failures', number_format($counters->get('failed'))],
                ['retries', number_format($counters->get('retried'))],
                ['total retry delay', number_format($totalDelay) . ' ms'],
                ['replayed completed id rejected', $replayed === 1 ? 'yes' : 'no'],
                ['processed ids', number_format($queue->processed()->size())],
            ],
        );

        $output->measure('jobs', $jobs);
        $output->measure('completed', $completed);
        $output->measure('dead_lettered', $deadLettered);
        $output->measure('lease_attempts', $counters->get('leased'));
        $output->measure('failures', $counters->get('failed'));
        $output->measure('retries', $counters->get('retried'));
        $output->measure('total_retry_delay_ms', $totalDelay);
        $output->measure('replayed_completed_id_rejected', $replayed);

        $output->line();
        $output->line('A retry is a lease attempt, not a new enqueue.');
        $output->line('The delay doubles from 100 ms and is capped at 10,000 ms.');
        $output->line('A completed id is refused on replay until the processed-job TTL expires.');
        $output->line('A permanently failing job leaves the queue through the dead-letter list.');
    },
);
