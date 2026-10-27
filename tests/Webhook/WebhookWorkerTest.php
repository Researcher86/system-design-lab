<?php

declare(strict_types=1);

namespace SystemDesignLab\Tests\Webhook;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use SystemDesignLab\Queue\ExponentialBackoff;
use SystemDesignLab\Webhook\CircuitState;
use SystemDesignLab\Webhook\Signer;
use SystemDesignLab\Webhook\Subscription;
use SystemDesignLab\Webhook\WebhookDelivery;
use SystemDesignLab\Webhook\WebhookEvent;
use SystemDesignLab\Webhook\WebhookResponse;
use SystemDesignLab\Webhook\WebhookWorker;

final class WebhookWorkerTest extends TestCase
{
    public function testSuccessfulDeliveryIsSignedAndMarkedProcessed(): void
    {
        $transport = new FakeTransport();
        $worker = $this->worker($transport);
        $delivery = $this->delivery();

        self::assertTrue($worker->enqueue($delivery, 0));
        self::assertSame(1, $worker->deliverDue(0));

        self::assertSame(0, $worker->pendingCount());
        self::assertSame(1, $worker->counters()->get('attempts'));
        self::assertSame(1, $worker->counters()->get('delivered'));
        self::assertSame(1, $worker->processed()->size());
        self::assertCount(1, $transport->requests);

        $request = $transport->requests[0];
        self::assertSame('delivery-1', $request->idempotencyKey);
        self::assertSame(1, $request->attempt);
        self::assertSame('event-1', $request->eventId);
        self::assertSame('sub-1', $request->subscriptionId);
        self::assertTrue(new Signer()->verify('secret', $request->body, $request->signature, $request->timestampMs));
    }

    public function testDuplicateActiveEnqueueIsRejected(): void
    {
        $worker = $this->worker(new FakeTransport());
        $worker->enqueue($this->delivery(), 0);

        self::assertFalse($worker->enqueue($this->delivery(), 0));
        self::assertSame(1, $worker->counters()->get('duplicateEnqueues'));
        self::assertSame(1, $worker->pendingCount());
    }

    public function testCompletedDeliveryIsRejectedInsideTheTtl(): void
    {
        $worker = $this->worker(new FakeTransport());
        $worker->enqueue($this->delivery(), 0);
        $worker->deliverDue(0);

        self::assertFalse($worker->enqueue($this->delivery(), 0));
        self::assertSame(1, $worker->counters()->get('duplicateEnqueues'));
    }

    public function testFailureRetriesWithBackoffAndThenSucceeds(): void
    {
        $transport = new FakeTransport();
        $transport->responses = [WebhookResponse::failure(), WebhookResponse::failure()];
        $worker = $this->worker($transport, maxAttempts: 4);
        $delivery = $this->delivery();
        $worker->enqueue($delivery, 0);

        self::assertSame(1, $worker->deliverDue(0));
        self::assertSame(1, $delivery->attempts());
        self::assertSame(100, $delivery->availableAtMs());

        self::assertSame(0, $worker->deliverDue(99));
        self::assertSame(1, $worker->deliverDue(100));
        self::assertSame(2, $delivery->attempts());
        self::assertSame(300, $delivery->availableAtMs());

        self::assertSame(1, $worker->deliverDue(300));
        self::assertSame(3, $delivery->attempts());
        self::assertSame(0, $worker->pendingCount());
        self::assertSame(2, $worker->counters()->get('retried'));
        self::assertSame(300, $worker->counters()->get('retryDelayMs'));
        self::assertSame(3, $worker->counters()->get('attempts'));
        self::assertSame(1, $worker->counters()->get('delivered'));
    }

    public function testPermanentFailureDeadLettersAfterMaxAttempts(): void
    {
        $transport = new FakeTransport();
        $transport->responses = [WebhookResponse::failure(500, 'down'), WebhookResponse::failure(500, 'down')];
        $worker = $this->worker($transport, maxAttempts: 2);
        $delivery = $this->delivery();
        $worker->enqueue($delivery, 0);

        $worker->deliverDue(0);
        $worker->deliverDue(100);

        self::assertSame(0, $worker->pendingCount());
        self::assertCount(1, $worker->deadLetters());
        self::assertSame(2, $delivery->attempts());
        self::assertSame('down', $delivery->lastError());
        self::assertSame(1, $worker->counters()->get('deadLettered'));
        self::assertSame(1, $worker->counters()->get('retried'));
    }

