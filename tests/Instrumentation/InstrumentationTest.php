<?php

declare(strict_types=1);

namespace SystemDesignLab\Tests\Instrumentation;

use InvalidArgumentException;
use LogicException;
use PHPUnit\Framework\TestCase;
use SystemDesignLab\Instrumentation\Counters;
use SystemDesignLab\Instrumentation\Measure;
use SystemDesignLab\Instrumentation\MemoryMeter;
use SystemDesignLab\Instrumentation\Stopwatch;

final class InstrumentationTest extends TestCase
{
    public function testCountersAccumulateAndReset(): void
    {
        $counters = new Counters();
        $counters->add('hits');
        $counters->add('hits', 2);
        $counters->add('misses');

        self::assertSame(3, $counters->get('hits'));
        self::assertSame(1, $counters->get('misses'));
        self::assertSame(0, $counters->get('unknown'));
        self::assertSame(['hits' => 3, 'misses' => 1], $counters->toArray());

        $counters->reset();
        self::assertSame([], $counters->toArray());
    }

    public function testCountersRejectNegativeDeltas(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new Counters()->add('hits', -1);
    }

    public function testStopwatchMeasuresMonotonicTime(): void
    {
        $watch = new Stopwatch();
        $watch->start();
        usleep(1_000);
        $elapsed = $watch->stop();

        self::assertGreaterThan(0.0, $elapsed);
        self::assertFalse($watch->isRunning());
    }

    public function testStopwatchRefusesDoubleStartAndStopWithoutStart(): void
    {
        $watch = new Stopwatch();
        $watch->start();

        try {
            $watch->start();
            self::fail('Expected a LogicException');
        } catch (LogicException) {
            $watch->stop();
        }

        $this->expectException(LogicException::class);
        $watch->stop();
    }

    public function testMemoryMeterReportsSnapshots(): void
    {
        $meter = new MemoryMeter();
        $before = $meter->snapshot();
        $payload = str_repeat('x', 10_000);
        $after = $meter->snapshot();

        self::assertGreaterThan($before['usage'], $after['usage']);
        self::assertSame($payload, str_repeat('x', 10_000));
        self::assertGreaterThanOrEqual(0, $meter->delta());
    }

    public function testMeasureCombinesInstruments(): void
    {
        $measure = new Measure();
        $measure->start();
        $measure->counters->add('ops', 3);
        $measure->stop();

        $result = $measure->toArray();
        self::assertSame(3, $result['ops']);
        self::assertArrayHasKey('elapsed_seconds', $result);
        self::assertArrayHasKey('php_delta_bytes', $result);
    }

    public function testMeasureRefusesResultWhileRunning(): void
    {
        $measure = new Measure();
        $measure->start();

        $this->expectException(LogicException::class);
        $measure->toArray();
    }
}
