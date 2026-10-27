<?php

declare(strict_types=1);

namespace SystemDesignLab\Notification;

use InvalidArgumentException;

final readonly class Delivery
{
    public function __construct(
        public string $tenantId,
        public string $userId,
        public Channel $channel,
        public string $eventKey,
        public int $scheduledAtMs,
        public DeliveryStatus $status,
    ) {
        if ($this->scheduledAtMs < 0) {
            throw new InvalidArgumentException('Delivery timestamp cannot be negative');
        }
    }

    public function delayFrom(int $nowMs): int
    {
        return max(0, $this->scheduledAtMs - $nowMs);
    }
}
