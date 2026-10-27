<?php

declare(strict_types=1);

namespace SystemDesignLab\Benchmark;

/**
 * One benchmark's completed measurement.
 */
final readonly class BenchmarkResult
{
    public function __construct(
        public string $name,
        public int $iterations,
        public int $repetitions,
        public float $elapsedSeconds,
        public Timings $timings,
        public int $phpDelta,
        public int $peakDelta,
    ) {
    }

    public function operationsPerSecond(): float
    {
        return $this->timings->median > 0.0
            ? $this->iterations / $this->timings->median
            : 0.0;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'iterations' => $this->iterations,
            'repetitions' => $this->repetitions,
            'ops_per_second' => $this->operationsPerSecond(),
            'timings_seconds' => [
                'min' => $this->timings->min,
                'max' => $this->timings->max,
                'mean' => $this->timings->mean,
                'median' => $this->timings->median,
                'p95' => $this->timings->p95,
                'mad' => $this->timings->mad,
            ],
            'php_delta_bytes' => $this->phpDelta,
            'peak_delta_bytes' => $this->peakDelta,
        ];
    }
}
