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
 * One event is not one message.
 *
 * Adding a channel multiplies the work: every enabled channel is an
 * independent preference, dedup key and delivery. The experiment holds the
 * event count fixed and adds channels one at a time, so the amplification is
 * visible instead of being buried in a single "notifications sent" number.
 */
return new Experiment(
    name: 'notify:fanout',
    description: 'channel intents and deliveries as each channel is enabled',
    supports: ['events', 'users', 'channels'],
    run: static function (Options $options, Output $output): void {
        $events = $options->int('events', 10_000);
        $users = $options->int('users', 1_000);
        $maxChannels = $options->int('channels', 3);

        if ($maxChannels > count(Channel::cases())) {
            throw new InvalidArgumentException(sprintf(
                'This experiment has %d channels, got %d',
                count(Channel::cases()),
                $maxChannels,
            ));
        }

        $output->heading(
            'Notification fan-out',
            sprintf('%s events over %s users; each event targets one user.', number_format($events), number_format($users)),
        );
        $output->line();

        $rows = [];

        for ($count = 1; $count <= $maxChannels; ++$count) {
            $channels = Channel::first($count);
            $preferences = new InMemoryPreferenceStore();

            for ($user = 0; $user < $users; ++$user) {
                $preferences->set('tenant-a', 'user-' . $user, new UserPreferences($channels));
            }

            $service = new NotificationService(
                $preferences,
                new InMemoryDedupStore(ttlMs: 86_400_000, cleanupInterval: 0),
                new DigestBuffer(),
            );

            for ($i = 0; $i < $events; ++$i) {
                $service->notify(new Notification(
                    eventKey: 'event-' . $i,
                    tenantId: 'tenant-a',
                    userId: 'user-' . ($i % $users),
                    title: 'Title',
                    body: 'Body',
                    createdAtMs: $i,
                ));
            }

            $intents = $service->counters()->get('channelIntents');
            $deliveries = $service->counters()->get('immediate');

            $rows[] = [
                $count,
                number_format($events),
                number_format($intents),
                number_format($deliveries),
                sprintf('%.1f', $intents / $events),
            ];
            $output->measure('channels_' . $count . '_intents', $intents);
            $output->measure('channels_' . $count . '_deliveries', $deliveries);
        }

        $output->table(
            ['channels', 'events', 'channel intents', 'deliveries', 'intents/event'],
            $rows,
        );
        $output->line();
        $output->line('The event count is constant; the message count is not.');
        $output->line('Each channel has its own preference check, dedup claim and delivery.');
        $output->line('A digest or a quiet window can collapse this fan-out later, but it cannot make the intent free.');
    },
);
