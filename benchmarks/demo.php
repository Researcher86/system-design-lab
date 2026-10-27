<?php

declare(strict_types=1);

use SystemDesignLab\Benchmark\Benchmark;
use SystemDesignLab\Foundation\DeterministicRandom;

$random = new DeterministicRandom('demo');

return [
    new Benchmark(
        name: 'hash xxh128',
        iterations: 20_000,
        run: static fn (): string => hash('xxh128', 'system-design-lab'),
    ),
    new Benchmark(
        name: 'deterministic random',
        iterations: 20_000,
        run: static fn (): int => $random->nextInt(),
    ),
];
