<?php

declare(strict_types=1);

namespace SystemDesignLab\Webhook;

use InvalidArgumentException;
use SystemDesignLab\Instrumentation\Counters;

/**
 * Fans one event out to the subscriptions that want it.
 *
 * Dispatch is pure planning: it creates one delivery per matching
 * subscription and does not sign, send or retry. That split keeps the fan-out
 * count separate from the delivery count in the experiments.
 */
final class WebhookDispatcher
{
    /** @var array<string, Subscription> */
    private array $subscriptions = [];

    private readonly Counters $counters;

    public function __construct(?Counters $counters = null)
    {
        $this->counters = $counters ?? new Counters();
    }

    public function subscribe(Subscription $subscription): void
    {
        if (isset($this->subscriptions[$subscription->id])) {
            throw new InvalidArgumentException(sprintf('Subscription %s already exists', $subscription->id));
        }

        $this->subscriptions[$subscription->id] = $subscription;
    }

    /**
     * @return list<WebhookDelivery>
     */
    public function dispatch(WebhookEvent $event): array
    {
        $this->counters->add('events');
        $deliveries = [];

        foreach ($this->subscriptions as $subscription) {
            if ($subscription->tenantId !== $event->tenantId || !$subscription->wants($event->type)) {
                continue;
            }

            $this->counters->add('matchedSubscriptions');
            $deliveries[] = new WebhookDelivery(
                id: $event->tenantId . ':' . $event->id . ':' . $subscription->id,
                event: $event,
                subscription: $subscription,
                createdAtMs: $event->createdAtMs,
            );
            $this->counters->add('deliveriesCreated');
        }

        return $deliveries;
    }

    public function subscriptionCount(): int
    {
        return count($this->subscriptions);
    }

    public function counters(): Counters
    {
        return $this->counters;
    }
}
