<?php

declare(strict_types=1);

namespace SystemDesignLab\Webhook;

/**
 * The three states of a circuit breaker.
 */
enum CircuitState: string
{
    case Closed = 'closed';
    case Open = 'open';
    case HalfOpen = 'half-open';
}
