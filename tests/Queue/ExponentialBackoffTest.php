<?php

declare(strict_types=1);

namespace SystemDesignLab\Tests\Queue;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use SystemDesignLab\Foundation\DeterministicRandom;
use SystemDesignLab\Queue\ExponentialBackoff;

final class ExponentialBackoffTest extends TestCase
{
    public function testDelaysDoubleAndCap(): void
    {
        $backoff = new ExponentialBackoff(baseMs: 100, multiplier: 2.0, maxMs: 1_000, jitterMs: 0);

        self::assertSame(100, $backoff->delayMs(1));
        self::assertSame(200, $backoff->delayMs(2));
        self::assertSame(400, $backoff->delayMs(3));
        self::assertSame(800, $backoff->delayMs(4));
        self::assertSame(1_000, $backoff->delayMs(5));
        self::assertSame(1_000, $backoff->delayMs(20));
    }

    public function testJitterIsBoundedAndDeterministic(): void
    {
        $left = new ExponentialBackoff(100, 2.0, 1_000, 50, new DeterministicRandom('jitter'));
        $right = new ExponentialBackoff(100, 2.0, 1_000, 50, new DeterministicRandom('jitter'));

        for ($attempt = 1; $attempt <= 10; ++$attempt) {
            $leftDelay = $left->delayMs($attempt);
            $rightDelay = $right->delayMs($attempt);

            self::assertSame($leftDelay, $rightDelay);
            self::assertGreaterThanOrEqual(100, $leftDelay);
            self::assertLessThanOrEqual(1_050, $leftDelay);
        }
    }

    public function testAttemptMustBePositive(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new ExponentialBackoff()->delayMs(0);
    }

    public function testBaseMustBePositive(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new ExponentialBackoff(baseMs: 0);
    }

    public function testMultiplierMustBeAtLeastOne(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new ExponentialBackoff(multiplier: 0.5);
    }

    public function testCapCannotBeBelowTheBase(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new ExponentialBackoff(baseMs: 100, maxMs: 50);
    }

    public function testJitterCannotBeNegative(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new ExponentialBackoff(jitterMs: -1);
    }
}
