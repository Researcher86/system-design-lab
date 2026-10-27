<?php

declare(strict_types=1);

namespace SystemDesignLab\Tests\Notification;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SystemDesignLab\Notification\Channel;
use SystemDesignLab\Notification\DeliveryStatus;
use SystemDesignLab\Notification\DigestBuffer;
use SystemDesignLab\Notification\InMemoryDedupStore;
use SystemDesignLab\Notification\InMemoryPreferenceStore;
use SystemDesignLab\Notification\Notification;
use SystemDesignLab\Notification\NotificationService;
use SystemDesignLab\Notification\QuietHours;
use SystemDesignLab\Notification\UserPreferences;

final class NotificationServiceTest extends TestCase
{
    public function testChannelFirstReturnsAPrefix(): void
    {
        self::assertSame([], Channel::first(0));
        self::assertSame([Channel::Email], Channel::first(1));
        self::assertSame([Channel::Email, Channel::Push, Channel::Sms], Channel::first(3));
    }

    public function testChannelFirstRejectsAnImpossibleCount(): void
    {
        $this->expectException(InvalidArgumentException::class);
        Channel::first(4);
    }

    public function testPreferencesRejectDuplicateChannels(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new UserPreferences([Channel::Email, Channel::Email]);
    }

    public function testPreferencesAllowOnlyTheConfiguredChannels(): void
    {
        $preferences = new UserPreferences([Channel::Email, Channel::Sms]);

        self::assertTrue($preferences->allows(Channel::Email));
        self::assertTrue($preferences->allows(Channel::Sms));
        self::assertFalse($preferences->allows(Channel::Push));
        self::assertFalse(UserPreferences::none()->allows(Channel::Email));
        self::assertCount(3, UserPreferences::all()->channels);
    }

    public function testPreferenceStoreReturnsTheDefaultForAMissingUser(): void
    {
        $store = new InMemoryPreferenceStore(UserPreferences::all());
        $store->set('tenant-a', 'user-1', new UserPreferences([Channel::Email]));

        self::assertSame([Channel::Email], $store->get('tenant-a', 'user-1')->channels);
        self::assertCount(3, $store->get('tenant-a', 'user-2')->channels);
        self::assertSame(1, $store->count());
    }

    public function testQuietHoursDetectTheirWindow(): void
    {
        $quiet = new QuietHours(22, 8);

        self::assertTrue($quiet->contains($this->hour(22)));
        self::assertTrue($quiet->contains($this->hour(23)));
        self::assertTrue($quiet->contains($this->hour(0)));
        self::assertTrue($quiet->contains($this->hour(7)));
        self::assertFalse($quiet->contains($this->hour(8)));
        self::assertFalse($quiet->contains($this->hour(21)));
    }

    public function testQuietHoursMoveToTheEndOfTheWindow(): void
    {
        $quiet = new QuietHours(22, 8);

        self::assertSame($this->hour(32), $quiet->nextAllowedMs($this->hour(22)));
        self::assertSame($this->hour(32), $quiet->nextAllowedMs($this->hour(23)));
        self::assertSame($this->hour(8), $quiet->nextAllowedMs($this->hour(3)));
        self::assertSame($this->hour(9), $quiet->nextAllowedMs($this->hour(9)));
    }

    public function testQuietHoursHandleASameDayWindow(): void
    {
        $quiet = new QuietHours(9, 17);

        self::assertTrue($quiet->contains($this->hour(9)));
        self::assertTrue($quiet->contains($this->hour(16)));
        self::assertFalse($quiet->contains($this->hour(17)));
        self::assertSame($this->hour(17), $quiet->nextAllowedMs($this->hour(9)));
    }

    public function testEqualQuietHoursAreDisabled(): void
    {
        $quiet = new QuietHours(8, 8);

        self::assertFalse($quiet->enabled());
        self::assertFalse($quiet->contains($this->hour(8)));
        self::assertSame($this->hour(8), $quiet->nextAllowedMs($this->hour(8)));
    }

    public function testQuietHoursRejectInvalidHours(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new QuietHours(24, 8);
    }

    public function testDedupClaimsAKeyOnceInsideTheTtl(): void
    {
        $store = new InMemoryDedupStore(ttlMs: 1_000, cleanupInterval: 0);

        self::assertTrue($store->claim('key', 0));
        self::assertFalse($store->claim('key', 500));
        self::assertTrue($store->claim('key', 1_000));
        self::assertSame(3, $store->counters()->get('claims'));
        self::assertSame(2, $store->counters()->get('accepted'));
        self::assertSame(1, $store->counters()->get('suppressed'));
    }

    public function testDedupPurgeRemovesExpiredKeys(): void
    {
        $store = new InMemoryDedupStore(ttlMs: 1_000, cleanupInterval: 0);
        $store->claim('a', 0);
        $store->claim('b', 500);

        self::assertSame(1, $store->purge(1_000));
        self::assertSame(1, $store->size());
        self::assertSame(1, $store->counters()->get('purged'));
    }

