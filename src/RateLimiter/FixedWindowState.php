<?php

declare(strict_types=1);

namespace SystemDesignLab\RateLimiter;

final class FixedWindowState
{
    public function __construct(
        public int $windowStartMs,
        public int $count,
    ) {
    }
}
