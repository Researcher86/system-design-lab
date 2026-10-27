<?php

declare(strict_types=1);

namespace SystemDesignLab\Instrumentation;

use InvalidArgumentException;

/**
 * Named operation counters for one measured run.
 *
 * Time says how fast this machine ran. Counters say what the code actually
 * did: requests admitted, leases expired, postings scanned, retries scheduled.
 * They are the primary evidence for the behaviour a chapter claims, and they
 * are kept separate from wall-clock measurements on purpose.
 */
final class Counters
{
    /** @var array<string, int> */
    private array $values = [];

    public function add(string $name, int $amount = 1): void
    {
        if ($amount < 0) {
            throw new InvalidArgumentException('Counter deltas cannot be negative');
        }

        $this->values[$name] = ($this->values[$name] ?? 0) + $amount;
    }

    public function get(string $name): int
    {
        return $this->values[$name] ?? 0;
    }

    public function reset(): void
    {
        $this->values = [];
    }

    /**
     * @return array<string, int>
     */
    public function toArray(): array
    {
        ksort($this->values);

        return $this->values;
    }
}