    public function testDedupWithAZeroTtlDisablesSuppression(): void
    {
        $store = new InMemoryDedupStore(0, cleanupInterval: 0);

        self::assertTrue($store->claim('key', 0));
        self::assertTrue($store->claim('key', 0));
        self::assertSame(0, $store->size());
        self::assertSame(2, $store->counters()->get('accepted'));
        self::assertSame(0, $store->counters()->get('suppressed'));
    }

    public function testDedupRejectsANegativeTtl(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new InMemoryDedupStore(-1);
    }

    public function testDigestGroupsByUserChannelAndDay(): void
    {
        $buffer = new DigestBuffer(dayMs: 1_000);
        $buffer->add($this->notification('a', 100), Channel::Email);
        $buffer->add($this->notification('b', 200), Channel::Email);
        $buffer->add($this->notification('c', 1_500), Channel::Email);
        $buffer->add($this->notification('d', 100), Channel::Push);

        self::assertSame(3, $buffer->size());
        self::assertSame(4, $buffer->itemCount());

        $due = $buffer->due(1_000);

        self::assertCount(2, $due);
        self::assertSame(1, $buffer->size());
        self::assertSame(3, $buffer->counters()->get('flushedItems'));
        self::assertSame(2, $buffer->counters()->get('digests'));
    }

    public function testDigestFlushAllIncludesTheCurrentDay(): void
    {
        $buffer = new DigestBuffer(dayMs: 1_000);
        $buffer->add($this->notification('a', 100), Channel::Email);
        $buffer->add($this->notification('b', 1_100), Channel::Email);

        self::assertCount(1, $buffer->due(1_000));
        self::assertCount(1, $buffer->flushAll());
        self::assertSame(0, $buffer->size());
    }

    public function testNotificationRejectsEmptyIdentifiers(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new Notification('', 'tenant-a', 'user-1', 'title', 'body', 0);
    }

    public function testServiceFansOutToEveryEnabledChannel(): void
    {
        $service = $this->service(UserPreferences::all());

        $result = $service->notify($this->notification('event-1', 0));

        self::assertSame(3, $result->channelIntents);
        self::assertSame(3, $result->delivered());
        self::assertSame(3, $result->immediate());
        self::assertSame(0, $result->deferred());
        self::assertSame(3, $service->counters()->get('planned'));
        self::assertSame(3, $service->counters()->get('immediate'));
    }

    public function testServiceUsesOnlyTheConfiguredChannels(): void
    {
        $service = $this->service(new UserPreferences([Channel::Email, Channel::Sms]));

        $result = $service->notify($this->notification('event-1', 0));

        self::assertSame(2, $result->delivered());
        self::assertSame(Channel::Email, $result->deliveries[0]->channel);
        self::assertSame(Channel::Sms, $result->deliveries[1]->channel);
    }

    public function testServiceSuppressesAnEventWithoutChannels(): void
    {
        $service = $this->service(UserPreferences::none());

        $result = $service->notify($this->notification('event-1', 0));

        self::assertSame(0, $result->channelIntents);
        self::assertSame(0, $result->delivered());
        self::assertSame(1, $result->suppressedByPreferences);
        self::assertSame(1, $service->counters()->get('suppressedByPreferences'));
    }

    public function testDuplicateEventIsSuppressedPerChannel(): void
    {
        $service = $this->service(UserPreferences::all());
        $notification = $this->notification('event-1', 0);

        self::assertSame(3, $service->notify($notification)->delivered());

        $duplicate = $service->notify($notification);

        self::assertSame(0, $duplicate->delivered());
        self::assertSame(3, $duplicate->suppressedDuplicates);
        self::assertSame(3, $service->counters()->get('suppressedDuplicates'));
    }

    public function testDedupIsScopedByTenantUserAndChannel(): void
    {
        $service = $this->service(new UserPreferences([Channel::Email]));

        self::assertSame(1, $service->notify($this->notification('event-1', 0, user: 'user-1'))->delivered());
        self::assertSame(1, $service->notify($this->notification('event-1', 0, user: 'user-2'))->delivered());
        self::assertSame(1, $service->notify($this->notification('event-1', 0, tenant: 'tenant-b'))->delivered());
        self::assertSame(0, $service->notify($this->notification('event-1', 0, user: 'user-1'))->delivered());
    }

    public function testDedupExpiresAfterTheTtl(): void
    {
        $service = $this->service(
            new UserPreferences([Channel::Email]),
            dedup: new InMemoryDedupStore(ttlMs: 1_000, cleanupInterval: 0),
        );

        self::assertSame(1, $service->notify($this->notification('event-1', 0))->delivered());
        self::assertSame(0, $service->notify($this->notification('event-1', 500))->delivered());
        self::assertSame(1, $service->notify($this->notification('event-1', 1_000))->delivered());
    }

