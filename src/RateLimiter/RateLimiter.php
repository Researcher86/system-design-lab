<?php

declare(strict_types=1);

namespace SystemDesignLab\RateLimiter;

use SystemDesignLab\Instrumentation\Counters;

/**
 * A per-key admission policy.
 *
 * Time is an explicit parameter. A production limiter would read a clock and
 * share state through Redis or a gateway; this interface keeps the policy
 * testable and lets a simulation move time without sleeping.
 */
interface RateLimiter
{
    public function name(): string;

    public function attempt(string $key, int $nowMs): Decision;

    public function reset(string $key): void;

    public function trackedKeys(): int;

    public function counters(): Counters;
}
