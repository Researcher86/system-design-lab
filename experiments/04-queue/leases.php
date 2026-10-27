<?php

declare(strict_types=1);

use SystemDesignLab\Experiment\Experiment;
use SystemDesignLab\Experiment\Options;
use SystemDesignLab\Experiment\Output;
use SystemDesignLab\Queue\ExponentialBackoff;
use SystemDesignLab\Queue\Job;
use SystemDesignLab\Queue\JobQueue;
use SystemDesignLab\Queue\Lease;
use SystemDesignLab\Queue\ProcessedJobStore;

/**
 * What happens when workers disappear instead of reporting a failure?
 *
 * A crashed worker never calls `complete()` or `fail()`. The lease expires,
 * the queue makes the job visible again, and another worker can take it. The
 * attempt was already consumed. Every `fail-every`-th lease is a crash; zero
 * disables crashes.
 */
return new Experiment(
    name: 'queue:leases',
    description: 'visibility timeouts, crashed workers and redelivery under a lease budget',
    supports: ['jobs', 'workers', 'lease-ms', 'max-attempts', 'fail-every'],
    run: static function (Options $options, Output $output): void {
        $jobs = $options->int('jobs', 1_000);
        $workers = $options->int('workers', 4);
        $leaseMs = $options->int('lease-ms', 500);
        $maxAttempts = $options->int('max-attempts', 3);
        $crashEvery = $options->int('fail-every', 3);
        $queue = new JobQueue(
            new ExponentialBackoff(baseMs: 100, multiplier: 2.0, maxMs: 10_000, jitterMs: 0),
            new ProcessedJobStore(ttlMs: 86_400_000, cleanupInterval: 0),
        );

        for ($i = 0; $i < $jobs; ++$i) {
            $queue->enqueue(new Job(
                id: 'job-' . $i,
                type: 'lease-demo',
                priority: 0,
                createdAtMs: 0,
                maxAttempts: $maxAttempts,
            ), 0);
        }

        $output->heading(
            'Queue leases',
            sprintf(
                '%s jobs, %d workers, %d ms visibility timeout, crash every %s lease(s).',
                number_format($jobs),
                $workers,
                $leaseMs,
                $crashEvery === 0 ? 'no' : (string) $crashEvery,
            ),
        );
        $output->line();

        $step = max(1, intdiv($leaseMs, 4));
        $limit = $jobs * $maxAttempts * ($leaseMs + $step) + 10_000;
        /** @var array<int, Lease> $workerLeases */
        $workerLeases = [];
        $now = 0;
        $leases = 0;
        $peak = 0;

        while ($queue->activeCount() > 0 && $now <= $limit) {
            $queue->tick($now);

            foreach ($workerLeases as $worker => $workerLease) {
                if ($workerLease->expiredAt($now)) {
                    unset($workerLeases[$worker]);
                }
            }

            for ($worker = 0; $worker < $workers; ++$worker) {
                if (isset($workerLeases[$worker])) {
                    continue;
                }

                $lease = $queue->lease('worker-' . $worker, $now, $leaseMs);

                if ($lease === null) {
                    break;
                }

                ++$leases;
                $peak = max($peak, $queue->leaseCount());

                if ($crashEvery > 0 && $leases % $crashEvery === 0) {
                    $workerLeases[$worker] = $lease;

                    continue;
                }

                $queue->complete($lease, $now);
            }

            $now += $step;
        }

        $counters = $queue->counters();
        $output->table(
            ['metric', 'value'],
            [
                ['jobs', number_format($jobs)],
                ['completed', number_format($counters->get('completed'))],
                ['dead-lettered', number_format($counters->get('deadLettered'))],
                ['lease attempts', number_format($counters->get('leased'))],
                ['expired leases', number_format($counters->get('expiredLeases'))],
                ['redeliveries', number_format($counters->get('retried'))],
                ['peak concurrent leases', $peak],
                ['simulated time', number_format($now) . ' ms'],
                ['still active', number_format($queue->activeCount())],
            ],
        );

        $output->measure('jobs', $jobs);
        $output->measure('completed', $counters->get('completed'));
        $output->measure('dead_lettered', $counters->get('deadLettered'));
        $output->measure('lease_attempts', $counters->get('leased'));
        $output->measure('expired_leases', $counters->get('expiredLeases'));
        $output->measure('redeliveries', $counters->get('retried'));
        $output->measure('peak_concurrent_leases', $peak);
        $output->measure('simulated_time_ms', $now);
        $output->measure('still_active', $queue->activeCount());

        $output->line();
        $output->line('A crash is invisible to the queue until the visibility timeout expires.');
        $output->line('The expired lease consumes an attempt, so a crash is not a free retry.');
        $output->line('A job that crashes on its last attempt is dead-lettered on expiry.');
        $output->line('The lease budget bounds concurrency, not correctness.');
    },
);
