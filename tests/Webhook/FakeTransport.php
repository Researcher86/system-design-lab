<?php

declare(strict_types=1);

namespace SystemDesignLab\Tests\Webhook;

use SystemDesignLab\Webhook\WebhookRequest;
use SystemDesignLab\Webhook\WebhookResponse;
use SystemDesignLab\Webhook\WebhookTransport;
use Throwable;

final class FakeTransport implements WebhookTransport
{
    /** @var list<WebhookResponse> */
    public array $responses = [];

    /** @var list<Throwable> */
    public array $exceptions = [];

    /** @var list<WebhookRequest> */
    public array $requests = [];

    public function send(WebhookRequest $request): WebhookResponse
    {
        $this->requests[] = $request;
        $exception = array_shift($this->exceptions);

        if ($exception !== null) {
            throw $exception;
        }

        return array_shift($this->responses) ?? WebhookResponse::success();
    }
}
