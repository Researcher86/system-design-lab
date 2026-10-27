<?php

declare(strict_types=1);

namespace SystemDesignLab\Experiment;

use Closure;

/**
 * One runnable experiment: name, description, declared options, and work.
 *
 * The descriptor is readonly data; the closure is only executed by the runner.
 * A file under experiments/ therefore costs almost nothing to discover, which
 * is what lets the registry load every experiment to print the list.
 */
final readonly class Experiment
{
    public function __construct(
        public string $name,
        public string $description,
        /** @var list<string> */
        public array $supports,
        /** @var Closure(Options, Output): void */
        public Closure $run,
    ) {
    }
}
