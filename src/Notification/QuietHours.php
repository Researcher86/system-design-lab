<?php

declare(strict_types=1);

namespace SystemDesignLab\Notification;

use InvalidArgumentException;

/**
 * A daily quiet window in UTC, inclusive at the start and exclusive at the end.
 *
 * The window may wrap midnight. Equal start and end disables quiet hours
 * instead of meaning "quiet all day"; that makes the disabled state explicit
 * and avoids an accidental 24-hour suppression. A deferred notification is
 * moved to the next end boundary, which is the first allowed instant.
 */
final readonly class QuietHours
{
    public const HOUR_MS = 3_600_000;
    public const DAY_MS = 86_400_000;

    public function __construct(
        public int $startHour,
        public int $endHour,
    ) {
        if ($this->startHour < 0 || $this->startHour > 23) {
            throw new InvalidArgumentException('Quiet-hours start must be an hour between 0 and 23');
        }

        if ($this->endHour < 0 || $this->endHour > 23) {
            throw new InvalidArgumentException('Quiet-hours end must be an hour between 0 and 23');
        }
    }

    public function enabled(): bool
    {
        return $this->startHour !== $this->endHour;
    }

    public function contains(int $nowMs): bool
    {
        $this->assertTimestamp($nowMs);

        if (!$this->enabled()) {
            return false;
        }

        $hour = intdiv($nowMs % self::DAY_MS, self::HOUR_MS);

        if ($this->startHour < $this->endHour) {
            return $hour >= $this->startHour && $hour < $this->endHour;
        }

        return $hour >= $this->startHour || $hour < $this->endHour;
    }

    public function nextAllowedMs(int $nowMs): int
    {
        $this->assertTimestamp($nowMs);

        if (!$this->contains($nowMs)) {
            return $nowMs;
        }

        $dayStart = intdiv($nowMs, self::DAY_MS) * self::DAY_MS;
        $candidate = $dayStart + $this->endHour * self::HOUR_MS;

        while ($candidate <= $nowMs) {
            $candidate += self::DAY_MS;
        }

        return $candidate;
    }

    private function assertTimestamp(int $nowMs): void
    {
        if ($nowMs < 0) {
            throw new InvalidArgumentException('Timestamp cannot be negative');
        }
    }
}
