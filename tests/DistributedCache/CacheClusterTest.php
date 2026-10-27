<?php

declare(strict_types=1);

namespace SystemDesignLab\Tests\DistributedCache;

use InvalidArgumentException;
use LogicException;
use PHPUnit\Framework\TestCase;
use SystemDesignLab\DistributedCache\CacheCluster;
use SystemDesignLab\DistributedCache\CacheSource;
use SystemDesignLab\DistributedCache\HashRing;
use SystemDesignLab\DistributedCache\HashStrategy;
use SystemDesignLab\DistributedCache\LruStore;
use SystemDesignLab\DistributedCache\ModuloHash;

final class CacheClusterTest extends TestCase
{
    public function testPrimaryHitIsServedByTheLocatedNode(): void
    {
        [$cluster, $placement] = $this->cluster(4, 1, 10);
        $key = 'link:key';
        $node = $placement->locate($key)[0];
        $cluster->put($key, 'value');

        $lookup = $cluster->get($key);

        self::assertTrue($lookup->found);
        self::assertSame('value', $lookup->value);
        self::assertSame($node, $lookup->node);
        self::assertSame(CacheSource::Primary, $lookup->source);
        self::assertSame(1, $cluster->counters()->get('primaryHits'));
        self::assertSame(0, $cluster->counters()->get('misses'));
    }

    public function testMissReturnsAnEmptyLookup(): void
    {
        [$cluster] = $this->cluster();

        $lookup = $cluster->get('missing');

        self::assertFalse($lookup->found);
        self::assertNull($lookup->value);
        self::assertNull($lookup->node);
        self::assertSame(CacheSource::Miss, $lookup->source);
        self::assertSame(1, $cluster->counters()->get('misses'));
        self::assertSame(0.0, $cluster->hitRatio());
    }

    public function testReplicaServesAfterThePrimaryFails(): void
    {
        [$cluster, $placement] = $this->cluster(4, 2, 10);
        $key = 'link:key';
        $targets = $placement->locate($key, 2);
        self::assertCount(2, $targets);
        $cluster->put($key, 'value');

        $lost = $cluster->failNode($targets[0]);

        self::assertSame(1, $lost);
        self::assertFalse($cluster->isUp($targets[0]));

        $lookup = $cluster->get($key);

        self::assertTrue($lookup->found);
        self::assertSame('value', $lookup->value);
        self::assertSame($targets[1], $lookup->node);
        self::assertSame(CacheSource::Replica, $lookup->source);
        self::assertSame(1, $cluster->counters()->get('replicaHits'));
        self::assertSame(1, $cluster->counters()->get('skippedDownNodes'));
        self::assertSame(0, $cluster->counters()->get('readRepairs'));
    }

    public function testReplicaHitRepairsThePrimaryAfterRecovery(): void
    {
        [$cluster, $placement] = $this->cluster(4, 2, 10);
        $key = 'link:key';
        $targets = $placement->locate($key, 2);
        $cluster->put($key, 'value');
        $cluster->failNode($targets[0]);
        self::assertSame(CacheSource::Replica, $cluster->get($key)->source);

        $cluster->recoverNode($targets[0]);
        $lookup = $cluster->get($key);

        self::assertTrue($lookup->found);
        self::assertSame($targets[1], $lookup->node);
        self::assertSame(CacheSource::Replica, $lookup->source);
        self::assertSame(1, $cluster->counters()->get('readRepairs'));

        $repaired = $cluster->get($key);

        self::assertSame($targets[0], $repaired->node);
        self::assertSame(CacheSource::Primary, $repaired->source);
    }

    public function testReplicationFactorOneMissesWhileThePrimaryIsDown(): void
    {
        [$cluster, $placement] = $this->cluster(4, 1, 10);
        $key = 'link:key';
        $node = $placement->locate($key)[0];
        $cluster->put($key, 'value');
        $cluster->failNode($node);

        self::assertFalse($cluster->get($key)->found);
        self::assertSame(1, $cluster->counters()->get('misses'));

        $cluster->recoverNode($node);

        self::assertFalse($cluster->get($key)->found);

        $cluster->put($key, 'value');

        self::assertTrue($cluster->get($key)->found);
    }

