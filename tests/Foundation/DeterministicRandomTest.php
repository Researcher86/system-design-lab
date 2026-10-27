<?php

declare(strict_types=1);

namespace SystemDesignLab\Tests\Foundation;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use SystemDesignLab\Foundation\DeterministicRandom;

final class DeterministicRandomTest extends TestCase
{
    public function testSameSeedProducesSameSequence(): void
    {
        $first = new DeterministicRandom('book');
        $second = new DeterministicRandom('book');

        $left = [];
        $right = [];

        for ($i = 0; $i < 100; ++$i) {
            $left[] = $first->nextInt();
            $right[] = $second->nextInt();
        }

        self::assertSame($left, $right);
    }

    public function testDifferentSeedsDiverge(): void
    {
        $first = new DeterministicRandom('a');
        $second = new DeterministicRandom('b');

        self::assertNotSame($first->nextInt(), $second->nextInt());
    }

    public function testRangeIsInclusive(): void
    {
        $random = new DeterministicRandom('range');
        $seen = [];

        for ($i = 0; $i < 1_000; ++$i) {
            $value = $random->intBetween(3, 5);
            self::assertGreaterThanOrEqual(3, $value);
            self::assertLessThanOrEqual(5, $value);
            $seen[$value] = true;
        }

        $values = array_keys($seen);
        sort($values);
        self::assertSame([3, 4, 5], $values);
    }

    public function testSingleValueRange(): void
    {
        $random = new DeterministicRandom('single');

        self::assertSame(7, $random->intBetween(7, 7));
    }

    public function testFloatsAreInUnitInterval(): void
    {
        $random = new DeterministicRandom('float');

        for ($i = 0; $i < 1_000; ++$i) {
            $value = $random->nextFloat();
            self::assertGreaterThanOrEqual(0.0, $value);
            self::assertLessThan(1.0, $value);
        }
    }

    public function testChanceAndPickAndShuffle(): void
    {
        $random = new DeterministicRandom('helpers');

        self::assertTrue($random->chance(1.0));
        self::assertFalse($random->chance(0.0));
        self::assertContains($random->pick(['a', 'b', 'c']), ['a', 'b', 'c']);

        $shuffled = $random->shuffle([1, 2, 3, 4, 5]);
        sort($shuffled);
        self::assertSame([1, 2, 3, 4, 5], $shuffled);
    }

    public function testRejectsInvalidRangesAndProbability(): void
    {
        $random = new DeterministicRandom('invalid');

        $this->expectException(InvalidArgumentException::class);
        $random->intBetween(5, 4);
    }

    public function testRejectsInvalidProbability(): void
    {
        $random = new DeterministicRandom('invalid');

        $this->expectException(InvalidArgumentException::class);
        $random->chance(1.5);
    }

    public function testRejectsEmptyPick(): void
    {
        $random = new DeterministicRandom('invalid');

        $this->expectException(InvalidArgumentException::class);
        $random->pick([]);
    }
}
