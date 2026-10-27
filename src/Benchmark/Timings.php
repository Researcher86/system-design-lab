<?php

declare(strict_types=1);

namespace SystemDesignLab\Benchmark;

/**
 * The distribution of one benchmark's repetitions, in seconds.
 *
 * The median is the headline because a single repetition can lose its CPU to
 * something else. The median absolute deviation says how much the samples
 * actually vary; min and max are kept because a p95 on five samples is a
 * coarse statement and should not pretend otherwise.
 */
final readonly class Timings
{
    private function __construct(
        public float $min,
        public float $max,
        public float $mean,
        public float $median,
        public float $p95,
        public float $mad,
    ) {
    }

    /**
     * @param non-empty-list<float> $samples seconds per repetition
     */
    public static function of(array $samples): self
    {
        sort($samples);
        $count = count($samples);
        $median = self::percentile($samples, 0.5);
        $deviations = [];

        foreach ($samples as $sample) {
            $deviations[] = abs($sample - $median);
        }

        sort($deviations);

        return new self(
            min: $samples[0],
            max: $samples[$count - 1],
            mean: array_sum($samples) / $count,
            median: $median,
            p95: self::percentile($samples, 0.95),
            mad: self::percentile($deviations, 0.5),
        );
    }

    /**
     * Nearest-rank on an already sorted list. Interpolation would invent
     * precision that five samples do not have.
     *
     * @param non-empty-list<float> $sorted
     */
    private static function percentile(array $sorted, float $fraction): float
    {
        $count = count($sorted);
        $rank = (int) ceil($fraction * $count) - 1;

        return $sorted[max(0, min($count - 1, $rank))];
    }
}
