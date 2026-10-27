<?php

declare(strict_types=1);

namespace SystemDesignLab\RateLimiter;

use InvalidArgumentException;
use SystemDesignLab\Instrumentation\Counters;

/**
 * A fixed window counter.
 *
 * It is the cheapest limiter to implement and the easiest to get wrong at a
 * boundary: a client can spend the whole budget just before the boundary and
 * the whole budget again just after it. The `rate:burst` experiment shows the
 * double-spend instead of explaining it away.
 */
final class FixedWindowCounter implements RateLimiter
{
    /** @var array<string, FixedWindowState> */
    private array $states = [];

    private readonly Counters $counters;

    public function __construct(
        private readonly int $limit,
        private readonly int $windowMs,
        ?Counters $counters = null,
    ) {
        if ($this->limit < 1) {
            throw new InvalidArgumentException('Window limit must be at least 1');
        }

        if ($this->windowMs < 1) {
            throw new InvalidArgumentException('Window length must be at least 1 ms');
        }

        $this->counters = $counters ?? new Counters();
    }

    public function name(): string
    {
        return 'fixed-window';
    }

    public function attempt(string $key, int $nowMs): Decision
    {
        $this->counters->add('attempts');
        $state = $this->states[$key] ?? null;
        $windowStart = intdiv(max(0, $nowMs), $this->windowMs) * $this->windowMs;

        if ($state === null) {
            $state = new FixedWindowState($windowStart, 0);
            $this->states[$key] = $state;
            $this->counters->add('createdKeys');
        } elseif ($windowStart > $state->windowStartMs) {
            $state->windowStartMs = $windowStart;
            $state->count = 0;
            $this->counters->add('windowRollovers');
        }

        if ($state->count < $this->limit) {
            ++$state->count;
            $this->counters->add('allowed');

            return new Decision(
                allowed: true,
                remaining: $this->limit - $state->count,
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
