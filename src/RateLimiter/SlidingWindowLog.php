<?php

declare(strict_types=1);

namespace SystemDesignLab\RateLimiter;

use InvalidArgumentException;
use SystemDesignLab\Instrumentation\Counters;

/**
 * An exact sliding-window log.
 *
 * Every accepted request is stored until it falls out of the window. The
 * decision is exact: at most `limit` accepted requests fit into any interval
 * of `windowMs`. The price is memory proportional to the limit, which the
 * `rate:memory` experiment makes visible.
 */
final class SlidingWindowLog implements RateLimiter
{
    /** @var array<string, SlidingWindowState> */
    private array $states = [];

    private readonly Counters $counters;

    public function __construct(
        private readonly int $limit,
        private readonly int $windowMs,
        ?Counters $counters = null,
    ) {
        if ($this->limit < 1) {
            throw new InvalidArgumentException('Sliding-window limit must be at least 1');
        }

        if ($this->windowMs < 1) {
            throw new InvalidArgumentException('Sliding-window length must be at least 1 ms');
        }

        $this->counters = $counters ?? new Counters();
    }

    public function name(): string
    {
        return 'sliding-log';
    }

    public function attempt(string $key, int $nowMs): Decision
    {
        $this->counters->add('attempts');
        $state = $this->states[$key] ?? null;

        if ($state === null) {
            $state = new SlidingWindowState($this->limit);
            $this->states[$key] = $state;
            $this->counters->add('createdKeys');
        }

        $this->prune($state, $nowMs);

        if ($state->count < $this->limit) {
            $index = ($state->head + $state->count) % $this->limit;
            $state->timestamps[$index] = $nowMs;
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
            retryAfterMs: max(0, $state->timestamps[$state->head] + $this->windowMs - $nowMs),
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

    private function prune(SlidingWindowState $state, int $nowMs): void
    {
        while ($state->count > 0 && $state->timestamps[$state->head] <= $nowMs - $this->windowMs) {
            $state->head = ($state->head + 1) % $state->limit;
            --$state->count;
            $this->counters->add('expiredEntries');
        }
    }
}
