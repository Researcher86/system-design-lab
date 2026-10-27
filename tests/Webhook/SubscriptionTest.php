<?php

declare(strict_types=1);

namespace SystemDesignLab\Tests\Webhook;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use SystemDesignLab\Webhook\Subscription;

final class SubscriptionTest extends TestCase
{
    public function testEmptyEventTypesMeanAllEvents(): void
    {
        $subscription = $this->subscription();

        self::assertTrue($subscription->wants('link.created'));
        self::assertTrue($subscription->wants('anything.else'));
    }

    public function testExplicitEventTypesAreFiltered(): void
    {
        $subscription = $this->subscription(['link.created', 'link.deleted']);

        self::assertTrue($subscription->wants('link.created'));
        self::assertTrue($subscription->wants('link.deleted'));
        self::assertFalse($subscription->wants('link.clicked'));
    }

    public function testDuplicateEventTypesAreNormalized(): void
    {
        $subscription = $this->subscription(['link.created', 'link.created', ' link.created ']);

        self::assertSame(['link.created'], $subscription->eventTypes);
    }

    public function testEmptyEventTypeIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->subscription(['link.created', ' ']);
    }

    public function testEmptyIdIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new Subscription('', 'tenant-a', 'https://example.test/hook', 'secret');
    }

    public function testEmptySecretIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new Subscription('sub-1', 'tenant-a', 'https://example.test/hook', ' ');
    }

    public function testNonHttpUrlIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new Subscription('sub-1', 'tenant-a', 'ftp://example.test/hook', 'secret');
    }

    public function testInvalidUrlIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new Subscription('sub-1', 'tenant-a', 'not a url', 'secret');
    }

    /**
     * @param list<string> $eventTypes
     */
    private function subscription(array $eventTypes = []): Subscription
    {
        return new Subscription('sub-1', 'tenant-a', 'https://example.test/hook', 'secret', $eventTypes);
    }
}
