<?php

declare(strict_types=1);

namespace SystemDesignLab\Webhook;

use InvalidArgumentException;
use SystemDesignLab\Instrumentation\Counters;

/**
 * A per-endpoint circuit breaker.
 *
 * Consecutive failures open the circuit for `cooldownMs`. After the cooldown
 * the breaker is half-open: exactly one probe is allowed through. A successful
 * probe closes the circuit and resets the failure count; a failed probe opens
 * it for another cooldown. While open, the breaker rejects without sending.
 */
final class CircuitBreaker
{
    private int $consecutiveFailures = 0;

    private bool $open = false;

    private int $openUntilMs = 0;

    private bool $probeInFlight = false;

    private readonly Counters $counters;

    public function __construct(
        private readonly int $failureThreshold,
        private readonly int $cooldownMs,
        ?Counters $counters = null,
    ) {
        if ($this->failureThreshold < 1) {
            throw new InvalidArgumentException('Circuit failure threshold must be at least 1');
        }

        if ($this->cooldownMs < 0) {
            throw new InvalidArgumentException('Circuit cooldown cannot be negative');
        }

        $this->counters = $counters ?? new Counters();
    }

    public function state(int $nowMs): CircuitState
    {
        if (!$this->open) {
            return CircuitState::Closed;
        }

        return $nowMs < $this->openUntilMs ? CircuitState::Open : CircuitState::HalfOpen;
    }

    public function allow(int $nowMs): bool
    {
        $state = $this->state($nowMs);

        if ($state === CircuitState::Closed) {
            $this->counters->add('breakerAllowed');

            return true;
        }

        if ($state === CircuitState::Open) {
            $this->counters->add('breakerRejected');

            return false;
        }

        if ($this->probeInFlight) {
            $this->counters->add('breakerRejected');

            return false;
        }

        $this->probeInFlight = true;
        $this->counters->add('breakerAllowed');
        $this->counters->add('breakerProbes');

        return true;
    }

    public function recordSuccess(int $nowMs): void
    {
        $wasOpen = $this->open;
        $this->open = false;
        $this->consecutiveFailures = 0;
        $this->openUntilMs = 0;
        $this->probeInFlight = false;
        $this->counters->add('breakerSuccesses');

        if ($wasOpen) {
            $this->counters->add('breakerCloses');
        }
    }

    public function recordFailure(int $nowMs): void
    {
        $this->counters->add('breakerFailures');
        $this->probeInFlight = false;

        if ($this->open) {
            $this->openUntilMs = $nowMs + $this->cooldownMs;
            $this->counters->add('breakerOpens');

            return;
        }

        ++$this->consecutiveFailures;

        if ($this->consecutiveFailures >= $this->failureThreshold) {
            $this->open = true;
            $this->openUntilMs = $nowMs + $this->cooldownMs;
            $this->counters->add('breakerOpens');
        }
    }

    public function retryAfterMs(int $nowMs): int
    {
        if (!$this->open) {
            return 0;
        }

        return max(0, $this->openUntilMs - $nowMs);
    }

    public function consecutiveFailures(): int
    {
        return $this->consecutiveFailures;
    }

    public function counters(): Counters
    {
        return $this->counters;
    }
}
