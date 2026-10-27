<?php

declare(strict_types=1);

namespace SystemDesignLab\Notification;

use SystemDesignLab\Instrumentation\Counters;

/**
 * Plans notifications for one event.
 *
 * The order is deliberate: preferences choose the channels, deduplication
 * claims each channel, quiet hours defer, digest batches, and only then does
 * an immediate delivery exist. Deduplication is per channel because a user
 * with email and push has two independent delivery promises for one event.
 *
 * The service does not sleep, retry or send. It returns a plan and counters;
 * the queue chapter is where a failed send becomes a retry.
 */
final class NotificationService
{
    private readonly Counters $counters;

    public function __construct(
        private readonly PreferenceStore $preferences,
        private readonly DedupStore $dedup,
        private readonly DigestBuffer $digest,
        ?Counters $counters = null,
    ) {
        $this->counters = $counters ?? new Counters();
    }

    public function notify(Notification $notification): NotificationResult
    {
        $this->counters->add('events');
        $preferences = $this->preferences->get($notification->tenantId, $notification->userId);

        if ($preferences->channels === []) {
            $this->counters->add('suppressedByPreferences');

            return new NotificationResult([], 0, 0, 1, 0);
        }

        $deliveries = [];
        $digested = 0;
        $suppressedDuplicates = 0;
        $channelIntents = 0;

        foreach ($preferences->channels as $channel) {
            ++$channelIntents;
            $this->counters->add('channelIntents');

            $key = implode("\0", [
                $notification->tenantId,
                $notification->userId,
                $channel->value,
                $notification->eventKey,
            ]);

            if (!$this->dedup->claim($key, $notification->createdAtMs)) {
                ++$suppressedDuplicates;
                $this->counters->add('suppressedDuplicates');

                continue;
            }

            if (!$notification->urgent && $preferences->quietHours?->contains($notification->createdAtMs) === true) {
                $deliveries[] = new Delivery(
                    tenantId: $notification->tenantId,
                    userId: $notification->userId,
                    channel: $channel,
                    eventKey: $notification->eventKey,
                    scheduledAtMs: $preferences->quietHours->nextAllowedMs($notification->createdAtMs),
                    status: DeliveryStatus::Deferred,
                );
                $this->counters->add('planned');
                $this->counters->add('deferred');

                continue;
            }

            if (!$notification->urgent && $preferences->digest) {
                $this->digest->add($notification, $channel);
                ++$digested;
                $this->counters->add('digested');

                continue;
            }

            $deliveries[] = new Delivery(
                tenantId: $notification->tenantId,
                userId: $notification->userId,
                channel: $channel,
                eventKey: $notification->eventKey,
                scheduledAtMs: $notification->createdAtMs,
                status: DeliveryStatus::Immediate,
            );
            $this->counters->add('planned');
            $this->counters->add('immediate');
        }

        return new NotificationResult(
            deliveries: $deliveries,
            digested: $digested,
            suppressedDuplicates: $suppressedDuplicates,
            suppressedByPreferences: 0,
            channelIntents: $channelIntents,
        );
    }

    public function counters(): Counters
    {
        return $this->counters;
    }
}
