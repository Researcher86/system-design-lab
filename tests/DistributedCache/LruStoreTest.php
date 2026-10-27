<?php

declare(strict_types=1);

namespace SystemDesignLab\Tests\DistributedCache;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use SystemDesignLab\DistributedCache\LruStore;

final class LruStoreTest extends TestCase
{
    public function testCapacityMustBePositive(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new LruStore(0);
    }

    public function testMissReturnsNullAndIsCounted(): void
    {
        $store = new LruStore(2);

        self::assertNull($store->get('missing'));
        self::assertFalse($store->has('missing'));
        self::assertSame(1, $store->counters()->get('lruMisses'));
        self::assertSame(0, $store->size());
    }

    public function testPutAndGetAValue(): void
    {
        $store = new LruStore(2);
        $store->put('key', 'value');

        self::assertTrue($store->has('key'));
        self::assertSame('value', $store->get('key'));
        self::assertSame(1, $store->counters()->get('writes'));
        self::assertSame(1, $store->counters()->get('lruHits'));
    }

    public function testNullIsNotCacheable(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new LruStore(1)->put('key', null);
    }

    public function testLeastRecentlyUsedEntryIsEvicted(): void
    {
        $store = new LruStore(2);
        $store->put('a', 'A');
        $store->put('b', 'B');
        $store->get('a');
        $store->put('c', 'C');

        self::assertTrue($store->has('a'));
        self::assertFalse($store->has('b'));
        self::assertTrue($store->has('c'));
        self::assertSame(2, $store->size());
        self::assertSame(1, $store->counters()->get('evictions'));
    }

    public function testOverwritingAKeyDoesNotEvictAnother(): void
    {
        $store = new LruStore(2);
        $store->put('a', 'A');
        $store->put('b', 'B');
        $store->put('a', 'A2');

        self::assertSame('A2', $store->get('a'));
        self::assertSame('B', $store->get('b'));
        self::assertSame(0, $store->counters()->get('evictions'));
        self::assertSame(1, $store->counters()->get('replacements'));
    }

    public function testClearRemovesEntriesAndReturnsTheCount(): void
    {
        $store = new LruStore(3);
        $store->put('a', 1);
        $store->put('b', 2);

        self::assertSame(2, $store->clear());
        self::assertSame(0, $store->size());
        self::assertSame(2, $store->counters()->get('cleared'));
        self::assertSame(3, $store->capacity());
    }

    public function testCapacityOneKeepsOnlyTheNewestEntry(): void
    {
        $store = new LruStore(1);
        $store->put('a', 1);
        $store->put('b', 2);

        self::assertFalse($store->has('a'));
        self::assertSame(2, $store->get('b'));
        self::assertSame(1, $store->counters()->get('evictions'));
    }
}