    public function testWritesDuringFailureUseTheAvailableReplica(): void
    {
        [$cluster, $placement] = $this->cluster(4, 2, 10);
        $key = 'link:key';
        $targets = $placement->locate($key, 2);
        $cluster->failNode($targets[0]);

        $cluster->put($key, 'value');

        self::assertSame(1, $cluster->counters()->get('degradedWrites'));
        self::assertSame(1, $cluster->counters()->get('writes'));
        self::assertSame(0, $cluster->counters()->get('replicaWrites'));

        $lookup = $cluster->get($key);
        self::assertTrue($lookup->found);
        self::assertSame($targets[1], $lookup->node);

        $cluster->recoverNode($targets[0]);
        $cluster->get($key);

        self::assertSame(1, $cluster->counters()->get('readRepairs'));
    }

    public function testAFailureWithoutDataLossKeepsTheEntries(): void
    {
        [$cluster, $placement] = $this->cluster(3, 1, 10);
        $key = 'link:key';
        $node = $placement->locate($key)[0];
        $cluster->put($key, 'value');

        self::assertSame(0, $cluster->failNode($node, loseData: false));
        $cluster->recoverNode($node);

        $lookup = $cluster->get($key);

        self::assertSame($node, $lookup->node);
        self::assertSame(CacheSource::Primary, $lookup->source);
    }

    public function testPutFailsWhenEveryTargetIsDown(): void
    {
        [$cluster] = $this->cluster(2, 1, 10);
        $cluster->failNode('cache-0');
        $cluster->failNode('cache-1');

        $cluster->put('key', 'value');

        self::assertSame(1, $cluster->counters()->get('writeFailures'));
        self::assertFalse($cluster->get('key')->found);
    }

    public function testUnknownNodeCannotBeFailedOrRead(): void
    {
        [$cluster] = $this->cluster();

        $this->expectException(LogicException::class);
        $cluster->failNode('missing');
    }

    public function testReplicationFactorCannotExceedTheNodeCount(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->cluster(2, 3);
    }

    public function testReplicationFactorMustBePositive(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->cluster(2, 0);
    }

    public function testEmptyClusterIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new CacheCluster(new ModuloHash(), [], 1);
    }

    public function testStoresMustMatchThePlacementNodes(): void
    {
        $placement = new ModuloHash();
        $placement->addNode('cache-a');
        $placement->addNode('cache-b');

        $this->expectException(InvalidArgumentException::class);
        new CacheCluster($placement, ['cache-a' => new LruStore(10)], 1);
    }

    public function testModuloPlacementWorksWithTheSameClusterContract(): void
    {
        $placement = new ModuloHash();
        $stores = [];

        for ($index = 0; $index < 4; ++$index) {
            $node = 'cache-' . $index;
            $placement->addNode($node);
            $stores[$node] = new LruStore(10);
        }

        $cluster = new CacheCluster($placement, $stores, 2);
        $cluster->put('key', 'value');

        self::assertTrue($cluster->get('key')->found);
        self::assertCount(2, $placement->locate('key', 2));
    }

    public function testHitRatioCountsPrimaryAndReplicaHits(): void
    {
        [$cluster, $placement] = $this->cluster(4, 2, 10);
        $key = 'link:key';
        $targets = $placement->locate($key, 2);
        $cluster->put($key, 'value');
        $cluster->get($key);
        $cluster->failNode($targets[0]);
        $cluster->get($key);
        $cluster->get('missing');

        self::assertSame(3, $cluster->counters()->get('reads'));
        self::assertSame(2, $cluster->counters()->get('primaryHits') + $cluster->counters()->get('replicaHits'));
        self::assertEqualsWithDelta(2 / 3, $cluster->hitRatio(), 1e-12);
    }

    /**
     * @return array{CacheCluster, HashStrategy}
     */
    private function cluster(int $nodes = 3, int $replicationFactor = 1, int $capacity = 10): array
    {
        $placement = new HashRing(50);
        $stores = [];

        for ($index = 0; $index < $nodes; ++$index) {
            $node = 'cache-' . $index;
            $placement->addNode($node);
            $stores[$node] = new LruStore($capacity);
        }

        return [new CacheCluster($placement, $stores, $replicationFactor), $placement];
    }
}
