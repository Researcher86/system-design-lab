<?php

declare(strict_types=1);

namespace SystemDesignLab\Foundation;

final class VirtualClock implements Clock
{
    public function __construct(
        private int $nowMs = 0,
    ) {
    }

    public function nowMs(): int
    {
        return $this->nowMs;
    }

    public function advance(int $milliseconds): void
    {
        if ($milliseconds < 0) {
            throw new \InvalidArgumentException('Cannot move a virtual clock backwards');
        }

        $this->nowMs += $milliseconds;
    }

    public function set(int $milliseconds): void
    {
        if ($milliseconds < 0) {
            throw new \InvalidArgumentException('A timestamp cannot be negative');
        }

        $this->nowMs = $milliseconds;
    }
}
