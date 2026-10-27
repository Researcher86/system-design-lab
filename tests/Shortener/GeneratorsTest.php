<?php

declare(strict_types=1);

namespace SystemDesignLab\Tests\Shortener;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use SystemDesignLab\Foundation\DeterministicRandom;
use SystemDesignLab\Shortener\HashIdGenerator;
use SystemDesignLab\Shortener\RandomIdGenerator;
use SystemDesignLab\Shortener\SequentialIdGenerator;

final class GeneratorsTest extends TestCase
{
    public function testSequentialGeneratorStartsAtZeroAndIgnoresTheLink(): void
    {
        $ids = new SequentialIdGenerator();

        self::assertSame(0, $ids->next('tenant-a', 'https://example.com/a'));
        self::assertSame(1, $ids->next('tenant-b', 'https://example.com/b'));
    }

    public function testSequentialGeneratorHonoursTheStartingValue(): void
    {
        $ids = new SequentialIdGenerator(41);

        self::assertSame(41, $ids->next('tenant-a', 'https://example.com/a'));
        self::assertSame(42, $ids->next('tenant-a', 'https://example.com/a'));
    }

    public function testSequentialGeneratorRejectsANegativeStart(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new SequentialIdGenerator(-1);
    }

    public function testRandomGeneratorIsDeterministicForOneSeed(): void
    {
        $left = new RandomIdGenerator(1_000, new DeterministicRandom('same-seed'));
        $right = new RandomIdGenerator(1_000, new DeterministicRandom('same-seed'));

        for ($i = 0; $i < 100; ++$i) {
            self::assertSame(
                $left->next('tenant-a', 'https://example.com/' . $i),
                $right->next('tenant-a', 'https://example.com/' . $i),
            );
        }
    }

    public function testRandomGeneratorStaysInsideTheSpace(): void
    {
        $ids = new RandomIdGenerator(7, new DeterministicRandom('space'));

        for ($i = 0; $i < 100; ++$i) {
            $value = $ids->next('tenant-a', 'https://example.com/' . $i);
            self::assertGreaterThanOrEqual(0, $value);
            self::assertLessThan(7, $value);
        }
    }

    public function testRandomGeneratorRejectsAnEmptySpace(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new RandomIdGenerator(0, new DeterministicRandom('space'));
    }

    public function testHashGeneratorIsDeterministicForTheSameAttempt(): void
    {
        $ids = new HashIdGenerator(1_000_000);

        self::assertSame(
            $ids->next('tenant-a', 'https://example.com/article', 0),
            $ids->next('tenant-a', 'https://example.com/article', 0),
        );
    }

    public function testHashGeneratorRehashesOnTheNextAttempt(): void
    {
        $ids = new HashIdGenerator(1_000_000_000);

        self::assertNotSame(
            $ids->next('tenant-a', 'https://example.com/article', 0),
            $ids->next('tenant-a', 'https://example.com/article', 1),
        );
    }

    public function testHashGeneratorSeparatesTenants(): void
    {
        $ids = new HashIdGenerator(1_000_000_000);

        self::assertNotSame(
            $ids->next('tenant-a', 'https://example.com/article'),
            $ids->next('tenant-b', 'https://example.com/article'),
        );
    }

    public function testHashGeneratorStaysInsideTheSpace(): void
    {
        $ids = new HashIdGenerator(97);

        for ($i = 0; $i < 100; ++$i) {
            $value = $ids->next('tenant-a', 'https://example.com/' . $i);
            self::assertGreaterThanOrEqual(0, $value);
            self::assertLessThan(97, $value);
        }
    }

    public function testHashGeneratorRejectsAnEmptySpace(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new HashIdGenerator(0);
    }
}
