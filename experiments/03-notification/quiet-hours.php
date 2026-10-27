<?php

declare(strict_types=1);

use SystemDesignLab\Experiment\Experiment;
use SystemDesignLab\Experiment\Options;
use SystemDesignLab\Experiment\Output;
use SystemDesignLab\Notification\Channel;
use SystemDesignLab\Notification\DeliveryStatus;
use SystemDesignLab\Notification\DigestBuffer;
use SystemDesignLab\Notification\InMemoryDedupStore;
use SystemDesignLab\Notification\InMemoryPreferenceStore;
use SystemDesignLab\Notification\Notification;
use SystemDesignLab\Notification\NotificationService;
use SystemDesignLab\Notification\QuietHours;
use SystemDesignLab\Notification\UserPreferences;

/**
 * What happens to a message that arrives inside a quiet window?
 *
 * The event stream is spread over 24 UTC hours. A quiet window defers
 * non-urgent messages to the end of the window; it does not drop them. The
 * table shows which hours produce immediate deliveries and how long the
 * deferred ones wait. The window may wrap midnight.
 */
return new Experiment(
    name: 'notify:quiet-hours',
    description: 'deferred and immediate deliveries across a 24-hour quiet window',
    supports: ['events', 'users', 'channels', 'quiet-start', 'quiet-end'],
    run: static function (Options $options, Output $output): void {
        $events = $options->int('events', 240);
        $users = $options->int('users', 10);
        $channels = Channel::first($options->int('channels', 1));
        $quiet = new QuietHours($options->int('quiet-start', 22), $options->int('quiet-end', 8));

        $preferences = new InMemoryPreferenceStore();

        for ($user = 0; $user < $users; ++$user) {
            $preferences->set(
                'tenant-a',
                'user-' . $user,
                new UserPreferences($channels, quietHours: $quiet),
            );
        }

        $service = new NotificationService(
            $preferences,
            new InMemoryDedupStore(ttlMs: 86_400_000, cleanupInterval: 0),
            new DigestBuffer(),
        );

        $output->heading(
            'Quiet hours',
            sprintf(
                '%s events across 24 hours; quiet %02d:00-%02d:00 UTC.',
                number_format($events),
                $quiet->startHour,
                $quiet->endHour,
            ),
        );
        $output->line();

        /** @var array<int, int> $hourEvents */
        $hourEvents = array_fill(0, 24, 0);
        /** @var array<int, int> $hourImmediate */
        $hourImmediate = array_fill(0, 24, 0);
        /** @var array<int, int> $hourDeferred */
        $hourDeferred = array_fill(0, 24, 0);
        /** @var array<int, int> $hourDelay */
        $hourDelay = array_fill(0, 24, 0);

        $totalImmediate = 0;
        $totalDeferred = 0;
        $totalDelay = 0;
        $maxDelay = 0;

        for ($i = 0; $i < $events; ++$i) {
            $hour = $i % 24;
            $createdAtMs = $hour * QuietHours::HOUR_MS + (intdiv($i, 24) % 60) * 60_000;
            $result = $service->notify(new Notification(
                eventKey: 'event-' . $i,
                tenantId: 'tenant-a',
                userId: 'user-' . ($i % $users),
                title: 'Title',
                body: 'Body',
                createdAtMs: $createdAtMs,
            ));

            ++$hourEvents[$hour];
            $totalImmediate += $result->immediate();
            $totalDeferred += $result->deferred();

            foreach ($result->deliveries as $delivery) {
                if ($delivery->status !== DeliveryStatus::Deferred) {
                    continue;
                }

                ++$hourDeferred[$hour];
                $delay = $delivery->delayFrom($createdAtMs);
                $hourDelay[$hour] += $delay;
                $totalDelay += $delay;
                $maxDelay = max($maxDelay, $delay);
            }

            $hourImmediate[$hour] += $result->immediate();
        }

        $rows = [];

        for ($hour = 0; $hour < 24; ++$hour) {
            $rows[] = [
                sprintf('%02d:00', $hour),
                $hourEvents[$hour],
                $hourImmediate[$hour],
                $hourDeferred[$hour],
                $hourDeferred[$hour] === 0
                    ? '-'
                    : sprintf('%.1f h', $hourDelay[$hour] / $hourDeferred[$hour] / QuietHours::HOUR_MS),
            ];
        }

        $output->table(['hour', 'events', 'immediate', 'deferred', 'average delay'], $rows);
        $output->line();
        $output->measure('immediate', $totalImmediate);
        $output->measure('deferred', $totalDeferred);
        $output->measure('average_deferred_delay_ms', $totalDeferred === 0 ? 0.0 : $totalDelay / $totalDeferred);
        $output->measure('max_deferred_delay_ms', $maxDelay);
        $output->line(sprintf(
            'Immediate %s, deferred %s, average deferred delay %.1f hours.',
            number_format($totalImmediate),
            number_format($totalDeferred),
            $totalDeferred === 0 ? 0.0 : $totalDelay / $totalDeferred / QuietHours::HOUR_MS,
        ));
        $output->line('A deferred message is not lost; it is scheduled for the first allowed instant.');
        $output->line('The window is exclusive at the end: the end hour is already allowed.');
        $output->line('An urgent message bypasses the window, but it still goes through deduplication.');
    },
);
