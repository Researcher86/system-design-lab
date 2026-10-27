<?php

declare(strict_types=1);

namespace SystemDesignLab\Webhook;

use InvalidArgumentException;
use LogicException;
use SystemDesignLab\Instrumentation\Counters;
use SystemDesignLab\Queue\ExponentialBackoff;
use SystemDesignLab\Queue\ProcessedJobStore;
use Throwable;

/**
 * Delivers webhook events with signatures, retries, a circuit breaker and
 * dead letters.
 *
 * The worker is at-least-once. A delivery id is the idempotency key and stays
 * stable across attempts; a completed delivery is remembered for a TTL so a
 * duplicate enqueue is rejected. If an endpoint processed the request but the
 * response was lost, the retry happens and the endpoint must suppress the
 * second side effect by the same key.
 *
 * A circuit that is open does not consume an attempt: the delivery is deferred
 * until the breaker allows a probe. A failed probe is a real attempt and can
 * eventually dead-letter the delivery.
 */
final class WebhookWorker
{
    /** @var array<string, WebhookDelivery> */
    private array $pending = [];

    /** @var array<string, CircuitBreaker> */
    private array $breakers = [];

    /** @var list<WebhookDelivery> */
    private array $deadLetters = [];

    private readonly ProcessedJobStore $processed;

    private readonly Counters $counters;

    public function __construct(
        private readonly WebhookTransport $transport,
        private readonly Signer $signer,
        private readonly ExponentialBackoff $backoff,
        private readonly int $maxAttempts = 5,
        private readonly int $circuitFailureThreshold = 5,
        private readonly int $circuitCooldownMs = 1_000,
        ?ProcessedJobStore $processed = null,
        ?Counters $counters = null,
    ) {
        if ($this->maxAttempts < 1) {
            throw new InvalidArgumentException('Maximum attempts must be at least 1');
        }

        if ($this->circuitFailureThreshold < 1) {
            throw new InvalidArgumentException('Circuit failure threshold must be at least 1');
        }

        if ($this->circuitCooldownMs < 0) {
            throw new InvalidArgumentException('Circuit cooldown cannot be negative');
        }

        $this->processed = $processed ?? new ProcessedJobStore(86_400_000);
        $this->counters = $counters ?? new Counters();
    }

    public function enqueue(WebhookDelivery $delivery, int $nowMs): bool
    {
        if ($nowMs < 0) {
            throw new InvalidArgumentException('Timestamp cannot be negative');
        }

        if (!$delivery->isPending()) {
            throw new LogicException(sprintf('Delivery %s is already finished', $delivery->id));
        }

        if (isset($this->pending[$delivery->id]) || $this->processed->isProcessed($delivery->id, $nowMs)) {
            $this->counters->add('duplicateEnqueues');

            return false;
        }

        $this->pending[$delivery->id] = $delivery;
        $this->counters->add('enqueued');

        return true;
    }

    /**
     * @return int Number of due deliveries the worker considered.
     */
    public function deliverDue(int $nowMs): int
    {
        if ($nowMs < 0) {
            throw new InvalidArgumentException('Timestamp cannot be negative');
        }

        $due = [];

        foreach ($this->pending as $delivery) {
            if ($delivery->availableAtMs() <= $nowMs) {
                $due[] = $delivery;
            }
        }

        foreach ($due as $delivery) {
            if ($delivery->isPending()) {
                $this->attempt($delivery, $nowMs);
            }
        }

        return count($due);
    }

    public function nextAvailableAtMs(): ?int
    {
        $next = null;

        foreach ($this->pending as $delivery) {
            $availableAt = $delivery->availableAtMs();

            if ($next === null || $availableAt < $next) {
                $next = $availableAt;
            }
        }

        return $next;
    }

    public function pendingCount(): int
    {
        return count($this->pending);
    }

    /**
     * @return list<WebhookDelivery>
     */
    public function deadLetters(): array
    {
        return $this->deadLetters;
    }

    public function breaker(string $url): CircuitBreaker
    {
        if (!isset($this->breakers[$url])) {
            $this->breakers[$url] = new CircuitBreaker(
                $this->circuitFailureThreshold,
                $this->circuitCooldownMs,
                $this->counters,
            );
        }

        return $this->breakers[$url];
    }

    public function processed(): ProcessedJobStore
    {
        return $this->processed;
    }

    public function counters(): Counters
    {
        return $this->counters;
    }

    private function attempt(WebhookDelivery $delivery, int $nowMs): void
    {
        $breaker = $this->breaker($delivery->subscription->url);

        if (!$breaker->allow($nowMs)) {
            $delay = max(1, $breaker->retryAfterMs($nowMs));
            $delivery->scheduleRetry($nowMs, $delay);
            $this->counters->add('circuitDeferred');

            return;
        }

        $attempt = $delivery->recordAttempt();
        $this->counters->add('attempts');
        $signature = $this->signer->sign(
            $delivery->subscription->secret,
            $delivery->event->payload,
            $nowMs,
        );
        $request = new WebhookRequest(
            url: $delivery->subscription->url,
            body: $delivery->event->payload,
            signature: $signature,
            idempotencyKey: $delivery->idempotencyKey(),
            attempt: $attempt,
            timestampMs: $nowMs,
            eventId: $delivery->event->id,
            subscriptionId: $delivery->subscription->id,
        );

        try {
            $response = $this->transport->send($request);
        } catch (Throwable $exception) {
            $this->counters->add('transportErrors');
            $response = WebhookResponse::failure(0, $exception->getMessage());
        }

        if ($response->isSuccessful()) {
            $breaker->recordSuccess($nowMs);
            $delivery->markDelivered($nowMs);
            $this->processed->mark($delivery->id, $nowMs);
            unset($this->pending[$delivery->id]);
            $this->counters->add('delivered');

            return;
        }

        $breaker->recordFailure($nowMs);
        $this->counters->add('failed');

        if ($attempt >= $this->maxAttempts) {
            $delivery->deadLetter($nowMs, $response->error);
            unset($this->pending[$delivery->id]);
            $this->deadLetters[] = $delivery;
            $this->counters->add('deadLettered');

            return;
        }

        $delay = $this->backoff->delayMs($attempt);
        $delivery->scheduleRetry($nowMs, $delay);
        $this->counters->add('retried');
        $this->counters->add('retryDelayMs', $delay);
    }
}
