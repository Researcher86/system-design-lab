<?php

declare(strict_types=1);

namespace SystemDesignLab\Foundation;

use InvalidArgumentException;

/**
 * A deterministic Zipf sampler.
 *
 * Real systems rarely see uniform traffic: a small set of links, users or
 * cache keys receives most requests. The sampler precomputes the cumulative
 * distribution once and uses a binary search per draw, so a simulation does
 * not pay an O(ranks) loop for every request.
 */
final class ZipfDistribution
{
    /** @var list<float> */
    private array $cumulative = [];

    public function __construct(
        private readonly int $ranks,
        float $alpha,
    ) {
        if ($ranks < 1) {
            throw new InvalidArgumentException('Zipf ranks must be at least 1');
        }

        if ($alpha <= 0.0) {
            throw new InvalidArgumentException('Zipf alpha must be positive');
        }

        $total = 0.0;

        for ($rank = 1; $rank <= $ranks; ++$rank) {
            $total += 1.0 / ($rank ** $alpha);
            $this->cumulative[] = $total;
        }
    }

    /**
     * @return int A rank in [1, ranks].
     */
    public function sample(DeterministicRandom $random): int
    {
        $target = $random->nextFloat() * $this->cumulative[$this->ranks - 1];
        $low = 0;
        $high = $this->ranks - 1;

        while ($low < $high) {
            $middle = intdiv($low + $high, 2);

            if ($this->cumulative[$middle] < $target) {
                $low = $middle + 1;
            } else {
                $high = $middle;
            }
        }

        return $low + 1;
    }

    public function ranks(): int
    {
        return $this->ranks;
    }
}
