<?php

declare(strict_types=1);

namespace SystemDesignLab\Tests\Webhook;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use SystemDesignLab\Webhook\Subscription;
use SystemDesignLab\Webhook\WebhookDelivery;
use SystemDesignLab\Webhook\WebhookDispatcher;
use SystemDesignLab\Webhook\WebhookEvent;

final class WebhookDispatcherTest extends TestCase
{
    public function testEventFansOutToMatchingSubscriptions(): void
    {
        $dispatcher = new WebhookDispatcher();
        $dispatcher->subscribe($this->subscription('sub-a', 'tenant-a', 'link.created'));
        $dispatcher->subscribe($this->subscription('sub-b', 'tenant-a', 'link.deleted'));
        $dispatcher->subscribe($this->subscription('sub-c', 'tenant-b', 'link.created'));
        $dispatcher->subscribe($this->subscription('sub-d', 'tenant-a'));

        $deliveries = $dispatcher->dispatch(new WebhookEvent('event-1', 'tenant-a', 'link.created', '{}'));

        self::assertCount(2, $deliveries);
        self::assertSame(['tenant-a:event-1:sub-a', 'tenant-a:event-1:sub-d'], array_map(
            static fn (WebhookDelivery $delivery): string => $delivery->id,
            $deliveries,
        ));
        self::assertSame(1, $dispatcher->counters()->get('events'));
        self::assertSame(2, $dispatcher->counters()->get('deliveriesCreated'));
    }

    public function testAnEmptyEventTypeListMeansEverything(): void
    {
        $dispatcher = new WebhookDispatcher();
        $dispatcher->subscribe($this->subscription('sub-a', 'tenant-a'));

        self::assertCount(1, $dispatcher->dispatch(new WebhookEvent('event-1', 'tenant-a', 'anything', '{}')));
    }

    public function testNoMatchProducesNoDelivery(): void
    {
        $dispatcher = new WebhookDispatcher();
        $dispatcher->subscribe($this->subscription('sub-a', 'tenant-a', 'link.created'));

        self::assertSame([], $dispatcher->dispatch(new WebhookEvent('event-1', 'tenant-a', 'link.deleted', '{}')));
        self::assertSame(1, $dispatcher->counters()->get('events'));
        self::assertSame(0, $dispatcher->counters()->get('deliveriesCreated'));
    }

    public function testSubscriptionIdCannotBeReused(): void
    {
        $dispatcher = new WebhookDispatcher();
        $dispatcher->subscribe($this->subscription('sub-a', 'tenant-a'));

        $this->expectException(InvalidArgumentException::class);
        $dispatcher->subscribe($this->subscription('sub-a', 'tenant-a'));
    }

    public function testDeliveryIdsAreStableAcrossDispatchCalls(): void
    {
        $dispatcher = new WebhookDispatcher();
        $dispatcher->subscribe($this->subscription('sub-a', 'tenant-a'));
        $event = new WebhookEvent('event-1', 'tenant-a', 'link.created', '{}');

        self::assertSame($dispatcher->dispatch($event)[0]->id, $dispatcher->dispatch($event)[0]->id);
        self::assertSame(2, $dispatcher->counters()->get('deliveriesCreated'));
    }

    public function testSubscriptionCount(): void
    {
        $dispatcher = new WebhookDispatcher();
        $dispatcher->subscribe($this->subscription('sub-a', 'tenant-a'));
        $dispatcher->subscribe($this->subscription('sub-b', 'tenant-a'));

        self::assertSame(2, $dispatcher->subscriptionCount());
    }

    private function subscription(string $id, string $tenantId, string ...$eventTypes): Subscription
    {
        return new Subscription($id, $tenantId, 'https://example.test/' . $id, 'secret', array_values($eventTypes));
    }
}
