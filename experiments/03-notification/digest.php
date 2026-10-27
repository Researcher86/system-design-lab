<?php

declare(strict_types=1);

use SystemDesignLab\Experiment\Experiment;
use SystemDesignLab\Experiment\Options;
use SystemDesignLab\Experiment\Output;
use SystemDesignLab\Notification\Channel;
use SystemDesignLab\Notification\DigestBuffer;
use SystemDesignLab\Notification\InMemoryDedupStore;
use SystemDesignLab\Notification\InMemoryPreferenceStore;
use SystemDesignLab\Notification\Notification;
use SystemDesignLab\Notification\NotificationService;
use SystemDesignLab\Notification\QuietHours;
use SystemDesignLab\Notification\UserPreferences;

/**
 * What does batching buy, and what does it postpone?
 *
 * The same event stream is planned twice: immediate delivery and daily digest.
 * Immediate delivery produces one message per event per channel. Digest
 * produces one message per user, channel and day, holding every event until
 * the next day. The difference is message count, not event count.
 */
return new Experiment(
    name: 'notify:digest',
    description: 'message count for immediate delivery versus a daily digest',
    supports: ['events', 'users', 'channels', 'day-ms'],
    run: static function (Options $options, Output $output): void {
        $events = $options->int('events', 10_000);
        $users = $options->int('users', 1_000);
        $channels = Channel::first($options->int('channels', 2));
        $dayMs = $options->int('day-ms', QuietHours::DAY_MS);
        $notifications = [];

        for ($i = 0; $i < $events; ++$i) {
            $notifications[] = new Notification(
                eventKey: 'event-' . $i,
                tenantId: 'tenant-a',
                userId: 'user-' . ($i % $users),
                title: 'Title',
                body: 'Body',
                createdAtMs: intdiv($dayMs, 2),
            );
        }

        $output->heading(
            'Notification digest',
            sprintf(
                '%s events over %s users, %d channels, one %d ms day.',
                number_format($events),
                number_format($users),
                count($channels),
                $dayMs,
            ),
        );
        $output->line();

        $immediate = notificationDigestService($users, $channels, digest: false);
        $immediateMessages = 0;
        $immediateIntents = 0;

        foreach ($notifications as $notification) {
            $result = $immediate->notify($notification);
            $immediateMessages += $result->delivered();
            $immediateIntents += $result->channelIntents;
        }

        $buffer = new DigestBuffer($dayMs);
        $digest = notificationDigestService($users, $channels, digest: true, buffer: $buffer);
        $digestIntents = 0;

        foreach ($notifications as $notification) {
            $digestIntents += $digest->notify($notification)->channelIntents;
        }

        $batches = $buffer->due($dayMs);
        $digestMessages = count($batches);
        $digestItems = 0;

        foreach ($batches as $batch) {
            $digestItems += $batch->count();
        }

        $output->table(
            ['mode', 'events', 'channel intents', 'messages', 'items/message', 'messages/event'],
            [
                ['immediate', number_format($events), number_format($immediateIntents), number_format($immediateMessages), '1.0', sprintf('%.2f', $immediateMessages / $events)],
                ['digest', number_format($events), number_format($digestIntents), number_format($digestMessages), sprintf('%.1f', $digestItems / max(1, $digestMessages)), sprintf('%.4f', $digestMessages / $events)],
            ],
        );

        $output->measure('immediate_messages', $immediateMessages);
        $output->measure('immediate_intents', $immediateIntents);
        $output->measure('digest_messages', $digestMessages);
        $output->measure('digest_items', $digestItems);
        $output->measure('digest_intents', $digestIntents);
        $output->measure('messages_saved', $immediateMessages - $digestMessages);

        $output->line();
        $output->line('Batching trades latency for message count.');
        $output->line('The digest still has to hold every event until the day boundary.');
        $output->line('The message count is bounded by users x channels x days, not by events.');
        $output->line('An urgent notification bypasses the digest; the service returns it immediately.');
    },
);

/**
 * @param list<Channel> $channels
 */
function notificationDigestService(
    int $users,
    array $channels,
    bool $digest,
    ?DigestBuffer $buffer = null,
): NotificationService {
    $preferences = new InMemoryPreferenceStore();

    for ($user = 0; $user < $users; ++$user) {
        $preferences->set('tenant-a', 'user-' . $user, new UserPreferences($channels, digest: $digest));
    }

    return new NotificationService(
        $preferences,
        new InMemoryDedupStore(ttlMs: 86_400_000, cleanupInterval: 0),
        $buffer ?? new DigestBuffer(),
    );
}
