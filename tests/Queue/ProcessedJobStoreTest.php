<?php

declare(strict_types=1);

namespace SystemDesignLab\Tests\Queue;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use SystemDesignLab\Queue\ProcessedJobStore;

final class ProcessedJobStoreTest extends TestCase
{
    public function testMarkAndCheckInsideTheTtl(): void
    {
        $store = new ProcessedJobStore(ttlMs: 1_000, cleanupInterval: 0);

        $store->mark('job-1', 0);

        self::assertTrue($store->isProcessed('job-1', 500));
        self::assertFalse($store->isProcessed('job-1', 1_000));
        self::assertFalse($store->isProcessed('job-2', 500));
        self::assertSame(0, $store->size());
    }

    public function testPurgeRemovesExpiredIds(): void
    {
        $store = new ProcessedJobStore(ttlMs: 1_000, cleanupInterval: 0);
        $store->mark('a', 0);
        $store->mark('b', 500);

        self::assertSame(1, $store->purge(1_000));
        self::assertSame(1, $store->size());
    }

    public function testZeroTtlDisablesTheStore(): void
    {
        $store = new ProcessedJobStore(ttlMs: 0, cleanupInterval: 0);
        $store->mark('job-1', 0);

        self::assertFalse($store->isProcessed('job-1', 0));
        self::assertSame(0, $store->size());
    }

    public function testNegativeTtlIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new ProcessedJobStore(-1);
    }

    public function testEmptyJobIdIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new ProcessedJobStore(1_000)->mark('', 0);
    }
}
