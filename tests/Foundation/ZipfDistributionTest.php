<?php

declare(strict_types=1);

namespace SystemDesignLab\Tests\Foundation;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use SystemDesignLab\Foundation\DeterministicRandom;
use SystemDesignLab\Foundation\ZipfDistribution;

final class ZipfDistributionTest extends TestCase
{
    public function testSamplesAreInRange(): void
    {
        $random = new DeterministicRandom('zipf');
        $zipf = new ZipfDistribution(100, 1.1);

        for ($i = 0; $i < 1_000; ++$i) {
            $rank = $zipf->sample($random);
            self::assertGreaterThanOrEqual(1, $rank);
            self::assertLessThanOrEqual(100, $rank);
        }
    }

    public function testSamplingIsReproducible(): void
    {
        $first = new ZipfDistribution(50, 1.2);
        $second = new ZipfDistribution(50, 1.2);
        $randomA = new DeterministicRandom('same');
        $randomB = new DeterministicRandom('same');

        for ($i = 0; $i < 200; ++$i) {
            self::assertSame($first->sample($randomA), $second->sample($randomB));
        }
    }

    public function testLowRanksAreMoreFrequent(): void
    {
        $random = new DeterministicRandom('skew');
        $zipf = new ZipfDistribution(100, 1.5);
        $firstTen = 0;

        for ($i = 0; $i < 10_000; ++$i) {
            if ($zipf->sample($random) <= 10) {
                ++$firstTen;
            }
        }

        self::assertGreaterThan(5_000, $firstTen);
    }

    public function testRejectsInvalidParameters(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new ZipfDistribution(0, 1.0);
    }

    public function testRejectsNonPositiveAlpha(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new ZipfDistribution(10, 0.0);
    }

    public function testRanksAccessor(): void
    {
        self::assertSame(10, new ZipfDistribution(10, 1.0)->ranks());
    }
}
