<?php

declare(strict_types=1);

namespace SystemDesignLab\Notification;

use InvalidArgumentException;
use SystemDesignLab\Instrumentation\Counters;

/**
 * Groups non-urgent notifications by tenant, user, channel and digest day.
 *
 * A batch is due once the clock has moved into a later day. `flushAll()` is
 * the end-of-simulation path; it returns every bucket, including the current
 * one. The buffer does not choose a send time; it only bounds how many
 * messages a day produces.
 */
final class DigestBuffer
{
    /**
     * @var array<string, array{
     *     tenantId: string,
     *     userId: string,
     *     channel: Channel,
     *     day: int,
     *     items: list<Notification>
     * }>
     */
    private array $buckets = [];

    private readonly Counters $counters;

    public function __construct(
        private readonly int $dayMs = QuietHours::DAY_MS,
        ?Counters $counters = null,
    ) {
        if ($this->dayMs < 1) {
            throw new InvalidArgumentException('Digest day must be at least 1 ms');
        }

        $this->counters = $counters ?? new Counters();
    }

    public function add(Notification $notification, Channel $channel): void
    {
        $day = intdiv($notification->createdAtMs, $this->dayMs);
        $key = $notification->tenantId . "\0" . $notification->userId . "\0" . $channel->value . "\0" . $day;

        if (!isset($this->buckets[$key])) {
            $this->buckets[$key] = [
                'tenantId' => $notification->tenantId,
                'userId' => $notification->userId,
                'channel' => $channel,
                'day' => $day,
                'items' => [],
            ];
        }

        $this->buckets[$key]['items'][] = $notification;
        $this->counters->add('buffered');
    }

    /**
     * @return list<Digest>
     */
    public function due(int $nowMs): array
    {
        $currentDay = intdiv($nowMs, $this->dayMs);

        return $this->take(static fn (int $day): bool => $day < $currentDay);
    }

    /**
     * @return list<Digest>
     */
    public function flushAll(): array
    {
        return $this->take(static fn (int $day): bool => true);
    }

    public function size(): int
    {
        return count($this->buckets);
    }

    public function itemCount(): int
    {
        $items = 0;

        foreach ($this->buckets as $bucket) {
            $items += count($bucket['items']);
        }

        return $items;
    }

    public function counters(): Counters
    {
        return $this->counters;
    }

    /**
     * @param callable(int): bool $isDue
     *
     * @return list<Digest>
     */
    private function take(callable $isDue): array
    {
        $digests = [];

        foreach ($this->buckets as $key => $bucket) {
            if (!$isDue($bucket['day'])) {
                continue;
            }

            $digests[] = new Digest(
                tenantId: $bucket['tenantId'],
                userId: $bucket['userId'],
                channel: $bucket['channel'],
                dayStartMs: $bucket['day'] * $this->dayMs,
                notifications: $bucket['items'],
            );
            unset($this->buckets[$key]);
            $this->counters->add('digests');
            $this->counters->add('flushedItems', count($bucket['items']));
        }

        return $digests;
    }
}
