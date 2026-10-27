<?php

declare(strict_types=1);

namespace SystemDesignLab\Webhook;

use InvalidArgumentException;

/**
 * One event that may be delivered to many subscriptions.
 *
 * The payload is already serialized. A signature must cover the exact bytes
 * that are sent, so the lab does not re-encode JSON after signing; a real
 * producer would use one canonical serializer on both sides.
 */
final readonly class WebhookEvent
{
    public function __construct(
        public string $id,
        public string $tenantId,
        public string $type,
        public string $payload,
        public int $createdAtMs = 0,
    ) {
        if (trim($this->id) === '') {
            throw new InvalidArgumentException('Event id cannot be empty');
        }

        if (trim($this->tenantId) === '') {
            throw new InvalidArgumentException('Event tenant id cannot be empty');
        }

        if (trim($this->type) === '') {
            throw new InvalidArgumentException('Event type cannot be empty');
        }

        if ($this->createdAtMs < 0) {
            throw new InvalidArgumentException('Event creation timestamp cannot be negative');
        }
    }
}
