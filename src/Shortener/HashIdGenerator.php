<?php

declare(strict_types=1);

namespace SystemDesignLab\Shortener;

use InvalidArgumentException;
use SystemDesignLab\Foundation\Hashers;

/**
 * An identifier derived from tenant and URL.
 *
 * The first attempt for a link is a pure function of tenant and URL, so the
 * same input maps to the same value without a lookup. Different URLs can still
 * collide after the hash is truncated to the code space; the attempt number is
 * mixed into the input so the shortener's retry loop can actually escape a
 * collision instead of hashing to the same taken code twenty times.
 */
final class HashIdGenerator implements IdGenerator
{
    public function __construct(
        private readonly int $space,
    ) {
        if ($this->space < 1) {
            throw new InvalidArgumentException('Identifier space must be at least 1');
        }
    }

    public function next(string $tenantId, string $longUrl, int $attempt = 0): int
    {
        return Hashers::hash64($tenantId . "\0" . $longUrl . "\0" . $attempt) % $this->space;
    }
}
