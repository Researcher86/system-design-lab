<?php

declare(strict_types=1);

namespace SystemDesignLab\Queue;

use InvalidArgumentException;
use SystemDesignLab\Foundation\DeterministicRandom;

/**
 * Exponential retry delays with optional deterministic jitter.
 *
 * Attempt 1 waits `baseMs`, attempt 2 waits `baseMs * multiplier`, and the
 * result is capped at `maxMs`. Jitter is added after the exponential value
 * and the sum is capped again. The random source is seeded, so a failure
 * sequence is reproducible; this is not a cryptographic jitter source.
 */
final readonly class ExponentialBackoff
{
    private DeterministicRandom $random;

    public function __construct(
        public int $baseMs = 1_000,
        public float $multiplier = 2.0,
        public int $maxMs = 60_000,
        public int $jitterMs = 0,
        ?DeterministicRandom $random = null,
    ) {
        if ($this->baseMs < 1) {
            throw new InvalidArgumentException('Backoff base must be at least 1 ms');
        }

        if ($this->multiplier < 1.0) {
            throw new InvalidArgumentException('Backoff multiplier must be at least 1');
        }

        if ($this->maxMs < $this->baseMs) {
            throw new InvalidArgumentException('Backoff cap cannot be below the base');
        }

        if ($this->jitterMs < 0) {
            throw new InvalidArgumentException('Backoff jitter cannot be negative');
        }

        $this->random = $random ?? new DeterministicRandom('backoff');
    }

    public function delayMs(int $attempt): int
    {
        if ($attempt < 1) {
            throw new InvalidArgumentException('Backoff attempt must be at least 1');
        }

        $raw = $this->baseMs * ($this->multiplier ** ($attempt - 1));
        $delay = $raw >= $this->maxMs ? $this->maxMs : (int) round($raw);

        if ($this->jitterMs > 0) {
            $delay = min($this->maxMs, $delay + $this->random->intBetween(0, $this->jitterMs));
        }

        return $delay;
    }
}
