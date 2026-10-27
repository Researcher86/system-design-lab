<?php

declare(strict_types=1);

namespace SystemDesignLab\Instrumentation;

use LogicException;

/**
 * A monotonic stopwatch based on hrtime().
 *
 * hrtime() is not affected by wall-clock changes, which is the minimum a
 * benchmark timer needs. The class refuses a double start and a stop without
 * a start instead of guessing what the caller meant.
 */
final class Stopwatch
{
    private ?int $startedAt = null;

    private ?float $elapsed = null;

    public function start(): void
    {
        if ($this->startedAt !== null) {
            throw new LogicException('Stopwatch is already running');
        }

        $this->startedAt = hrtime(true);
        $this->elapsed = null;
    }

    public function stop(): float
    {
        if ($this->startedAt === null) {
            throw new LogicException('Stopwatch is not running');
        }

        $this->elapsed = (hrtime(true) - $this->startedAt) / 1e9;
        $this->startedAt = null;

        return $this->elapsed;
    }

    public function elapsedSeconds(): float
    {
        if ($this->startedAt !== null) {
            return (hrtime(true) - $this->startedAt) / 1e9;
        }

        return $this->elapsed ?? 0.0;
    }

    public function isRunning(): bool
    {
        return $this->startedAt !== null;
    }
}
