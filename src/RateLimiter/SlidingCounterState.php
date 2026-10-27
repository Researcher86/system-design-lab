<?php

declare(strict_types=1);

namespace SystemDesignLab\RateLimiter;

final class SlidingCounterState
{
    public function __construct(
        public int $windowStartMs,
        public int $currentCount,
        public int $previousCount,
    ) {
    }
}
