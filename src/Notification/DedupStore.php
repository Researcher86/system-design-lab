<?php

declare(strict_types=1);

namespace SystemDesignLab\Notification;

use SystemDesignLab\Instrumentation\Counters;

interface DedupStore
{
    /**
     * Claims a key for the first time inside its TTL.
     *
     * @return bool true when the caller owns the key, false for a duplicate
     */
    public function claim(string $key, int $nowMs): bool;

    public function purge(int $nowMs): int;

    public function size(): int;

    public function counters(): Counters;
}
