<?php

declare(strict_types=1);

namespace SystemDesignLab\Queue;

use InvalidArgumentException;

/**
 * Completed job ids inside a TTL.
 *
 * This is the queue-side half of idempotency: a producer that replays a
 * completed job id is refused instead of starting the work again. It does
 * not make side effects exactly-once; a worker that completed after its lease
 * expired can still have performed the side effect. The webhook and
 * notification consumers carry their own idempotency keys for that reason.
 */
final class ProcessedJobStore
{
    /** @var array<string, int> job id => expiry timestamp */
    private array $expiresAt = [];

    private int $marks = 0;

    public function __construct(
        private readonly int $ttlMs,
        private readonly int $cleanupInterval = 1_024,
    ) {
        if ($this->ttlMs < 0) {
            throw new InvalidArgumentException('Processed-job TTL cannot be negative');
        }

        if ($this->cleanupInterval < 0) {
            throw new InvalidArgumentException('Cleanup interval cannot be negative');
        }
    }

    public function mark(string $jobId, int $nowMs): void
    {
        if (trim($jobId) === '') {
            throw new InvalidArgumentException('Job id cannot be empty');
        }

        if ($nowMs < 0) {
            throw new InvalidArgumentException('Timestamp cannot be negative');
        }

        if ($this->ttlMs === 0) {
            return;
        }

        $this->expiresAt[$jobId] = $nowMs + $this->ttlMs;
        ++$this->marks;

        if ($this->cleanupInterval > 0 && $this->marks % $this->cleanupInterval === 0) {
            $this->purge($nowMs);
        }
    }

    public function isProcessed(string $jobId, int $nowMs): bool
    {
        if ($this->ttlMs === 0) {
            return false;
        }

        $expiresAt = $this->expiresAt[$jobId] ?? null;

        if ($expiresAt === null) {
            return false;
        }

        if ($expiresAt > $nowMs) {
            return true;
        }

        unset($this->expiresAt[$jobId]);

        return false;
    }

    public function purge(int $nowMs): int
    {
        $removed = 0;

        foreach ($this->expiresAt as $jobId => $expiresAt) {
            if ($expiresAt <= $nowMs) {
                unset($this->expiresAt[$jobId]);
                ++$removed;
            }
        }

        return $removed;
    }

    public function size(): int
    {
        return count($this->expiresAt);
    }
}
