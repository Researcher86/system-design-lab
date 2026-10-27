<?php

declare(strict_types=1);

namespace SystemDesignLab\Tests\Webhook;

use InvalidArgumentException;
use LogicException;
use PHPUnit\Framework\TestCase;
use SystemDesignLab\Webhook\Subscription;
use SystemDesignLab\Webhook\WebhookDelivery;
use SystemDesignLab\Webhook\WebhookEvent;

final class WebhookDeliveryTest extends TestCase
{
    public function testDeliveryStartsPendingWithTheIdAsIdempotencyKey(): void
    {
        $delivery = $this->delivery();

        self::assertTrue($delivery->isPending());
        self::assertSame('delivery-1', $delivery->idempotencyKey());
        self::assertSame(0, $delivery->attempts());
        self::assertSame(0, $delivery->availableAtMs());
    }

    public function testAttemptsAreCounted(): void
    {
        $delivery = $this->delivery();

        self::assertSame(1, $delivery->recordAttempt());
        self::assertSame(2, $delivery->recordAttempt());
        self::assertSame(2, $delivery->attempts());
    }

    public function testRetryMovesTheAvailabilityForward(): void
    {
        $delivery = $this->delivery();
        $delivery->recordAttempt();
        $delivery->scheduleRetry(100, 250);

        self::assertSame(350, $delivery->availableAtMs());
        self::assertTrue($delivery->isPending());
    }

    public function testDeliveredDeliveryIsTerminal(): void
    {
        $delivery = $this->delivery();
        $delivery->markDelivered(10);

        self::assertFalse($delivery->isPending());
        self::assertSame(10, $delivery->deliveredAtMs());
        self::assertNull($delivery->deadLetteredAtMs());
    }

    public function testDeadLetteredDeliveryIsTerminalAndKeepsTheError(): void
    {
        $delivery = $this->delivery();
        $delivery->deadLetter(10, 'endpoint unreachable');

        self::assertFalse($delivery->isPending());
        self::assertSame(10, $delivery->deadLetteredAtMs());
        self::assertSame('endpoint unreachable', $delivery->lastError());
    }

    public function testFinishedDeliveryCannotAttemptOrRetry(): void
    {
        $delivery = $this->delivery();
        $delivery->markDelivered(10);

        $this->expectException(LogicException::class);
        $delivery->recordAttempt();
    }

    public function testFinishedDeliveryCannotBeDeadLetteredAgain(): void
    {
        $delivery = $this->delivery();
        $delivery->deadLetter(10, 'failure');

        $this->expectException(LogicException::class);
        $delivery->deadLetter(20, 'failure');
    }

    public function testNegativeRetryDelayIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->delivery()->scheduleRetry(0, -1);
    }

    public function testEventAndSubscriptionMustShareATenant(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new WebhookDelivery(
            'delivery-1',
            new WebhookEvent('event-1', 'tenant-b', 'link.created', '{}'),
            $this->subscription(),
            0,
        );
    }

    public function testEmptyDeliveryIdIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new WebhookDelivery('', new WebhookEvent('event-1', 'tenant-a', 'link.created', '{}'), $this->subscription(), 0);
    }

    private function delivery(): WebhookDelivery
    {
        return new WebhookDelivery(
            'delivery-1',
            new WebhookEvent('event-1', 'tenant-a', 'link.created', '{}'),
            $this->subscription(),
            0,
        );
    }

    private function subscription(): Subscription
    {
        return new Subscription('sub-1', 'tenant-a', 'https://example.test/hook', 'secret');
    }
}
