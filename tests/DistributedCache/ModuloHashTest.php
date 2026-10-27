<?php

declare(strict_types=1);

namespace SystemDesignLab\Tests\DistributedCache;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use SystemDesignLab\DistributedCache\ModuloHash;

final class ModuloHashTest extends TestCase
{
    public function testLocateIsStableAndReturnsDistinctReplicas(): void
    {
        $hash = $this->hash(4);
        $first = $hash->locate('link:tenant-a:abc123', 2);

        self::assertCount(2, $first);
        self::assertNotSame($first[0], $first[1]);
        self::assertSame($first, $hash->locate('link:tenant-a:abc123', 2));
    }

    public function testLocateReturnsEveryNodeWhenReplicasExceedMembership(): void
    {
        $hash = $this->hash(3);
        $nodes = $hash->locate('key', 10);
        sort($nodes);

        self::assertSame(['cache-0', 'cache-1', 'cache-2'], $nodes);
    }

    public function testLocateOnAnEmptyMembershipReturnsNothing(): void
    {
        self::assertSame([], new ModuloHash()->locate('key'));
    }

    public function testReplicaCountMustBePositive(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->hash(2)->locate('key', 0);
    }

    public function testNodeCanBeAddedOnlyOnce(): void
    {
        $hash = new ModuloHash();
        $hash->addNode('cache-a');

        $this->expectException(InvalidArgumentException::class);
        $hash->addNode('cache-a');
    }

    public function testUnknownNodeCannotBeRemoved(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new ModuloHash()->removeNode('missing');
    }

    public function testAddingANodeRemapsMostKeys(): void
    {
        $hash = $this->hash(5);
        $before = $this->placement($hash, 5_000);

        $hash->addNode('cache-5');
        $after = $this->placement($hash, 5_000);
        $moved = 0;

        foreach ($before as $key => $node) {
            if ($after[$key] !== $node) {
                ++$moved;
            }
        }

        self::assertGreaterThan(0.75, $moved / count($before));
    }

    public function testRemovingANodeChangesTheModulus(): void
    {
        $hash = $this->hash(4);
        $before = $this->placement($hash, 2_000);

        $hash->removeNode('cache-2');
        $after = $this->placement($hash, 2_000);
        $moved = 0;

        foreach ($before as $key => $node) {
            if ($after[$key] !== $node) {
                ++$moved;
            }
        }

        self::assertGreaterThan(0.5, $moved / count($before));
    }

    private function hash(int $nodes): ModuloHash
    {
        $hash = new ModuloHash();

        for ($index = 0; $index < $nodes; ++$index) {
            $hash->addNode('cache-' . $index);
        }

        return $hash;
    }

    /**
     * @return array<string, string>
     */
    private function placement(ModuloHash $hash, int $keys): array
    {
        $placement = [];

        for ($index = 0; $index < $keys; ++$index) {
            $key = 'key-' . $index;
            $placement[$key] = $hash->locate($key)[0];
        }

        return $placement;
    }
}
