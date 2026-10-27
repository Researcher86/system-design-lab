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
use SystemDesignLab\Notification\UserPreferences;

/**
 * How many duplicate intents does a TTL suppress, and when does it stop?
 *
 * The same event id is replayed inside the TTL and again after it. A duplicate
 * is suppressed per channel, not per event; the second phase should produce no
 * messages, while the third phase should produce exactly one more message per
 * channel. The dedup map keeps one expiry per key, so its size tracks keys,
 * not attempts.
 */
return new Experiment(
    name: 'notify:dedup',
    description: 'duplicate suppression inside a TTL and delivery after it expires',
    supports: ['events', 'duplicates', 'users', 'channels', 'ttl-ms'],
    run: static function (Options $options, Output $output): void {
        $events = $options->int('events', 10_000);
        $duplicates = $options->int('duplicates', 5_000);
        $users = $options->int('users', 1_000);
        $channels = Channel::first($options->int('channels', 2));
        $ttlMs = $options->int('ttl-ms', 60_000);

        $preferences = new InMemoryPreferenceStore();

        foreach (range(0, $users - 1) as $user) {
            $preferences->set('tenant-a', 'user-' . $user, new UserPreferences($channels));
        }

        $dedup = new InMemoryDedupStore(ttlMs: $ttlMs, cleanupInterval: 0);
        $service = new NotificationService($preferences, $dedup, new DigestBuffer());

        $output->heading(
            'Notification deduplication',
            sprintf(
                '%s events, %s duplicates, %d channels, TTL %d ms.',
                number_format($events),
                number_format($duplicates),
                count($channels),
                $ttlMs,
            ),
        );
        $output->line();

        $phases = [
            'unique events' => ['count' => $events, 'at' => 0],
            'duplicates inside TTL' => ['count' => $duplicates, 'at' => max(0, $ttlMs - 1)],
            'duplicates after TTL' => ['count' => $duplicates, 'at' => $ttlMs],
        ];
        $rows = [];

        foreach ($phases as $name => $phase) {
            $delivered = 0;
            $suppressed = 0;
            $intents = 0;

            for ($i = 0; $i < $phase['count']; ++$i) {
                $event = $i % $events;
                $result = $service->notify(new Notification(
                    eventKey: 'event-' . $event,
                    tenantId: 'tenant-a',
                    userId: 'user-' . ($event % $users),
                    title: 'Title',
                    body: 'Body',
                    createdAtMs: $phase['at'],
                ));
                $delivered += $result->delivered();
                $suppressed += $result->suppressedDuplicates;
                $intents += $result->channelIntents;
            }

            $rows[] = [
                $name,
                number_format($phase['count']),
                number_format($intents),
                number_format($delivered),
                number_format($suppressed),
                sprintf('%.1f%%', $intents === 0 ? 0.0 : $suppressed / $intents * 100),
            ];
            $output->measure(str_replace(' ', '_', $name) . '_delivered', $delivered);
            $output->measure(str_replace(' ', '_', $name) . '_suppressed', $suppressed);
        }

        $output->table(
            ['phase', 'attempts', 'intents', 'delivered', 'suppressed', 'suppressed share'],
            $rows,
        );
        $output->line();
        $output->line(sprintf('Dedup keys retained: %s (one per event and channel, not one per attempt).', number_format($dedup->size())));
        $output->line(sprintf(
            'Dedup counters: claims %s, accepted %s, suppressed %s.',
            number_format($dedup->counters()->get('claims')),
            number_format($dedup->counters()->get('accepted')),
            number_format($dedup->counters()->get('suppressed')),
        ));
        $output->line('The TTL starts at the first claim and a duplicate does not extend it.');
        $output->line('At zero TTL the store disables suppression; the same replay is delivered every time.');
    },
);
