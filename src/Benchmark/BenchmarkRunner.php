<?php

declare(strict_types=1);

namespace SystemDesignLab\Benchmark;

use Closure;
use InvalidArgumentException;
use SystemDesignLab\Instrumentation\MemoryMeter;

/**
 * Runs a callable often enough, and often enough times, to say something
 * about it.
 *
 * The inner loop calls the operation `iterations` times and is timed. The
 * outer loop repeats that measurement so the report has a distribution rather
 * than one lucky sample. Warm-up repetitions run first and are discarded:
 * they measure the allocator growing an arena and pages being faulted in.
 */
final readonly class BenchmarkRunner
{
    public function __construct(
        private int $repetitions = 5,
        private int $warmups = 1,
    ) {
        if ($this->repetitions < 1) {
            throw new InvalidArgumentException('repetitions must be at least 1');
        }

        if ($this->warmups < 0) {
            throw new InvalidArgumentException('warmups cannot be negative');
        }
    }

    /**
     * @param Closure(): mixed $operation
     */
    public function run(string $name, Closure $operation, int $iterations = 1): BenchmarkResult
    {
        if ($iterations < 1) {
            throw new InvalidArgumentException(sprintf('%s: iterations must be at least 1', $name));
        }

        for ($warmup = 0; $warmup < $this->warmups; ++$warmup) {
            $this->measureOnce($operation, $iterations);
        }

        gc_collect_cycles();
        $memory = new MemoryMeter();
        $before = $memory->snapshot();
        $samples = [];

        for ($repetition = 0; $repetition < $this->repetitions; ++$repetition) {
            $samples[] = $this->measureOnce($operation, $iterations);
        }

        $after = $memory->snapshot();

        /** @var non-empty-list<float> $samples */
        return new BenchmarkResult(
            name: $name,
            iterations: $iterations,
            repetitions: $this->repetitions,
            elapsedSeconds: array_sum($samples),
            timings: Timings::of($samples),
            phpDelta: $after['usage'] - $before['usage'],
            peakDelta: max(0, $after['peak'] - $before['peak']),
        );
    }

    /**
     * @param Closure(): mixed $operation
     *
     * @return float seconds for one repetition
     */
    private function measureOnce(Closure $operation, int $iterations): float
    {
        $start = hrtime(true);

        for ($i = 0; $i < $iterations; ++$i) {
            $operation();
        }

        return (hrtime(true) - $start) / 1e9;
    }
}
