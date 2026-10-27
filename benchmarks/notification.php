<?php

declare(strict_types=1);

use SystemDesignLab\Benchmark\Benchmark;
use SystemDesignLab\Notification\Channel;
use SystemDesignLab\Notification\DigestBuffer;
use SystemDesignLab\Notification\InMemoryDedupStore;
use SystemDesignLab\Notification\InMemoryPreferenceStore;
use SystemDesignLab\Notification\Notification;
use SystemDesignLab\Notification\NotificationService;
use SystemDesignLab\Notification\QuietHours;
use SystemDesignLab\Notification\UserPreferences;

$preferences = new InMemoryPreferenceStore();
$preferences->set('tenant-a', 'user-1', UserPreferences::all());
$preferences->set('tenant-a', 'user-2', new UserPreferences([Channel::Email]));

$dedupNew = new InMemoryDedupStore(ttlMs: 86_400_000, cleanupInterval: 0);
$dedupDuplicate = new InMemoryDedupStore(ttlMs: 86_400_000, cleanupInterval: 0);
$dedupDuplicate->claim('duplicate', 0);

$quiet = new QuietHours(22, 8);

$digestBuffer = new DigestBuffer();
$digestNotification = new Notification('digest', 'tenant-a', 'user-1', 'Title', 'Body', 100);

$oneChannel = new NotificationService(
    $preferences,
    new InMemoryDedupStore(ttlMs: 86_400_000, cleanupInterval: 0),
    new DigestBuffer(),
);
$threeChannels = new NotificationService(
    $preferences,
    new InMemoryDedupStore(ttlMs: 86_400_000, cleanupInterval: 0),
    new DigestBuffer(),
);
$duplicateService = new NotificationService(
    new InMemoryPreferenceStore(UserPreferences::all()),
    $dedupDuplicate,
    new DigestBuffer(),
);
$duplicateNotification = new Notification('duplicate', 'tenant-a', 'user-1', 'Title', 'Body', 0);
$duplicateService->notify($duplicateNotification);

return [
    new Benchmark(
        name: 'preference lookup',
        iterations: 100_000,
        run: static fn (): UserPreferences => $preferences->get('tenant-a', 'user-1'),
    ),
    new Benchmark(
        name: 'dedup new key',
        iterations: 100_000,
        run: static function () use ($dedupNew): bool {
            static $index = 0;

            return $dedupNew->claim('key-' . $index++, 0);
        },
    ),
    new Benchmark(
        name: 'dedup duplicate',
        iterations: 100_000,
        run: static fn (): bool => $dedupDuplicate->claim('duplicate', 0),
    ),
    new Benchmark(
        name: 'quiet hours contains',
        iterations: 100_000,
        run: static fn (): bool => $quiet->contains(23 * QuietHours::HOUR_MS),
    ),
    new Benchmark(
        name: 'digest add',
        iterations: 20_000,
        run: static function () use ($digestBuffer, $digestNotification): void {
            $digestBuffer->add($digestNotification, Channel::Email);
        },
    ),
    new Benchmark(
        name: 'notify one channel',
        iterations: 20_000,
        run: static function () use ($oneChannel): mixed {
            static $index = 0;

            return $oneChannel->notify(new Notification(
                eventKey: 'one-' . $index++,
                tenantId: 'tenant-a',
                userId: 'user-2',
                title: 'Title',
                body: 'Body',
                createdAtMs: 0,
            ));
        },
    ),
    new Benchmark(
        name: 'notify three channels',
        iterations: 20_000,
        run: static function () use ($threeChannels): mixed {
            static $index = 0;

            return $threeChannels->notify(new Notification(
                eventKey: 'three-' . $index++,
                tenantId: 'tenant-a',
                userId: 'user-1',
                title: 'Title',
                body: 'Body',
                createdAtMs: 0,
            ));
        },
    ),
    new Benchmark(
        name: 'notify duplicate',
        iterations: 100_000,
        run: static fn (): mixed => $duplicateService->notify($duplicateNotification),
    ),
];
