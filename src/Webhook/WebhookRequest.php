<?php

declare(strict_types=1);

namespace SystemDesignLab\Webhook;

use InvalidArgumentException;

/**
 * The bytes and headers one delivery attempt would put on the wire.
 *
 * The transport is intentionally narrow: it receives exactly what is signed,
 * the idempotency key and the attempt number. It cannot reach into the worker
 * or the subscription to discover a different secret.
 */
final readonly class WebhookRequest
{
    public function __construct(
        public string $url,
        public string $body,
        public string $signature,
        public string $idempotencyKey,
        public int $attempt,
        public int $timestampMs,
        public string $eventId,
        public string $subscriptionId,
    ) {
        if (filter_var($this->url, FILTER_VALIDATE_URL) === false) {
            throw new InvalidArgumentException(sprintf('Not a URL: %s', $this->url));
        }

        if ($this->idempotencyKey === '') {
            throw new InvalidArgumentException('Idempotency key cannot be empty');
        }

        if ($this->attempt < 1) {
            throw new InvalidArgumentException('Attempt number must be at least 1');
        }

        if ($this->timestampMs < 0) {
            throw new InvalidArgumentException('Request timestamp cannot be negative');
        }
    }
}
