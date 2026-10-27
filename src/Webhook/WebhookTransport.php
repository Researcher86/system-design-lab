<?php

declare(strict_types=1);

namespace SystemDesignLab\Webhook;

/**
 * The network boundary. Experiments substitute a deterministic endpoint;
 * tests substitute a scripted one. The worker owns retries, signatures and
 * circuit decisions, not the transport.
 */
interface WebhookTransport
{
    public function send(WebhookRequest $request): WebhookResponse;
}
