<?php

declare(strict_types=1);

namespace SystemDesignLab\Shortener;

use InvalidArgumentException;

/**
 * A single-node monotonic counter.
 *
 * It never collides inside one process and produces short, ordered codes. It
 * also leaks creation order and cannot be shared by two writers without a
 * coordination step; those are the reasons a distributed service usually
 * chooses a time-ordered or random scheme instead.
 */
final class SequentialIdGenerator implements IdGenerator
{
    public function __construct(
        private int $nextId = 0,
    ) {
        if ($this->nextId < 0) {
            throw new InvalidArgumentException('The starting id cannot be negative');
        }
    }

    public function next(string $tenantId, string $longUrl, int $attempt = 0): int
    {
        return $this->nextId++;
    }
}
