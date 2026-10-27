<?php

declare(strict_types=1);

namespace SystemDesignLab\Notification;

use InvalidArgumentException;

/**
 * One event that may become one message per enabled channel.
 *
 * `eventKey` is the idempotency key: the same event delivered twice is still
 * one event. `urgent` bypasses quiet hours and digest batching, but not
 * deduplication; urgency is not a permission to send the same message twice.
 */
final readonly class Notification
{
    public function __construct(
        public string $eventKey,
        public string $tenantId,
        public string $userId,
        public string $title,
        public string $body,
        public int $createdAtMs,
        public bool $urgent = false,
    ) {
        if (trim($this->eventKey) === '') {
            throw new InvalidArgumentException('Notification event key cannot be empty');
        }

        if (trim($this->tenantId) === '') {
            throw new InvalidArgumentException('Notification tenant id cannot be empty');
        }

        if (trim($this->userId) === '') {
            throw new InvalidArgumentException('Notification user id cannot be empty');
        }

        if ($this->createdAtMs < 0) {
            throw new InvalidArgumentException('Notification timestamp cannot be negative');
        }
    }
}