    public function testOpenCircuitDefersWithoutConsumingAnAttempt(): void
    {
        $transport = new FakeTransport();
        $transport->responses = [WebhookResponse::failure(), WebhookResponse::failure()];
        $worker = $this->worker($transport, maxAttempts: 5, threshold: 2, cooldown: 1_000);
        $delivery = $this->delivery();
        $worker->enqueue($delivery, 0);

        $worker->deliverDue(0);
        $worker->deliverDue(100);

        self::assertSame(CircuitState::Open, $worker->breaker('https://example.test/hook')->state(100));
        self::assertSame(2, $delivery->attempts());

        $worker->deliverDue(300);

        self::assertCount(2, $transport->requests);
        self::assertSame(1_100, $delivery->availableAtMs());
        self::assertSame(1, $worker->counters()->get('circuitDeferred'));

        self::assertSame(1, $worker->deliverDue(1_100));
        self::assertSame(3, $delivery->attempts());
        self::assertSame(0, $worker->pendingCount());
        self::assertSame(CircuitState::Closed, $worker->breaker('https://example.test/hook')->state(1_100));
    }

    public function testFailedProbeReopensTheCircuit(): void
    {
        $transport = new FakeTransport();
        $transport->responses = [
            WebhookResponse::failure(),
            WebhookResponse::failure(),
            WebhookResponse::success(),
        ];
        $worker = $this->worker($transport, maxAttempts: 5, threshold: 1, cooldown: 100);
        $delivery = $this->delivery();
        $worker->enqueue($delivery, 0);

        $worker->deliverDue(0);
        self::assertSame(CircuitState::HalfOpen, $worker->breaker('https://example.test/hook')->state(100));
        $worker->deliverDue(100);
        self::assertSame(CircuitState::Open, $worker->breaker('https://example.test/hook')->state(100));

        $worker->deliverDue(300);

        self::assertSame(CircuitState::Closed, $worker->breaker('https://example.test/hook')->state(300));
        self::assertSame(0, $worker->pendingCount());
    }

    public function testTransportExceptionIsTreatedAsARetryableFailure(): void
    {
        $transport = new FakeTransport();
        $transport->exceptions = [new RuntimeException('timeout')];
        $worker = $this->worker($transport, maxAttempts: 2);
        $delivery = $this->delivery();
        $worker->enqueue($delivery, 0);

        $worker->deliverDue(0);

        self::assertSame(1, $worker->counters()->get('transportErrors'));
        self::assertSame(1, $delivery->attempts());
        self::assertSame(100, $delivery->availableAtMs());

        $worker->deliverDue(100);

        self::assertSame(0, $worker->pendingCount());
        self::assertSame(1, $worker->counters()->get('delivered'));
    }

    public function testRetriesKeepTheSameIdempotencyKeyAndSignaturePayload(): void
    {
        $transport = new FakeTransport();
        $transport->responses = [WebhookResponse::failure()];
        $worker = $this->worker($transport, maxAttempts: 2);
        $worker->enqueue($this->delivery(), 0);
        $worker->deliverDue(0);
        $worker->deliverDue(100);

        self::assertCount(2, $transport->requests);
        self::assertSame($transport->requests[0]->idempotencyKey, $transport->requests[1]->idempotencyKey);
        self::assertSame($transport->requests[0]->body, $transport->requests[1]->body);
        self::assertSame(1, $transport->requests[0]->attempt);
        self::assertSame(2, $transport->requests[1]->attempt);
    }

    public function testNextAvailableAtTracksTheEarliestRetry(): void
    {
        $transport = new FakeTransport();
        $transport->responses = [WebhookResponse::failure(), WebhookResponse::failure()];
        $worker = $this->worker($transport, maxAttempts: 4);
        $worker->enqueue($this->delivery('delivery-1'), 0);
        $worker->enqueue($this->delivery('delivery-2'), 0);

        $worker->deliverDue(0);

        self::assertSame(100, $worker->nextAvailableAtMs());
        self::assertSame(2, $worker->pendingCount());
    }

    public function testMaxAttemptsMustBePositive(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->worker(new FakeTransport(), maxAttempts: 0);
    }

    public function testThresholdMustBePositive(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->worker(new FakeTransport(), threshold: 0);
    }

    public function testCooldownCannotBeNegative(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->worker(new FakeTransport(), cooldown: -1);
    }

    private function worker(
        FakeTransport $transport,
        int $maxAttempts = 3,
        int $threshold = 5,
        int $cooldown = 1_000,
    ): WebhookWorker {
        return new WebhookWorker(
            $transport,
            new Signer(),
            new ExponentialBackoff(baseMs: 100, multiplier: 2.0, maxMs: 10_000, jitterMs: 0),
            maxAttempts: $maxAttempts,
            circuitFailureThreshold: $threshold,
            circuitCooldownMs: $cooldown,
        );
    }

    private function delivery(string $id = 'delivery-1'): WebhookDelivery
    {
        return new WebhookDelivery(
            $id,
            new WebhookEvent('event-1', 'tenant-a', 'link.created', '{"id":"link-1"}'),
            new Subscription('sub-1', 'tenant-a', 'https://example.test/hook', 'secret'),
            0,
        );
    }
}
