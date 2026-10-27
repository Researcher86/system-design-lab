<?php

declare(strict_types=1);

namespace SystemDesignLab\Webhook;

use InvalidArgumentException;
use LogicException;

/**
 * One event-to-subscription delivery and its retry state.
 *
 * The id is also the idempotency key sent to the endpoint. It is stable across
 * retries, which is what lets a receiver suppress a second side effect when a
 * response is lost after the work was already done.
 */
final class WebhookDelivery
{
    private int $attempts = 0;

    private int $availableAtMs;

    private ?int $deliveredAtMs = null;

    private ?int $deadLetteredAtMs = null;

    private ?string $lastError = null;

    public function __construct(
        public readonly string $id,
        public readonly WebhookEvent $event,
        public readonly Subscription $subscription,
        public readonly int $createdAtMs,
    ) {
        if (trim($this->id) === '') {
            throw new InvalidArgumentException('Delivery id cannot be empty');
        }

        if ($this->createdAtMs < 0) {
            throw new InvalidArgumentException('Delivery creation timestamp cannot be negative');
        }

        if ($this->event->tenantId !== $this->subscription->tenantId) {
            throw new InvalidArgumentException('Event and subscription belong to different tenants');
        }

        $this->availableAtMs = $this->createdAtMs;
    }

    public function availableAtMs(): int
    {
        return $this->availableAtMs;
    }

    public function attempts(): int
    {
        return $this->attempts;
    }

    public function deliveredAtMs(): ?int
    {
        return $this->deliveredAtMs;
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
        return $this->deliveredAtMs === null && $this->deadLetteredAtMs === null;
    }

    public function idempotencyKey(): string
    {
        return $this->id;
    }

    public function recordAttempt(): int
    {
        if (!$this->isPending()) {
            throw new LogicException(sprintf('Delivery %s is no longer pending', $this->id));
        }

        return ++$this->attempts;
    }

    public function scheduleRetry(int $nowMs, int $delayMs): void
    {
        if ($nowMs < 0 || $delayMs < 0) {
            throw new InvalidArgumentException('Timestamp and retry delay cannot be negative');
        }

        if (!$this->isPending()) {
            throw new LogicException(sprintf('Delivery %s is no longer pending', $this->id));
        }

        $this->availableAtMs = $nowMs + $delayMs;
    }

    public function markDelivered(int $nowMs): void
    {
        if ($nowMs < 0) {
            throw new InvalidArgumentException('Timestamp cannot be negative');
        }

        if (!$this->isPending()) {
            throw new LogicException(sprintf('Delivery %s is no longer pending', $this->id));
        }

        $this->deliveredAtMs = $nowMs;
    }

    public function deadLetter(int $nowMs, string $error): void
    {
        if ($nowMs < 0) {
            throw new InvalidArgumentException('Timestamp cannot be negative');
        }

        if (!$this->isPending()) {
            throw new LogicException(sprintf('Delivery %s is no longer pending', $this->id));
        }

        $this->deadLetteredAtMs = $nowMs;
        $this->lastError = $error;
    }
}
