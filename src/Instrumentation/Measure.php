<?php

declare(strict_types=1);

namespace SystemDesignLab\Instrumentation;

use LogicException;

/**
 * One measurement: counters, elapsed time and PHP memory.
 *
 * Experiments use this facade instead of wiring the three instruments by hand,
 * so every result has the same shape and the same reset semantics.
 */
final class Measure
{
    public readonly Counters $counters;

    public readonly Stopwatch $stopwatch;

    public readonly MemoryMeter $memory;

    public function __construct()
    {
        $this->counters = new Counters();
        $this->stopwatch = new Stopwatch();
        $this->memory = new MemoryMeter();
    }

    public function start(): void
    {
        $this->counters->reset();
        $this->memory->resetPeak();
        $this->stopwatch->start();
    }

    public function stop(): void
    {
        $this->stopwatch->stop();
    }

    public function elapsedSeconds(): float
    {
        return $this->stopwatch->elapsedSeconds();
    }

    public function deltaBytes(): int
    {
        return $this->memory->delta();
    }

    /**
     * @return array<string, int|float>
     */
    public function toArray(): array
    {
        if ($this->stopwatch->isRunning()) {
            throw new LogicException('Stop the measurement before reading its result');
        }

        return [
            ...$this->counters->toArray(),
            'elapsed_seconds' => $this->elapsedSeconds(),
            'php_delta_bytes' => $this->deltaBytes(),
        ];
    }
}
