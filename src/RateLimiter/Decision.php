<?php

declare(strict_types=1);

namespace SystemDesignLab\RateLimiter;

/**
 * The answer to one admission attempt.
 *
 * `remaining` is the number of whole requests the caller may still make in the
 * current budget. `retryAfterMs` is zero when the request was allowed and a
 * positive hint when it was not; it is a hint, not a promise, because another
 * caller can consume the budget first.
 */
final readonly class Decision
{
    public function __construct(
        public bool $allowed,
        public int $remaining,
        public int $retryAfterMs,
        public int $limit,
    ) {
    }

    /**
     * @return array{allowed: bool, remaining: int, retry_after_ms: int, limit: int}
     */
    public function toArray(): array
    {
        return [
            'allowed' => $this->allowed,
            'remaining' => $this->remaining,
            'retry_after_ms' => $this->retryAfterMs,
            'limit' => $this->limit,
        ];
    }
}
