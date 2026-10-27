<?php

declare(strict_types=1);

namespace SystemDesignLab\Notification;

use InvalidArgumentException;

/**
 * One user's delivery choices.
 *
 * Channels are a list, not a bitmask, so fan-out has to iterate them and a
 * preference change is visible in the result. Digest mode batches non-urgent
 * messages; quiet hours defer them. An urgent notification bypasses both.
 */
final readonly class UserPreferences
{
    /**
     * @param list<Channel> $channels
     */
    public function __construct(
        public array $channels,
        public bool $digest = false,
        public ?QuietHours $quietHours = null,
    ) {
        $seen = [];

        foreach ($channels as $channel) {
            if (isset($seen[$channel->value])) {
                throw new InvalidArgumentException(sprintf('Duplicate channel in preferences: %s', $channel->value));
            }

            $seen[$channel->value] = true;
        }
    }

    public static function all(bool $digest = false, ?QuietHours $quietHours = null): self
    {
        return new self(Channel::cases(), $digest, $quietHours);
    }

    public static function none(): self
    {
        return new self([]);
    }

    public function allows(Channel $channel): bool
    {
        return in_array($channel, $this->channels, true);
    }
}
