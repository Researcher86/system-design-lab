<?php

declare(strict_types=1);

namespace SystemDesignLab\Webhook;

use InvalidArgumentException;

/**
 * One endpoint that wants a subset of a tenant's events.
 *
 * An empty event-type list means "all types"; a non-empty list is an explicit
 * subscription. The secret belongs to the subscription, not to the tenant:
 * rotating one endpoint must not invalidate every other endpoint.
 */
final readonly class Subscription
{
    /** @var list<string> */
    public array $eventTypes;

    /**
     * @param list<string> $eventTypes
     */
    public function __construct(
        public string $id,
        public string $tenantId,
        public string $url,
        public string $secret,
        array $eventTypes = [],
    ) {
        if (trim($this->id) === '') {
            throw new InvalidArgumentException('Subscription id cannot be empty');
        }

        if (trim($this->tenantId) === '') {
            throw new InvalidArgumentException('Subscription tenant id cannot be empty');
        }

        if (filter_var($this->url, FILTER_VALIDATE_URL) === false) {
            throw new InvalidArgumentException(sprintf('Not a URL: %s', $this->url));
        }

        $scheme = strtolower((string) parse_url($this->url, PHP_URL_SCHEME));

        if (!in_array($scheme, ['http', 'https'], true)) {
            throw new InvalidArgumentException('Webhook URL must use HTTP or HTTPS');
        }

        if (trim($this->secret) === '') {
            throw new InvalidArgumentException('Subscription secret cannot be empty');
        }

        $normalized = [];

        foreach ($eventTypes as $eventType) {
            $eventType = trim($eventType);

            if ($eventType === '') {
                throw new InvalidArgumentException('Event type cannot be empty');
            }

            if (!in_array($eventType, $normalized, true)) {
                $normalized[] = $eventType;
            }
        }

        $this->eventTypes = $normalized;
    }

    public function wants(string $eventType): bool
    {
        return $this->eventTypes === [] || in_array($eventType, $this->eventTypes, true);
    }
}
