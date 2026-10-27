<?php

declare(strict_types=1);

namespace SystemDesignLab\RateLimiter;

use SystemDesignLab\Instrumentation\Counters;

/**
 * A baseline that admits everything. Without it, "the limiter is fast" is not
 * a useful statement: it has to be compared with doing nothing.
 */
final class UnlimitedLimiter implements RateLimiter
{
    private readonly Counters $counters;

    public function __construct(?Counters $counters = null)
    {
        $this->counters = $counters ?? new Counters();
    }

    public function name(): string
    {
        return 'unlimited';
    }

    public function attempt(string $key, int $nowMs): Decision
    {
        $this->counters->add('attempts');
        $this->counters->add('allowed');

        return new Decision(true, PHP_INT_MAX, 0, PHP_INT_MAX);
    }

    public function reset(string $key): void
    {
    }

    public function trackedKeys(): int
    {
        return 0;
    }

    public function counters(): Counters
    {
        return $this->counters;
    }
}
