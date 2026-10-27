<?php

declare(strict_types=1);

namespace SystemDesignLab\Tests\Foundation;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use SystemDesignLab\Foundation\ByteFormatter;
use SystemDesignLab\Foundation\Hashers;
use SystemDesignLab\Foundation\SystemClock;
use SystemDesignLab\Foundation\VirtualClock;

final class FoundationHelpersTest extends TestCase
{
    public function testHashIsStableAndNonNegative(): void
    {
        self::assertSame(Hashers::hash64('abc'), Hashers::hash64('abc'));
        self::assertNotSame(Hashers::hash64('abc'), Hashers::hash64('abd'));
        self::assertGreaterThanOrEqual(0, Hashers::hash64('abc'));
    }

    public function testIndexIsBounded(): void
    {
        for ($i = 0; $i < 100; ++$i) {
            $index = Hashers::index('key-' . $i, 7);
            self::assertGreaterThanOrEqual(0, $index);
            self::assertLessThan(7, $index);
        }
    }

    public function testIndexRejectsZeroBuckets(): void
    {
        $this->expectException(InvalidArgumentException::class);
        Hashers::index('key', 0);
    }

    public function testVirtualClockMovesForward(): void
    {
        $clock = new VirtualClock(10);
        self::assertSame(10, $clock->nowMs());
        $clock->advance(5);
        self::assertSame(15, $clock->nowMs());
        $clock->set(100);
        self::assertSame(100, $clock->nowMs());
    }

    public function testVirtualClockRejectsBackwardsMovement(): void
    {
        $clock = new VirtualClock(10);

        $this->expectException(InvalidArgumentException::class);
        $clock->advance(-1);
    }

    public function testSystemClockReturnsMilliseconds(): void
    {
        $before = (int) floor(microtime(true) * 1000);
        $now = new SystemClock()->nowMs();
        $after = (int) floor(microtime(true) * 1000);

        self::assertGreaterThanOrEqual($before, $now);
        self::assertLessThanOrEqual($after, $now);
    }

    public function testByteFormatter(): void
    {
        self::assertSame('512 B', ByteFormatter::format(512));
        self::assertSame('1.0 KiB', ByteFormatter::format(1024));
        self::assertSame('1.5 MiB', ByteFormatter::format(1024 * 1024 + 512 * 1024));
        self::assertSame('10.0 B', ByteFormatter::perItem(100, 10));
        self::assertSame('n/a', ByteFormatter::perItem(100, 0));
    }
}
