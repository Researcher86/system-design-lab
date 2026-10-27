<?php

declare(strict_types=1);

namespace SystemDesignLab\Notification;

use InvalidArgumentException;
use SystemDesignLab\Instrumentation\Counters;

/**
 * A TTL map of claimed keys.
 *
 * The TTL starts on the first claim; a duplicate does not extend it. Expired
 * entries are removed lazily on claim or by an explicit purge, so `size()`
 * includes entries that are already dead but not yet swept. The periodic sweep
 * keeps a long simulation from turning the map into an unbounded leak. A TTL
 * of zero disables deduplication without a separate store class.
 */
final class InMemoryDedupStore implements DedupStore
{
    /** @var array<string, int> key => expiry timestamp */
    private array $expiresAt = [];

    private readonly Counters $counters;

    public function __construct(
        private readonly int $ttlMs,
        private readonly int $cleanupInterval = 1_024,
        ?Counters $counters = null,
    ) {
        if ($this->ttlMs < 0) {
            throw new InvalidArgumentException('Deduplication TTL cannot be negative');
        }

        if ($this->cleanupInterval < 0) {
            throw new InvalidArgumentException('Cleanup interval cannot be negative');
        }

        $this->counters = $counters ?? new Counters();
    }

    public function claim(string $key, int $nowMs): bool
    {
        if ($nowMs < 0) {
            throw new InvalidArgumentException('Timestamp cannot be negative');
        }

        $this->counters->add('claims');

        if ($this->ttlMs === 0) {
            $this->counters->add('accepted');

            return true;
        }

        $expiresAt = $this->expiresAt[$key] ?? null;

        if ($expiresAt !== null && $expiresAt > $nowMs) {
            $this->counters->add('suppressed');

            return false;
        }

        $this->expiresAt[$key] = $nowMs + $this->ttlMs;
        $this->counters->add('accepted');

        if ($this->cleanupInterval > 0 && $this->counters->get('claims') % $this->cleanupInterval === 0) {
            $this->purge($nowMs);
        }

        return true;
    }

    public function purge(int $nowMs): int
    {
        $removed = 0;

        foreach ($this->expiresAt as $key => $expiresAt) {
            if ($expiresAt <= $nowMs) {
                unset($this->expiresAt[$key]);
                ++$removed;
            }
        }

        if ($removed > 0) {
            $this->counters->add('purged', $removed);
        }

        return $removed;
    }

    public function size(): int
    {
        return count($this->expiresAt);
    }

    public function counters(): Counters
    {
        return $this->counters;
    }
}
