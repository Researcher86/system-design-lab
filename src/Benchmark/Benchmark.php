<?php

declare(strict_types=1);

namespace SystemDesignLab\Benchmark;

use Closure;

/**
 * One named operation to measure, with the iteration count that makes it
 * measurable.
 *
 * The count belongs to the benchmark because it is a property of the
 * operation: a cache hit needs many more calls than building a thousand-link
 * index before either rises above timer noise.
 */
final readonly class Benchmark
{
    public function __construct(
        public string $name,
        public int $iterations,
        /** @var Closure(): mixed */
        public Closure $run,
    ) {
    }
}
