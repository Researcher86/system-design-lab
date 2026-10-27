<?php

declare(strict_types=1);

namespace SystemDesignLab\Notification;

/**
 * The plan for one event: what would be sent now, deferred, batched or
 * suppressed. It is a plan, not a delivery receipt; the queue chapter turns
 * the plan into work with retries.
 */
final readonly class NotificationResult
{
    /**
     * @param list<Delivery> $deliveries
     */
    public function __construct(
        public array $deliveries,
        public int $digested,
        public int $suppressedDuplicates,
        public int $suppressedByPreferences,
        public int $channelIntents,
    ) {
    }

    public function delivered(): int
    {
        return count($this->deliveries);
    }

    public function immediate(): int
    {
        return $this->countStatus(DeliveryStatus::Immediate);
    }

    public function deferred(): int
    {
        return $this->countStatus(DeliveryStatus::Deferred);
    }

    private function countStatus(DeliveryStatus $status): int
    {
        $count = 0;

        foreach ($this->deliveries as $delivery) {
            if ($delivery->status === $status) {
                ++$count;
            }
        }

        return $count;
    }
}
