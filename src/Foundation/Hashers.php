<?php

declare(strict_types=1);

namespace SystemDesignLab\Foundation;

/**
 * Stable 64-bit-ish hashes for routing, sharding and deterministic jitter.
 *
 * xxh128 is fast and available in the PHP builds used by this lab. Only the
 * first 15 hex digits are kept so the value fits in a signed 64-bit integer;
 * that is enough for a simulation and for a ring with thousands of tokens.
 */
final class Hashers
{
    public static function hash64(string $value): int
    {
        return (int) hexdec(substr(hash('xxh128', $value), 0, 15));
    }

    public static function index(string $value, int $buckets): int
    {
        if ($buckets < 1) {
            throw new \InvalidArgumentException('Bucket count must be at least 1');
        }

        return self::hash64($value) % $buckets;
    }
}
