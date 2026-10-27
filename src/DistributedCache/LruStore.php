<?php

declare(strict_types=1);

namespace SystemDesignLab\DistributedCache;

use InvalidArgumentException;
use SystemDesignLab\Instrumentation\Counters;

/**
 * One cache node: a bounded map with least-recently-used eviction.
 *
 * A hit reinserts the key at the end of the PHP array, which is how insertion
 * order is kept; the first key is then the least recently used. This is O(1)
 * expected per operation and uses one array entry per cached value. It is not
 * a distributed cache by itself: placement and replication live in
 * CacheCluster.
 *
 * Null is not a cacheable value, so get() can use null as an unambiguous miss.
 */
final class LruStore
{
    /** @var array<string, mixed> */
    private array $entries = [];

    private readonly Counters $counters;

    public function __construct(
        private readonly int $capacity,
        ?Counters $counters = null,
    ) {
        if ($this->capacity < 1) {
            throw new InvalidArgumentException('Cache capacity must be at least 1');
        }

        $this->counters = $counters ?? new Counters();
    }

    public function get(string $key): mixed
    {
        if (!array_key_exists($key, $this->entries)) {
            $this->counters->add('lruMisses');

            return null;
        }

        $value = $this->entries[$key];
        unset($this->entries[$key]);
        $this->entries[$key] = $value;
        $this->counters->add('lruHits');

        return $value;
    }

    public function put(string $key, mixed $value): void
    {
        if ($value === null) {
            throw new InvalidArgumentException('Null is not a cacheable value');
        }

        if (array_key_exists($key, $this->entries)) {
            unset($this->entries[$key]);
            $this->counters->add('replacements');
        }

        $this->entries[$key] = $value;
        $this->counters->add('writes');

        while (count($this->entries) > $this->capacity) {
            foreach ($this->entries as $oldest => $value) {
                unset($this->entries[$oldest]);
                $this->counters->add('evictions');

                break;
            }
        }
    }

    public function has(string $key): bool
    {
        return array_key_exists($key, $this->entries);
    }

    /**
     * @return int The number of entries that were removed.
     */
    public function clear(): int
    {
        $removed = count($this->entries);
        $this->entries = [];
        $this->counters->add('cleared', $removed);

        return $removed;
    }

    public function size(): int
    {
        return count($this->entries);
    }

    public function capacity(): int
    {
        return $this->capacity;
    }

    public function counters(): Counters
    {
        return $this->counters;
    }
}
