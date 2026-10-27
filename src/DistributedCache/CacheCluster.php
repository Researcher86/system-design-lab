<?php

declare(strict_types=1);

namespace SystemDesignLab\DistributedCache;

use InvalidArgumentException;
use LogicException;
use SystemDesignLab\Instrumentation\Counters;

/**
 * A cluster of LRU nodes behind one placement strategy.
 *
 * Reads go to the primary first and then to replicas; a replica hit repairs
 * the primary when it is reachable. A failed node is skipped, and a read or
 * write may therefore use fewer copies than the replication factor. Failed
 * nodes lose their data by default, which is what a cache crash does; recovery
 * is empty and is repopulated by reads and writes.
 *
 * This is a cache, not a database: a miss is normal, and no replica is
 * guaranteed to have the key forever. The counters make the cost visible.
 */
final class CacheCluster
{
    /** @var array<string, LruStore> */
    private array $stores;

    /** @var array<string, true> */
    private array $down = [];

    private readonly Counters $counters;

    /**
     * @param array<string, LruStore> $stores
     */
    public function __construct(
        private readonly HashStrategy $placement,
        array $stores,
        private readonly int $replicationFactor = 1,
        ?Counters $counters = null,
    ) {
        if ($this->replicationFactor < 1) {
            throw new InvalidArgumentException('Replication factor must be at least 1');
        }

        if ($stores === []) {
            throw new InvalidArgumentException('A cache cluster needs at least one node');
        }

        $placementNodes = $this->placement->nodes();
        $storeNodes = array_keys($stores);
        sort($placementNodes);
        sort($storeNodes);

        if ($placementNodes !== $storeNodes) {
            throw new InvalidArgumentException('Placement and stores must contain the same nodes');
        }

        if ($this->replicationFactor > count($placementNodes)) {
            throw new InvalidArgumentException('Replication factor cannot exceed the node count');
        }

        $this->stores = $stores;
        $this->counters = $counters ?? new Counters();
    }

    public function get(string $key): CacheLookup
    {
        $this->counters->add('reads');
        $targets = $this->placement->locate($key, $this->replicationFactor);
        $primary = $targets[0] ?? null;

        foreach ($targets as $index => $node) {
            if (isset($this->down[$node])) {
                $this->counters->add('skippedDownNodes');

                continue;
            }

            $store = $this->stores[$node];

            if (!$store->has($key)) {
                continue;
            }

            $value = $store->get($key);

            if ($index === 0) {
                $this->counters->add('primaryHits');

                return CacheLookup::hit(CacheSource::Primary, $node, $value);
            }

            $this->counters->add('replicaHits');

            if ($primary !== null && !isset($this->down[$primary])) {
                $this->stores[$primary]->put($key, $value);
                $this->counters->add('readRepairs');
            }

            return CacheLookup::hit(CacheSource::Replica, $node, $value);
        }

        $this->counters->add('misses');

        return CacheLookup::miss();
    }

    public function put(string $key, mixed $value): void
    {
        if ($value === null) {
            throw new InvalidArgumentException('Null is not a cacheable value');
        }

        $this->counters->add('writes');
        $targets = $this->placement->locate($key, $this->replicationFactor);
        $primary = $targets[0] ?? null;
        $written = 0;
        $primaryWritten = false;

        foreach ($targets as $node) {
            if (isset($this->down[$node])) {
                continue;
            }

            $this->stores[$node]->put($key, $value);
            ++$written;

            if ($node === $primary) {
                $primaryWritten = true;
            }
        }

        if ($written === 0) {
            $this->counters->add('writeFailures');

            return;
        }

        if ($written > 1) {
            $this->counters->add('replicaWrites', $written - 1);
        }

        if (!$primaryWritten) {
            $this->counters->add('degradedWrites');
        }
    }

    /**
     * @return int The number of entries lost with the node.
     */
    public function failNode(string $node, bool $loseData = true): int
    {
        $this->assertNode($node);

        if (isset($this->down[$node])) {
            return 0;
        }

        $this->down[$node] = true;
        $this->counters->add('nodeFailures');
        $lost = 0;

        if ($loseData) {
            $lost = $this->stores[$node]->clear();
            $this->counters->add('lostEntries', $lost);
            $this->counters->add('dataLossFailures');
        }

        return $lost;
    }

    public function recoverNode(string $node): void
    {
        $this->assertNode($node);

        if (!isset($this->down[$node])) {
            return;
        }

        unset($this->down[$node]);
        $this->counters->add('nodeRecoveries');
    }

    public function isUp(string $node): bool
    {
        $this->assertNode($node);

        return !isset($this->down[$node]);
    }

    public function nodeStore(string $node): LruStore
    {
        $this->assertNode($node);

        return $this->stores[$node];
    }

    /**
     * @return list<string>
     */
    public function nodes(): array
    {
        return array_keys($this->stores);
    }

    public function replicationFactor(): int
    {
        return $this->replicationFactor;
    }

    public function hitRatio(): float
    {
        $reads = $this->counters->get('reads');

        if ($reads === 0) {
            return 0.0;
        }

        return ($this->counters->get('primaryHits') + $this->counters->get('replicaHits')) / $reads;
    }

    public function counters(): Counters
    {
        return $this->counters;
    }

    private function assertNode(string $node): void
    {
        if (!isset($this->stores[$node])) {
            throw new LogicException(sprintf('Unknown cache node: %s', $node));
        }
    }
}
