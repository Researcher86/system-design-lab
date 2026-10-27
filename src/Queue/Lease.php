<?php

declare(strict_types=1);

namespace SystemDesignLab\Queue;

/**
 * A worker's temporary claim on a job.
 *
 * The lease is a value object: the queue keeps the authoritative copy so a
 * stale completion can be told apart from a valid one. The worker cannot
 * extend the lease in this lab; a long task has to renew explicitly in a
 * production system.
 */
final readonly class Lease
{
    public function __construct(
        public Job $job,
        public string $workerId,
        public int $expiresAtMs,
    ) {
    }

    public function jobId(): string
    {
        return $this->job->id;
    }

    public function expiredAt(int $nowMs): bool
    {
        return $this->expiresAtMs <= $nowMs;
    }
}
