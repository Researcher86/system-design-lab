<?php

declare(strict_types=1);

namespace SystemDesignLab\RateLimiter;

use InvalidArgumentException;
use SystemDesignLab\Instrumentation\Counters;

/**
 * A token bucket: a burst up to `capacity`, then a smooth refill.
 *
 * The bucket allows a short burst by design. That is a feature for an API
 * client that sends a batch, and a problem for a downstream service that
 * assumed the rate was smooth. The experiment measures both faces.
 */
final class TokenBucket implements RateLimiter
{
    private const UNIT = 1_000_000_000;

    /** @var array<string, BucketState> */
    private array $states = [];

    private readonly int $capacityUnits;

    private readonly int $refillUnitsPerMs;

    private readonly Counters $counters;

    public function __construct(
        private readonly int $capacity,
        private readonly float $refillPerSecond,
        ?Counters $counters = null,
    ) {
        if ($this->capacity < 1) {
            throw new InvalidArgumentException('Token bucket capacity must be at least 1');
        }

        if ($this->refillPerSecond < 0.0) {
            throw new InvalidArgumentException('Token bucket refill cannot be negative');
        }

        $this->capacityUnits = $this->capacity * self::UNIT;
        $this->refillUnitsPerMs = (int) round($this->refillPerSecond * self::UNIT / 1000.0);
        $this->counters = $counters ?? new Counters();
    }

    public function name(): string
    {
        return 'token-bucket';
    }

    public function attempt(string $key, int $nowMs): Decision
    {
        $this->counters->add('attempts');
        $state = $this->states[$key] ?? null;

        if ($state === null) {
            $state = new BucketState($this->capacityUnits, $nowMs);
            $this->states[$key] = $state;
            $this->counters->add('createdKeys');
        }

        $elapsed = max(0, $nowMs - $state->lastRefillMs);

        if ($elapsed > 0) {
            if ($state->units < $this->capacityUnits && $this->refillUnitsPerMs > 0) {
                $refill = $elapsed * $this->refillUnitsPerMs;
                $state->units = min($this->capacityUnits, $state->units + $refill);
                $this->counters->add('refills');
            }

            $state->lastRefillMs = max($state->lastRefillMs, $nowMs);
        }

        if ($state->units >= self::UNIT) {
            $state->units -= self::UNIT;
            $this->counters->add('allowed');

            return new Decision(
                allowed: true,
                remaining: intdiv($state->units, self::UNIT),
                retryAfterMs: 0,
                limit: $this->capacity,
            );
        }

        $this->counters->add('denied');
        $missing = self::UNIT - $state->units;
        $retryAfterMs = $this->refillUnitsPerMs > 0
            ? intdiv($missing + $this->refillUnitsPerMs - 1, $this->refillUnitsPerMs)
            : PHP_INT_MAX;

        return new Decision(
            allowed: false,
            remaining: 0,
            retryAfterMs: $retryAfterMs,
            limit: $this->capacity,
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
