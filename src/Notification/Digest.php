<?php

declare(strict_types=1);

namespace SystemDesignLab\Notification;

/**
 * One batch for one user, one channel and one digest day.
 */
final readonly class Digest
{
    /**
     * @param list<Notification> $notifications
     */
    public function __construct(
        public string $tenantId,
        public string $userId,
        public Channel $channel,
        public int $dayStartMs,
        public array $notifications,
    ) {
    }

    public function count(): int
    {
        return count($this->notifications);
    }
}
