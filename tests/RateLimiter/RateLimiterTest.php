<?php

declare(strict_types=1);

namespace SystemDesignLab\Tests\RateLimiter;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use SystemDesignLab\Foundation\DeterministicRandom;
use SystemDesignLab\RateLimiter\FixedWindowCounter;
use SystemDesignLab\RateLimiter\SlidingWindowCounter;
use SystemDesignLab\RateLimiter\SlidingWindowLog;
use SystemDesignLab\RateLimiter\TokenBucket;
use SystemDesignLab\RateLimiter\UnlimitedLimiter;

final class RateLimiterTest extends TestCase
{
    public function testTokenBucketAllowsBurstThenRefills(): void
    {
        $limiter = new TokenBucket(capacity: 3, refillPerSecond: 1.0);

        self::assertTrue($limiter->attempt('a', 0)->allowed);
        self::assertTrue($limiter->attempt('a', 0)->allowed);
        self::assertTrue($limiter->attempt('a', 0)->allowed);
        $denied = $limiter->attempt('a', 0);
        self::assertFalse($denied->allowed);
        self::assertSame(1000, $denied->retryAfterMs);

        self::assertTrue($limiter->attempt('a', 1000)->allowed);
        self::assertFalse($limiter->attempt('a', 1000)->allowed);
    }

    public function testTokenBucketCapsRefill(): void
    {
        $limiter = new TokenBucket(capacity: 2, refillPerSecond: 10.0);
        $limiter->attempt('a', 0);
        $limiter->attempt('a', 0);

        self::assertTrue($limiter->attempt('a', 1_000_000)->allowed);
        self::assertTrue($limiter->attempt('a', 1_000_000)->allowed);
        self::assertFalse($limiter->attempt('a', 1_000_000)->allowed);
    }

    public function testTokenBucketStateIsPerKey(): void
    {
        $limiter = new TokenBucket(capacity: 1, refillPerSecond: 0.0);

        self::assertTrue($limiter->attempt('a', 0)->allowed);
        self::assertTrue($limiter->attempt('b', 0)->allowed);
        self::assertFalse($limiter->attempt('a', 0)->allowed);
        self::assertSame(2, $limiter->trackedKeys());
    }

    public function testTokenBucketWithoutRefillNeverRecovers(): void
    {
        $limiter = new TokenBucket(capacity: 1, refillPerSecond: 0.0);
        $limiter->attempt('a', 0);
        $denied = $limiter->attempt('a', 1_000_000);

        self::assertFalse($denied->allowed);
        self::assertSame(PHP_INT_MAX, $denied->retryAfterMs);
    }

    public function testTokenBucketIgnoresClockRegression(): void
    {
        $limiter = new TokenBucket(capacity: 1, refillPerSecond: 1.0);
        $limiter->attempt('a', 1000);

        self::assertFalse($limiter->attempt('a', 500)->allowed);
    }

    public function testFixedWindowDoublesTheBudgetAtTheBoundary(): void
    {
        $limiter = new FixedWindowCounter(limit: 2, windowMs: 1000);

        self::assertTrue($limiter->attempt('a', 999)->allowed);
        self::assertTrue($limiter->attempt('a', 999)->allowed);
        self::assertFalse($limiter->attempt('a', 999)->allowed);

        self::assertTrue($limiter->attempt('a', 1000)->allowed);
        self::assertTrue($limiter->attempt('a', 1000)->allowed);
    }

    public function testFixedWindowDoesNotResetEarly(): void
    {
        $limiter = new FixedWindowCounter(limit: 1, windowMs: 1000);
        $limiter->attempt('a', 0);
        self::assertFalse($limiter->attempt('a', 999)->allowed);
        self::assertTrue($limiter->attempt('a', 1000)->allowed);
    }

    public function testSlidingLogIsExactAtTheBoundary(): void
    {
        $limiter = new SlidingWindowLog(limit: 2, windowMs: 1000);

        self::assertTrue($limiter->attempt('a', 999)->allowed);
        self::assertTrue($limiter->attempt('a', 999)->allowed);
        self::assertFalse($limiter->attempt('a', 1000)->allowed);

        self::assertTrue($limiter->attempt('a', 1999)->allowed);
        self::assertTrue($limiter->attempt('a', 1999)->allowed);
    }

    public function testSlidingLogMatchesAnIndependentOracle(): void
    {
        $random = new DeterministicRandom('differential');
        $limiter = new SlidingWindowLog(limit: 5, windowMs: 100);
        /** @var list<int> $oracle */
        $oracle = [];
        $now = 0;

        for ($i = 0; $i < 500; ++$i) {
            $now += $random->intBetween(0, 20);
            $oracle = array_values(array_filter($oracle, static fn (int $stamp): bool => $stamp > $now - 100));
            $expected = count($oracle) < 5;
            $actual = $limiter->attempt('a', $now)->allowed;

            self::assertSame($expected, $actual, sprintf('diverged at request %d, t=%d', $i, $now));

            if ($expected) {
                $oracle[] = $now;
            }
        }
    }

    public function testSlidingCounterDoesNotDoubleSpendOnAnExactBoundary(): void
    {
        $limiter = new SlidingWindowCounter(limit: 2, windowMs: 1000);

        self::assertTrue($limiter->attempt('a', 0)->allowed);
        self::assertTrue($limiter->attempt('a', 0)->allowed);
        self::assertFalse($limiter->attempt('a', 1000)->allowed);
    }

    public function testSlidingCounterKeepsApproximateState(): void
    {
        $limiter = new SlidingWindowCounter(limit: 10, windowMs: 1000);
        $limiter->attempt('a', 0);
        $limiter->attempt('a', 0);
        $limiter->attempt('a', 0);
        $limiter->attempt('a', 0);
        $limiter->attempt('a', 0);

        self::assertTrue($limiter->attempt('a', 500)->allowed);
        self::assertGreaterThanOrEqual(0, $limiter->attempt('a', 500)->remaining);
    }

    public function testUnlimitedLimiterAdmitsEverythingAndTracksNothing(): void
    {
        $limiter = new UnlimitedLimiter();

        self::assertTrue($limiter->attempt('a', 0)->allowed);
        self::assertTrue($limiter->attempt('a', 0)->allowed);
        self::assertSame(0, $limiter->trackedKeys());
        self::assertSame(2, $limiter->counters()->get('allowed'));
    }

    public function testResetRemovesOnlyOneKey(): void
    {
        $limiter = new TokenBucket(capacity: 1, refillPerSecond: 0.0);
        $limiter->attempt('a', 0);
        $limiter->attempt('b', 0);
        $limiter->reset('a');

        self::assertSame(1, $limiter->trackedKeys());
        self::assertTrue($limiter->attempt('a', 0)->allowed);
    }

    public function testCountersRecordAllowedAndDeniedAttempts(): void
    {
        $limiter = new FixedWindowCounter(limit: 1, windowMs: 1000);
        $limiter->attempt('a', 0);
        $limiter->attempt('a', 0);

        self::assertSame(2, $limiter->counters()->get('attempts'));
        self::assertSame(1, $limiter->counters()->get('allowed'));
        self::assertSame(1, $limiter->counters()->get('denied'));
    }

    public function testConstructorsRejectInvalidParameters(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new TokenBucket(0, 1.0);
    }

    public function testFixedWindowRejectsInvalidWindow(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new FixedWindowCounter(1, 0);
    }

    public function testSlidingLogRejectsInvalidLimit(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new SlidingWindowLog(0, 1000);
    }

    public function testSlidingCounterRejectsInvalidLimit(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new SlidingWindowCounter(0, 1000);
    }
}
