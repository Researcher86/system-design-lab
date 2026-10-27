<?php

declare(strict_types=1);

namespace SystemDesignLab\Notification;

use InvalidArgumentException;

/**
 * A delivery channel is a separate message with its own preference and dedup
 * key. Fan-out is measured in channel intents, not in events.
 */
enum Channel: string
{
    case Email = 'email';
    case Push = 'push';
    case Sms = 'sms';

    /**
     * @return list<Channel>
     */
    public static function first(int $count): array
    {
        if ($count < 0 || $count > count(self::cases())) {
            throw new InvalidArgumentException(sprintf(
                'Channel count must be between 0 and %d, got %d',
                count(self::cases()),
                $count,
            ));
        }

        return array_slice(self::cases(), 0, $count);
    }
}
