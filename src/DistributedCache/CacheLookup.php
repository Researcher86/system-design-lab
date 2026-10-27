<?php

declare(strict_types=1);

namespace SystemDesignLab\DistributedCache;

/**
 * The outcome of one cluster read.
 *
 * `found` distinguishes a hit from a miss even though a cache value is never
 * null; the source says whether the primary or a replica served it. A replica
 * hit may also have repaired the primary, which the cluster counts separately.
 */
final readonly class CacheLookup
{
    private function __construct(
        public bool $found,
        public mixed $value,
        public ?string $node,
        public CacheSource $source,
    ) {
    }

    public static function hit(CacheSource $source, string $node, mixed $value): self
    {
        return new self(true, $value, $node, $source);
    }

    public static function miss(): self
    {
        return new self(false, null, null, CacheSource::Miss);
    }
}
