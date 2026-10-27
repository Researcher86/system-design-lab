<?php

declare(strict_types=1);

namespace SystemDesignLab\Queue;

use InvalidArgumentException;
use LogicException;

/**
 * One unit of work and its scheduling state.
 *
 * The queue owns the heaps; the job owns whether it is pending, leased,
 * completed or dead-lettered. `attempts` counts leases, not failures: a
 * worker that disappears still consumed one delivery. A completion or failure
 * from an expired lease must be rejected by the queue, not trusted.
 */
final class Job
{
    private int $availableAtMs;

    private int $attempts = 0;

    private ?string $leaseOwner = null;

    private ?int $leaseExpiresAtMs = null;

    private ?int $completedAtMs = null;

    private ?int $deadLetteredAtMs = null;

    private ?string $lastError = null;

    public function __construct(
        public readonly string $id,
        public readonly string $type,
        public readonly int $priority,
        public readonly int $createdAtMs,
        public readonly int $maxAttempts = 5,
        ?int $availableAtMs = null,
    ) {
        if (trim($this->id) === '') {
            throw new InvalidArgumentException('Job id cannot be empty');
        }

        if (trim($this->type) === '') {
            throw new InvalidArgumentException('Job type cannot be empty');
        }

        if ($this->createdAtMs < 0) {
            throw new InvalidArgumentException('Job creation timestamp cannot be negative');
        }

        if ($this->maxAttempts < 1) {
            throw new InvalidArgumentException('Job max attempts must be at least 1');
        }

        $availableAtMs ??= $this->createdAtMs;

        if ($availableAtMs < $this->createdAtMs) {
            throw new InvalidArgumentException('A job cannot become available before it was created');
        }

        $this->availableAtMs = $availableAtMs;
    }

    public function availableAtMs(): int
    {
        return $this->availableAtMs;
    }

    public function attempts(): int
    {
        return $this->attempts;
    }

    public function leaseOwner(): ?string
    {
        return $this->leaseOwner;
    }

    public function leaseExpiresAtMs(): ?int
    {
        return $this->leaseExpiresAtMs;
    }

    public function completedAtMs(): ?int
    {
        return $this->completedAtMs;
    }

    public function deadLetteredAtMs(): ?int
    {
        return $this->deadLetteredAtMs;
    }

    public function lastError(): ?string
    {
        return $this->lastError;
    }

    public function isPending(): bool
    {
        return $this->completedAtMs === null && $this->deadLetteredAtMs === null;
    }

    public function isLeased(): bool
    {
        return $this->leaseOwner !== null;
    }

    public function leaseExpiredAt(int $nowMs): bool
    {
        return $this->leaseOwner !== null
            && $this->leaseExpiresAtMs !== null
            && $this->leaseExpiresAtMs <= $nowMs;
    }

    public function lease(string $workerId, int $nowMs, int $visibilityTimeoutMs): void
    {
        if (trim($workerId) === '') {
            throw new InvalidArgumentException('Worker id cannot be empty');
        }

        if ($nowMs < 0) {
            throw new InvalidArgumentException('Timestamp cannot be negative');
        }

        if ($visibilityTimeoutMs < 1) {
            throw new InvalidArgumentException('Visibility timeout must be at least 1 ms');
        }

        if (!$this->isPending()) {
            throw new LogicException(sprintf('Job %s is no longer pending', $this->id));
        }

        if ($this->leaseOwner !== null) {
            throw new LogicException(sprintf('Job %s is already leased', $this->id));
        }

        if ($nowMs < $this->availableAtMs) {
            throw new LogicException(sprintf('Job %s is not available yet', $this->id));
        }

        ++$this->attempts;
        $this->leaseOwner = $workerId;
        $this->leaseExpiresAtMs = $nowMs + $visibilityTimeoutMs;
    }

    public function complete(int $nowMs): void
    {
        if ($nowMs < 0) {
            throw new InvalidArgumentException('Timestamp cannot be negative');
        }

        if ($this->leaseOwner === null || !$this->isPending()) {
            throw new LogicException(sprintf('Job %s is not leased', $this->id));
        }

        $this->completedAtMs = $nowMs;
        $this->leaseOwner = null;
        $this->leaseExpiresAtMs = null;
    }

    public function scheduleRetry(int $nowMs, int $delayMs, string $error): void
    {
        if ($nowMs < 0 || $delayMs < 0) {
            throw new InvalidArgumentException('Timestamp and retry delay cannot be negative');
        }

        if ($this->leaseOwner === null || !$this->isPending()) {
            throw new LogicException(sprintf('Job %s is not leased', $this->id));
        }

        if ($this->attempts >= $this->maxAttempts) {
            throw new LogicException(sprintf('Job %s has exhausted its attempts; dead-letter it', $this->id));
        }

        $this->availableAtMs = $nowMs + $delayMs;
        $this->leaseOwner = null;
        $this->leaseExpiresAtMs = null;
        $this->lastError = $error;
    }

    public function deadLetter(int $nowMs, string $error): void
    {
        if ($nowMs < 0) {
            throw new InvalidArgumentException('Timestamp cannot be negative');
        }

        if (!$this->isPending()) {
            throw new LogicException(sprintf('Job %s is no longer pending', $this->id));
        }

        $this->deadLetteredAtMs = $nowMs;
        $this->leaseOwner = null;
        $this->leaseExpiresAtMs = null;
        $this->lastError = $error;
    }
}
