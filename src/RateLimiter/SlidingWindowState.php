<?php

declare(strict_types=1);

namespace SystemDesignLab\RateLimiter;

/**
 * A fixed-size circular buffer of accepted timestamps.
 *
 * The log is exact, but its memory is `O(limit)` per key, not `O(1)`. The
 * buffer avoids array_shift() reindexing on every expiry; the head pointer
 * moves instead.
 */
final class SlidingWindowState
{
    /** @var array<int, int> */
    public array $timestamps;

    public int $head = 0;

    public int $count = 0;

    public function __construct(
        public readonly int $limit,
    ) {
        $this->timestamps = array_fill(0, $limit, 0);
    }
}
