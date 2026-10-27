<?php

declare(strict_types=1);

use SystemDesignLab\Experiment\Experiment;
use SystemDesignLab\Experiment\Options;
use SystemDesignLab\Experiment\Output;
use SystemDesignLab\Webhook\Subscription;
use SystemDesignLab\Webhook\WebhookDispatcher;
use SystemDesignLab\Webhook\WebhookEvent;

/**
 * One event is not one webhook.
 *
 * Each subscription chooses event types. The dispatcher creates one delivery
 * per matching tenant and type, so the fan-out factor is the number of
 * interested subscriptions, not the number of registered subscriptions.
 */
return new Experiment(
    name: 'webhook:fanout',
    description: 'fan-out from events to subscriptions filtered by event type',
    supports: ['events', 'subscriptions'],
    run: static function (Options $options, Output $output): void {
        $events = $options->int('events', 10_000);
        $subscriptions = $options->int('subscriptions', 50);
        $types = ['link.created', 'link.clicked', 'link.deleted'];
        $dispatcher = new WebhookDispatcher();

        for ($index = 0; $index < $subscriptions; ++$index) {
            $wanted = [];

            if ($index % 2 === 0) {
                $wanted[] = 'link.created';
            }

            if ($index % 3 === 0) {
                $wanted[] = 'link.clicked';
            }

            if ($index % 5 === 0) {
                $wanted[] = 'link.deleted';
            }

            if ($wanted === []) {
                $wanted[] = 'link.deleted';
            }

            $dispatcher->subscribe(new Subscription(
                'sub-' . $index,
                'tenant-a',
                'https://example.test/hook-' . $index,
                'secret-' . $index,
                $wanted,
            ));
        }

        $output->heading(
            'Webhook fan-out',
            sprintf('%s events, %d subscriptions across %d event types.', number_format($events), $subscriptions, count($types)),
        );
        $output->line();

        /** @var array<string, int> $eventCounts */
        $eventCounts = array_fill_keys($types, 0);
        /** @var array<string, int> $deliveryCounts */
        $deliveryCounts = array_fill_keys($types, 0);
        /** @var array<string, int> $subscriptionCounts */
        $subscriptionCounts = array_fill_keys($types, 0);

        foreach ($types as $type) {
            for ($index = 0; $index < $subscriptions; ++$index) {
                $wanted = [];

                if ($index % 2 === 0) {
                    $wanted[] = 'link.created';
                }

                if ($index % 3 === 0) {
                    $wanted[] = 'link.clicked';
                }

                if ($index % 5 === 0) {
                    $wanted[] = 'link.deleted';
                }

                if ($wanted === []) {
                    $wanted[] = 'link.deleted';
                }

                if (in_array($type, $wanted, true)) {
                    ++$subscriptionCounts[$type];
                }
            }
        }

        for ($index = 0; $index < $events; ++$index) {
            $type = $types[$index % count($types)];
            ++$eventCounts[$type];
            $deliveries = $dispatcher->dispatch(new WebhookEvent(
                id: 'event-' . $index,
                tenantId: 'tenant-a',
                type: $type,
                payload: '{"id":"link-' . $index . '"}',
                createdAtMs: 0,
            ));
            $deliveryCounts[$type] += count($deliveries);
        }

        $rows = [];

        foreach ($types as $type) {
            $rows[] = [
                $type,
                number_format($eventCounts[$type]),
                number_format($subscriptionCounts[$type]),
                number_format($deliveryCounts[$type]),
                sprintf('%.2f', $eventCounts[$type] === 0 ? 0.0 : $deliveryCounts[$type] / $eventCounts[$type]),
            ];
            $output->measure('deliveries_' . str_replace('.', '_', $type), $deliveryCounts[$type]);
        }

        $totalDeliveries = $dispatcher->counters()->get('deliveriesCreated');
        $rows[] = [
            'total',
            number_format($events),
            number_format($subscriptions),
            number_format($totalDeliveries),
            sprintf('%.2f', $events === 0 ? 0.0 : $totalDeliveries / $events),
        ];
        $output->table(
            ['event type', 'events', 'interested subscriptions', 'deliveries', 'deliveries/event'],
            $rows,
        );
        $output->measure('deliveries_total', $totalDeliveries);
        $output->measure('fanout_per_event', $totalDeliveries / $events);
        $output->line();
        $output->line('Fan-out is the count of interested subscriptions, not all subscriptions.');
        $output->line('An event type filter cuts delivery volume without changing the event stream.');
        $output->line('Each delivery still needs a signature, an attempt and a retry budget.');
        $output->line('A broad subscription list multiplies every downstream cost.');
    },
);
