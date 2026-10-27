<?php

declare(strict_types=1);

namespace SystemDesignLab\Tests\Benchmark;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use SystemDesignLab\Benchmark\Benchmark;
use SystemDesignLab\Benchmark\BenchmarkReport;
use SystemDesignLab\Benchmark\BenchmarkRunner;
use SystemDesignLab\Benchmark\Environment;
use SystemDesignLab\Benchmark\Formatter\CsvFormatter;
use SystemDesignLab\Benchmark\Formatter\JsonFormatter;
use SystemDesignLab\Benchmark\Formatter\TextFormatter;
use SystemDesignLab\Benchmark\Timings;

final class BenchmarkHarnessTest extends TestCase
{
    public function testRunnerProducesDistribution(): void
    {
        $runner = new BenchmarkRunner(repetitions: 3, warmups: 0);
        $result = $runner->run('noop', static fn (): int => 1, 10);

        self::assertSame('noop', $result->name);
        self::assertSame(10, $result->iterations);
        self::assertSame(3, $result->repetitions);
        self::assertGreaterThan(0.0, $result->timings->median);
        self::assertGreaterThan(0.0, $result->operationsPerSecond());
        self::assertGreaterThanOrEqual($result->timings->min, $result->timings->median);
        self::assertLessThanOrEqual($result->timings->max, $result->timings->median);
    }

    public function testRunnerRejectsInvalidCounts(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new BenchmarkRunner(repetitions: 0);
    }

    public function testRunnerRejectsNonPositiveIterations(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new BenchmarkRunner()->run('bad', static fn (): null => null, 0);
    }

    public function testTimingsOfOneSample(): void
    {
        $timings = Timings::of([0.01]);

        self::assertSame(0.01, $timings->min);
        self::assertSame(0.01, $timings->max);
        self::assertSame(0.01, $timings->median);
        self::assertSame(0.0, $timings->mad);
    }

    public function testFormattersRenderEnvironmentAndResults(): void
    {
        $report = new BenchmarkReport(
            suite: 'demo',
            environment: Environment::detect(),
            results: [
                new BenchmarkRunner(repetitions: 1, warmups: 0)->run('op', static fn (): int => 1, 5),
            ],
        );

        $text = new TextFormatter()->format($report);
        self::assertStringContainsString('Benchmark suite: demo', $text);
        self::assertStringContainsString('PHP', $text);
        self::assertStringContainsString('op', $text);
        self::assertStringContainsString('git ', $text);

        $json = new JsonFormatter()->format($report);
        $decoded = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('demo', $decoded['suite']);
        self::assertSame('op', $decoded['results'][0]['name']);

        $csv = new CsvFormatter()->format($report);
        self::assertStringContainsString('suite,benchmark,iterations', $csv);
        self::assertStringContainsString('demo,op', $csv);
    }

    public function testBenchmarkDescriptorCarriesIterations(): void
    {
        $benchmark = new Benchmark('x', 12, static fn (): null => null);

        self::assertSame('x', $benchmark->name);
        self::assertSame(12, $benchmark->iterations);
    }
}