    public function testQuietHoursDeferANonUrgentMessage(): void
    {
        $service = $this->service(new UserPreferences(
            [Channel::Email],
            quietHours: new QuietHours(22, 8),
        ));

        $result = $service->notify($this->notification('event-1', $this->hour(23)));

        self::assertSame(0, $result->immediate());
        self::assertSame(1, $result->deferred());
        self::assertSame($this->hour(32), $result->deliveries[0]->scheduledAtMs);
        self::assertSame(DeliveryStatus::Deferred, $result->deliveries[0]->status);
        self::assertSame(1, $service->counters()->get('deferred'));
    }

    public function testUrgentMessageBypassesQuietHours(): void
    {
        $service = $this->service(new UserPreferences(
            [Channel::Email],
            quietHours: new QuietHours(22, 8),
        ));

        $result = $service->notify($this->notification('event-1', $this->hour(23), urgent: true));

        self::assertSame(1, $result->immediate());
        self::assertSame(0, $result->deferred());
        self::assertSame($this->hour(23), $result->deliveries[0]->scheduledAtMs);
    }

    public function testDigestBatchesNonUrgentMessages(): void
    {
        $buffer = new DigestBuffer();
        $service = $this->service(new UserPreferences([Channel::Email], digest: true), digest: $buffer);

        for ($i = 0; $i < 3; ++$i) {
            $result = $service->notify($this->notification('event-' . $i, 100 + $i));
            self::assertSame(0, $result->delivered());
            self::assertSame(1, $result->digested);
        }

        self::assertSame(1, $buffer->size());
        self::assertSame(3, $buffer->itemCount());
        self::assertSame(3, $service->counters()->get('digested'));

        $due = $buffer->due(QuietHours::DAY_MS);

        self::assertCount(1, $due);
        self::assertSame(3, $due[0]->count());
        self::assertSame(0, $due[0]->dayStartMs);
    }

    public function testUrgentMessageBypassesDigest(): void
    {
        $buffer = new DigestBuffer();
        $service = $this->service(new UserPreferences([Channel::Email], digest: true), digest: $buffer);

        $result = $service->notify($this->notification('event-1', 100, urgent: true));

        self::assertSame(1, $result->immediate());
        self::assertSame(0, $buffer->size());
    }

    public function testQuietHoursWinOverDigest(): void
    {
        $buffer = new DigestBuffer();
        $service = $this->service(
            new UserPreferences(
                [Channel::Email],
                digest: true,
                quietHours: new QuietHours(22, 8),
            ),
            digest: $buffer,
        );

        $result = $service->notify($this->notification('event-1', $this->hour(23)));

        self::assertSame(1, $result->deferred());
        self::assertSame(0, $buffer->size());
    }

    public function testDedupRunsBeforeQuietHours(): void
    {
        $service = $this->service(new UserPreferences(
            [Channel::Email],
            quietHours: new QuietHours(22, 8),
        ));
        $notification = $this->notification('event-1', $this->hour(23));

        self::assertSame(1, $service->notify($notification)->deferred());
        self::assertSame(0, $service->notify($notification)->deferred());
        self::assertSame(1, $service->counters()->get('suppressedDuplicates'));
    }

    /**
     * @return iterable<string, array{string, string, string, int}>
     */
    public static function invalidNotifications(): iterable
    {
        yield 'empty key' => ['', 'tenant-a', 'user-1', 0];
        yield 'blank tenant' => ['event-1', ' ', 'user-1', 0];
        yield 'empty user' => ['event-1', 'tenant-a', '', 0];
        yield 'negative timestamp' => ['event-1', 'tenant-a', 'user-1', -1];
    }

    #[DataProvider('invalidNotifications')]
    public function testNotificationRejectsInvalidValues(
        string $eventKey,
        string $tenantId,
        string $userId,
        int $createdAtMs,
    ): void {
        $this->expectException(InvalidArgumentException::class);
        new Notification($eventKey, $tenantId, $userId, 'title', 'body', $createdAtMs);
    }

    private function service(
        UserPreferences $preferences,
        ?InMemoryDedupStore $dedup = null,
        ?DigestBuffer $digest = null,
    ): NotificationService {
        $store = new InMemoryPreferenceStore();
        $store->set('tenant-a', 'user-1', $preferences);
        $store->set('tenant-a', 'user-2', $preferences);
        $store->set('tenant-b', 'user-1', $preferences);

        return new NotificationService(
            $store,
            $dedup ?? new InMemoryDedupStore(ttlMs: 60_000, cleanupInterval: 0),
            $digest ?? new DigestBuffer(),
        );
    }

    private function notification(
        string $eventKey,
        int $createdAtMs,
        string $tenant = 'tenant-a',
        string $user = 'user-1',
        bool $urgent = false,
    ): Notification {
        return new Notification(
            eventKey: $eventKey,
            tenantId: $tenant,
            userId: $user,
            title: 'Title',
            body: 'Body',
            createdAtMs: $createdAtMs,
            urgent: $urgent,
        );
    }

    private function hour(int $hour): int
    {
        return $hour * QuietHours::HOUR_MS;
    }
}
