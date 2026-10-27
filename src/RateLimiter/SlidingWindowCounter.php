<?php

declare(strict_types=1);

namespace SystemDesignLab\RateLimiter;

use InvalidArgumentException;
use SystemDesignLab\Instrumentation\Counters;

/**
 * A two-window approximation of a sliding window.
 *
 * It stores the current and previous window counts and weights the previous
 * one by how much of the current window has elapsed. Memory is O(1) per key,
 * but the decision is an estimate: the previous window's requests are not
 * uniformly distributed inside it. The accuracy experiment reports the false
 * allows and false denies instead of hiding them.
 */
final class SlidingWindowCounter implements RateLimiter
{
    /** @var array<string, SlidingCounterState> */
    private array $states = [];

    private readonly Counters $counters;

    public function __construct(
        private readonly int $limit,
        private readonly int $windowMs,
        ?Counters $counters = null,
    ) {
        if ($this->limit < 1) {
            throw new InvalidArgumentException('Sliding counter limit must be at least 1');
        }

        if ($this->windowMs < 1) {
            throw new InvalidArgumentException('Sliding counter window must be at least 1 ms');
        }

        $this->counters = $counters ?? new Counters();
    }

    public function name(): string
    {
        return 'sliding-counter';
    }

    public function attempt(string $key, int $nowMs): Decision
    {
        $this->counters->add('attempts');
        $windowStart = intdiv(max(0, $nowMs), $this->windowMs) * $this->windowMs;
        $state = $this->states[$key] ?? null;

        if ($state === null) {
            $state = new SlidingCounterState($windowStart, 0, 0);
            $this->states[$key] = $state;
            $this->counters->add('createdKeys');
        } elseif ($windowStart > $state->windowStartMs) {
            $distance = intdiv($windowStart - $state->windowStartMs, $this->windowMs);
            $state->previousCount = $distance === 1 ? $state->currentCount : 0;
            $state->currentCount = 0;
            $state->windowStartMs = $windowStart;
            $this->counters->add('windowRollovers');
        }

        $elapsedFraction = ($nowMs - $state->windowStartMs) / $this->windowMs;
        $weightedPrevious = $state->previousCount * (1.0 - $elapsedFraction);
        $estimated = $weightedPrevious + $state->currentCount;

        if ($estimated < $this->limit) {
            ++$state->currentCount;
            $this->counters->add('allowed');

            return new Decision(
                allowed: true,
                remaining: max(0, $this->limit - (int) ceil($weightedPrevious) - $state->currentCount),
                retryAfterMs: 0,
                limit: $this->limit,
            );
        }

        $this->counters->add('denied');

        return new Decision(
            allowed: false,
            remaining: 0,
            retryAfterMs: max(0, $state->windowStartMs + $this->windowMs - $nowMs),
            limit: $this->limit,
        );
    }

    public function reset(string $key): void
    {
        unset($this->states[$key]);
    }

    public function trackedKeys(): int
    {
        return count($this->states);
    }

    public function counters(): Counters
    {
        return $this->counters;
    }
}
