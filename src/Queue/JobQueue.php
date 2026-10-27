<?php

declare(strict_types=1);

namespace SystemDesignLab\Queue;

use InvalidArgumentException;
use LogicException;
use SplPriorityQueue;
use SystemDesignLab\Instrumentation\Counters;

/**
 * A deterministic in-process job queue with delayed and ready heaps.
 *
 * A delayed job is not in the ready heap: a high-priority job that is not due
 * yet must not block a lower-priority job that is due now. `lease()` first
 * releases expired leases, then promotes due delayed jobs, then extracts the
 * highest-priority ready job. The two heaps and the lease map are the whole
 * state machine; the counters are the evidence that it behaved.
 *
 * Delivery is at-least-once. An expired lease makes the job available again;
 * a worker that returns after its lease expired is told that its result is
 * stale. The queue does not claim exactly-once.
 */
final class JobQueue
{
    /** @var SplPriorityQueue<array{int, int}, Job> */
    private readonly SplPriorityQueue $ready;

    /** @var SplPriorityQueue<array{int, int}, Job> */
    private readonly SplPriorityQueue $delayed;

    /** @var SplPriorityQueue<array{int, int}, Job> */
    private readonly SplPriorityQueue $arrivals;

    /** @var array<string, Lease> */
    private array $leases = [];

    /** @var array<string, true> */
    private array $active = [];

    /** @var list<Job> */
    private array $deadLetters = [];

    private readonly ProcessedJobStore $processed;

    private readonly Counters $counters;

    private int $sequence = 0;

    public function __construct(
        private readonly ExponentialBackoff $backoff,
        ?ProcessedJobStore $processed = null,
        ?Counters $counters = null,
    ) {
        $this->processed = $processed ?? new ProcessedJobStore(86_400_000);
        $this->counters = $counters ?? new Counters();
        $this->ready = new SplPriorityQueue();
        $this->ready->setExtractFlags(SplPriorityQueue::EXTR_DATA);
        $this->delayed = new SplPriorityQueue();
        $this->delayed->setExtractFlags(SplPriorityQueue::EXTR_DATA);
        $this->arrivals = new SplPriorityQueue();
        $this->arrivals->setExtractFlags(SplPriorityQueue::EXTR_DATA);
    }

    public function enqueue(Job $job, int $nowMs): bool
    {
        $this->assertTimestamp($nowMs);

        if (!$job->isPending()) {
            throw new InvalidArgumentException(sprintf('Job %s is already finished', $job->id));
        }

        if (isset($this->active[$job->id]) || $this->processed->isProcessed($job->id, $nowMs)) {
            $this->counters->add('duplicateEnqueues');

            return false;
        }

        $this->active[$job->id] = true;
        $this->arrivals->insert($job, [-$job->createdAtMs, -$this->nextSequence()]);
        $this->counters->add('enqueued');

        if ($job->availableAtMs() <= $nowMs) {
            $this->insertReady($job);
        } else {
            $this->insertDelayed($job);
        }

        return true;
    }

    public function lease(string $workerId, int $nowMs, int $visibilityTimeoutMs = 30_000): ?Lease
    {
        if (trim($workerId) === '') {
            throw new InvalidArgumentException('Worker id cannot be empty');
        }

        $this->assertTimestamp($nowMs);

        if ($visibilityTimeoutMs < 1) {
            throw new InvalidArgumentException('Visibility timeout must be at least 1 ms');
        }

        $this->releaseExpiredLeases($nowMs);
        $this->promoteDue($nowMs);

        if ($this->ready->isEmpty()) {
            return null;
        }

        $job = $this->ready->extract();

        if (!$job instanceof Job) {
            throw new LogicException('The ready heap returned a non-job value');
        }

        if (!$job->isPending() || $job->isLeased()) {
            throw new LogicException(sprintf('Job %s is not leaseable', $job->id));
        }

        $job->lease($workerId, $nowMs, $visibilityTimeoutMs);
        $lease = new Lease($job, $workerId, $nowMs + $visibilityTimeoutMs);
        $this->leases[$job->id] = $lease;
        $this->counters->add('leased');

        return $lease;
    }

    public function complete(Lease $lease, int $nowMs): bool
    {
        $this->assertTimestamp($nowMs);
        $current = $this->leases[$lease->job->id] ?? null;

        if ($current !== $lease || $lease->expiredAt($nowMs)) {
            $this->counters->add('staleCompletions');

            return false;
        }

        unset($this->leases[$lease->job->id], $this->active[$lease->job->id]);
        $lease->job->complete($nowMs);
        $this->processed->mark($lease->job->id, $nowMs);
        $this->counters->add('completed');

        return true;
    }

