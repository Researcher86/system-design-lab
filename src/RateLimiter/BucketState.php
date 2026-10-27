<?php

declare(strict_types=1);

namespace SystemDesignLab\RateLimiter;

/**
 * Per-key state for a token bucket.
 *
 * Tokens are stored in integer nano-token units. Floats would make a long
 * simulation depend on rounding; integers make the refill and the boundary
 * decisions reproducible.
 */
final class BucketState
{
    public function __construct(
        public int $units,
        public int $lastRefillMs,
    ) {
    }
}
