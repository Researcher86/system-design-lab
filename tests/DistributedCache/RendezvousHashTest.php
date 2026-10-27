<?php

declare(strict_types=1);

namespace SystemDesignLab\Tests\DistributedCache;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use SystemDesignLab\DistributedCache\RendezvousHash;

final class RendezvousHashTest extends TestCase
{
    public function testNodeCanBeAddedOnlyOnce(): void
    {
        $hash = new RendezvousHash();
        $hash->addNode('cache-a');

        $this->expectException(InvalidArgumentException::class);
        $hash->addNode('cache-a');
    }

    public function testEmptyNodeNameIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new RendezvousHash()->addNode(' ');
    }

    public function testUnknownNodeCannotBeRemoved(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new RendezvousHash()->removeNode('missing');
    }

    public function testLocateIsStableAndReturnsDistinctReplicas(): void
    {
        $hash = $this->hash(4);
        $first = $hash->locate('link:tenant-a:abc123', 2);

        self::assertCount(2, $first);
        self::assertNotSame($first[0], $first[1]);
        self::assertSame($first, $hash->locate('link:tenant-a:abc123', 2));
    }

    public function testNodeOrderDoesNotChangeTheMapping(): void
    {
        $forward = $this->hash(4);
        $reverse = new RendezvousHash();

        for ($index = 3; $index >= 0; --$index) {
            $reverse->addNode('cache-' . $index);
        }

        for ($index = 0; $index < 500; ++$index) {
            self::assertSame(
                $forward->locate('key-' . $index)[0],
                $reverse->locate('key-' . $index)[0],
            );
        }
    }

    public function testLocateReturnsEveryNodeWhenReplicasExceedMembership(): void
    {
        $hash = $this->hash(3);
        $nodes = $hash->locate('key', 10);
        sort($nodes);

        self::assertCount(3, $nodes);
        self::assertSame(['cache-0', 'cache-1', 'cache-2'], $nodes);
    }

    public function testLocateOnAnEmptyMembershipReturnsNothing(): void
    {
        self::assertSame([], new RendezvousHash()->locate('key'));
    }

    public function testReplicaCountMustBePositive(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->hash(2)->locate('key', 0);
    }

    public function testAddingANodeMovesAboutOneOverNKeys(): void
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

        $share = $moved / count($before);

        self::assertGreaterThan(0.10, $share);
        self::assertLessThan(0.25, $share);
    }

    public function testRemovingANodeMovesOnlyItsKeys(): void
    {
        $hash = $this->hash(4);
        $before = $this->placement($hash, 2_000);

        $hash->removeNode('cache-2');
        $after = $this->placement($hash, 2_000);

        foreach ($before as $key => $node) {
            if ($node === 'cache-2') {
                self::assertNotSame('cache-2', $after[$key]);
            } else {
                self::assertSame($node, $after[$key]);
            }
        }
    }

    private function hash(int $nodes): RendezvousHash
    {
        $hash = new RendezvousHash();

        for ($index = 0; $index < $nodes; ++$index) {
            $hash->addNode('cache-' . $index);
        }

        return $hash;
    }

    /**
     * @return array<string, string>
     */
    private function placement(RendezvousHash $hash, int $keys): array
    {
        $placement = [];

        for ($index = 0; $index < $keys; ++$index) {
            $key = 'key-' . $index;
            $placement[$key] = $hash->locate($key)[0];
        }

        return $placement;
    }
}
