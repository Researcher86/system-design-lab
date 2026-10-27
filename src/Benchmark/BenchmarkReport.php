<?php

declare(strict_types=1);

namespace SystemDesignLab\Benchmark;

/**
 * A suite's results together with the machine that produced them.
 */
final readonly class BenchmarkReport
{
    /**
     * @param list<BenchmarkResult> $results
     */
    public function __construct(
        public string $suite,
        public Environment $environment,
        public array $results,
    ) {
    }
}
