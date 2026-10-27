<?php

declare(strict_types=1);

namespace SystemDesignLab\Tests\Webhook;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use SystemDesignLab\Webhook\CircuitBreaker;
use SystemDesignLab\Webhook\CircuitState;

final class CircuitBreakerTest extends TestCase
{
    public function testClosedCircuitAllowsRequests(): void
    {
        $breaker = new CircuitBreaker(3, 1_000);

        self::assertSame(CircuitState::Closed, $breaker->state(0));
        self::assertTrue($breaker->allow(0));
        self::assertSame(1, $breaker->counters()->get('breakerAllowed'));
    }

    public function testThresholdOpensTheCircuit(): void
    {
        $breaker = new CircuitBreaker(2, 1_000);
        $breaker->recordFailure(0);

        self::assertSame(CircuitState::Closed, $breaker->state(0));

        $breaker->recordFailure(0);

        self::assertSame(CircuitState::Open, $breaker->state(0));
        self::assertSame(1, $breaker->counters()->get('breakerOpens'));
        self::assertFalse($breaker->allow(0));
        self::assertSame(1, $breaker->counters()->get('breakerRejected'));
    }

    public function testCooldownMovesToHalfOpenAndAllowsOneProbe(): void
    {
        $breaker = new CircuitBreaker(1, 1_000);
        $breaker->recordFailure(0);

        self::assertSame(CircuitState::Open, $breaker->state(999));
        self::assertFalse($breaker->allow(999));
        self::assertSame(CircuitState::HalfOpen, $breaker->state(1_000));
        self::assertTrue($breaker->allow(1_000));
        self::assertFalse($breaker->allow(1_000));
        self::assertSame(1, $breaker->counters()->get('breakerProbes'));
    }

    public function testSuccessfulProbeClosesTheCircuit(): void
    {
        $breaker = new CircuitBreaker(1, 100);
        $breaker->recordFailure(0);
        $breaker->allow(100);
        $breaker->recordSuccess(100);

        self::assertSame(CircuitState::Closed, $breaker->state(100));
        self::assertTrue($breaker->allow(100));
        self::assertSame(0, $breaker->consecutiveFailures());
        self::assertSame(1, $breaker->counters()->get('breakerCloses'));
    }

    public function testFailedProbeReopensTheCircuit(): void
    {
        $breaker = new CircuitBreaker(1, 100);
        $breaker->recordFailure(0);
        $breaker->allow(100);
        $breaker->recordFailure(100);

        self::assertSame(CircuitState::Open, $breaker->state(100));
        self::assertSame(CircuitState::HalfOpen, $breaker->state(200));
        self::assertSame(2, $breaker->counters()->get('breakerOpens'));
        self::assertSame(100, $breaker->retryAfterMs(100));
    }

    public function testZeroCooldownTransitionsImmediatelyToHalfOpen(): void
    {
        $breaker = new CircuitBreaker(1, 0);
        $breaker->recordFailure(0);

        self::assertSame(CircuitState::HalfOpen, $breaker->state(0));
        self::assertTrue($breaker->allow(0));
    }

    public function testSuccessResetsConsecutiveFailures(): void
    {
        $breaker = new CircuitBreaker(3, 100);
        $breaker->recordFailure(0);
        $breaker->recordFailure(0);
        $breaker->recordSuccess(0);
        $breaker->recordFailure(0);

        self::assertSame(CircuitState::Closed, $breaker->state(0));
        self::assertSame(1, $breaker->consecutiveFailures());
    }

    public function testFailureThresholdMustBePositive(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new CircuitBreaker(0, 100);
    }

    public function testCooldownCannotBeNegative(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new CircuitBreaker(1, -1);
    }
}
