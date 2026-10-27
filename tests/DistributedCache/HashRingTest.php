<?php

declare(strict_types=1);

namespace SystemDesignLab\Tests\DistributedCache;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use SystemDesignLab\DistributedCache\HashRing;

final class HashRingTest extends TestCase
{
    public function testVirtualNodeCountMustBePositive(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new HashRing(0);
    }

    public function testNodeCanBeAddedOnlyOnce(): void
    {
        $ring = new HashRing(10);
        $ring->addNode('cache-a');

        $this->expectException(InvalidArgumentException::class);
        $ring->addNode('cache-a');
    }

    public function testEmptyNodeNameIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new HashRing()->addNode('  ');
    }

    public function testUnknownNodeCannotBeRemoved(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new HashRing()->removeNode('missing');
    }

    public function testTokenCountTracksPhysicalNodes(): void
    {
        $ring = new HashRing(50);
        $ring->addNode('cache-a');
        $ring->addNode('cache-b');

        self::assertSame(100, $ring->tokenCount());
        self::assertSame(['cache-a', 'cache-b'], $ring->nodes());

        $ring->removeNode('cache-a');

        self::assertSame(50, $ring->tokenCount());
        self::assertSame(['cache-b'], $ring->nodes());
    }

    public function testLocateIsStableAndReturnsDistinctReplicas(): void
    {
        $ring = $this->ring(4, 100);
        $first = $ring->locate('link:tenant-a:abc123', 2);

        self::assertCount(2, $first);
        self::assertNotSame($first[0], $first[1]);
        self::assertSame($first, $ring->locate('link:tenant-a:abc123', 2));
    }

    public function testLocateReturnsEveryNodeWhenReplicasExceedMembership(): void
    {
        $ring = $this->ring(3, 10);
        $nodes = $ring->locate('key', 10);
        sort($nodes);

        self::assertCount(3, $nodes);
        self::assertSame(['cache-0', 'cache-1', 'cache-2'], $nodes);
    }

    public function testLocateOnAnEmptyRingReturnsNothing(): void
    {
        self::assertSame([], new HashRing()->locate('key'));
    }

    public function testReplicaCountMustBePositive(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->ring(2)->locate('key', 0);
    }

    public function testAddingANodeMovesAboutOneOverNKeys(): void
    {
        $ring = $this->ring(5, 150);
        $before = $this->placement($ring, 5_000);

        $ring->addNode('cache-5');
        $after = $this->placement($ring, 5_000);
        $moved = 0;

        foreach ($before as $key => $node) {
            if ($after[$key] !== $node) {
                ++$moved;
            }
        }

        $share = $moved / count($before);

        self::assertGreaterThan(0.10, $share);
        self::assertLessThan(0.25, $share);
    }

    public function testRemovingANodeOnlyMovesItsKeys(): void
    {
        $ring = $this->ring(4, 100);
        $before = $this->placement($ring, 2_000);

        $ring->removeNode('cache-2');
        $after = $this->placement($ring, 2_000);

        foreach ($before as $key => $node) {
            if ($node === 'cache-2') {
                self::assertNotSame('cache-2', $after[$key]);
            } else {
                self::assertSame($node, $after[$key]);
            }
        }
    }

    public function testVirtualNodesSpreadTheRing(): void
    {
        $ring = $this->ring(8, 150);
        $counts = [];

        for ($index = 0; $index < 10_000; ++$index) {
            $node = $ring->locate('key-' . $index)[0];
            $counts[$node] = ($counts[$node] ?? 0) + 1;
        }

        self::assertCount(8, $counts);
        self::assertLessThan(0.18, max($counts) / 10_000);
    }

    private function ring(int $nodes, int $virtualNodes = 150): HashRing
    {
        $ring = new HashRing($virtualNodes);

        for ($index = 0; $index < $nodes; ++$index) {
            $ring->addNode('cache-' . $index);
        }

        return $ring;
    }

    /**
     * @return array<string, string>
     */
    private function placement(HashRing $ring, int $keys): array
    {
        $placement = [];

        for ($index = 0; $index < $keys; ++$index) {
            $key = 'key-' . $index;
            $placement[$key] = $ring->locate($key)[0];
        }

        return $placement;
    }
}