    public function fail(Lease $lease, int $nowMs, string $error = 'job failed'): FailureOutcome
    {
        $this->assertTimestamp($nowMs);
        $current = $this->leases[$lease->job->id] ?? null;

        if ($current !== $lease || $lease->expiredAt($nowMs)) {
            $this->counters->add('staleFailures');

            return FailureOutcome::stale();
        }

        unset($this->leases[$lease->job->id]);
        $this->counters->add('failed');
        $job = $lease->job;

        if ($job->attempts() >= $job->maxAttempts) {
            $job->deadLetter($nowMs, $error);
            unset($this->active[$job->id]);
            $this->deadLetters[] = $job;
            $this->counters->add('deadLettered');

            return FailureOutcome::deadLettered();
        }

        $delay = $this->backoff->delayMs($job->attempts());
        $job->scheduleRetry($nowMs, $delay, $error);

        if ($delay === 0) {
            $this->insertReady($job);
        } else {
            $this->insertDelayed($job);
        }

        $this->counters->add('retried');

        return FailureOutcome::retried($delay);
    }

    /**
     * Expires leases whose visibility timeout has passed.
     *
     * An expired lease is redelivery, not a reported failure, so the job is
     * put back into the ready heap immediately. It still consumes one attempt;
     * a job that expires on its last attempt is dead-lettered.
     */
    public function releaseExpiredLeases(int $nowMs): int
    {
        $this->assertTimestamp($nowMs);
        $expired = [];

        foreach ($this->leases as $lease) {
            if ($lease->expiresAtMs <= $nowMs) {
                $expired[] = $lease;
            }
        }

        foreach ($expired as $lease) {
            unset($this->leases[$lease->jobId()]);
            $this->counters->add('expiredLeases');
            $job = $lease->job;

            if ($job->attempts() >= $job->maxAttempts) {
                $job->deadLetter($nowMs, 'lease expired');
                unset($this->active[$job->id]);
                $this->deadLetters[] = $job;
                $this->counters->add('deadLettered');

                continue;
            }

            $job->scheduleRetry($nowMs, 0, 'lease expired');
            $this->insertReady($job);
            $this->counters->add('retried');
        }

        return count($expired);
    }

    public function promoteDue(int $nowMs): int
    {
        $this->assertTimestamp($nowMs);
        $promoted = 0;

        while (!$this->delayed->isEmpty()) {
            $job = $this->delayed->top();

            if (!$job instanceof Job) {
                throw new LogicException('The delayed heap returned a non-job value');
            }

            if ($job->availableAtMs() > $nowMs) {
                break;
            }

            $this->delayed->extract();
            $this->insertReady($job);
            ++$promoted;
        }

        if ($promoted > 0) {
            $this->counters->add('promoted', $promoted);
        }

        return $promoted;
    }

    public function tick(int $nowMs): void
    {
        $this->releaseExpiredLeases($nowMs);
        $this->promoteDue($nowMs);
    }

    public function pending(): int
    {
        return $this->ready->count() + $this->delayed->count();
    }

    public function readyDepth(): int
    {
        return $this->ready->count();
    }

    public function delayedDepth(): int
    {
        return $this->delayed->count();
    }

    public function leaseCount(): int
    {
        return count($this->leases);
    }

    public function activeCount(): int
    {
        return count($this->active);
    }

    /**
     * Creation timestamp of the oldest job that is still pending or leased.
     * Completed and dead-lettered arrivals are removed lazily.
     */
    public function oldestPendingAtMs(): ?int
    {
        while (!$this->arrivals->isEmpty()) {
            $job = $this->arrivals->top();

            if (!$job instanceof Job) {
                throw new LogicException('The arrival heap returned a non-job value');
            }

            if ($job->isPending() && isset($this->active[$job->id])) {
                return $job->createdAtMs;
            }

            $this->arrivals->extract();
        }

        return null;
    }

    /**
     * @return list<Job>
     */
    public function deadLetters(): array
    {
        return $this->deadLetters;
    }

    public function counters(): Counters
    {
        return $this->counters;
    }

    public function processed(): ProcessedJobStore
    {
        return $this->processed;
    }

    private function insertReady(Job $job): void
    {
        $this->ready->insert($job, [$job->priority, -$this->nextSequence()]);
    }

    private function insertDelayed(Job $job): void
    {
        $this->delayed->insert($job, [-$job->availableAtMs(), -$this->nextSequence()]);
    }

    private function nextSequence(): int
    {
        return ++$this->sequence;
    }

    private function assertTimestamp(int $nowMs): void
    {
        if ($nowMs < 0) {
            throw new InvalidArgumentException('Timestamp cannot be negative');
        }
    }
}
