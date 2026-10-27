<?php

declare(strict_types=1);

namespace SystemDesignLab\Shortener;

use InvalidArgumentException;
use SystemDesignLab\Foundation\DeterministicRandom;

/**
 * A random identifier drawn from the code space.
 *
 * There is no coordination and no obvious order, but two writers can draw the
 * same value. The shortener must check the repository and retry; the
 * `shorten:collisions` experiment shows how the birthday bound catches up
 * with a short code.
 */
final class RandomIdGenerator implements IdGenerator
{
    public function __construct(
        private readonly int $space,
        private readonly DeterministicRandom $random,
    ) {
        if ($this->space < 1) {
            throw new InvalidArgumentException('Identifier space must be at least 1');
        }
    }

    public function next(string $tenantId, string $longUrl, int $attempt = 0): int
    {
        return $this->random->intBetween(0, $this->space - 1);
    }
}
