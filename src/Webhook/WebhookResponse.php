<?php

declare(strict_types=1);

namespace SystemDesignLab\Webhook;

/**
 * The endpoint's answer. Any 2xx is a success; everything else, including a
 * transport error with status 0, is a retryable failure in this lab.
 */
final readonly class WebhookResponse
{
    public function __construct(
        public int $statusCode,
        public string $error = '',
    ) {
        if ($this->statusCode < 0) {
            throw new \InvalidArgumentException('Status code cannot be negative');
        }
    }

    public static function success(int $statusCode = 200): self
    {
        return new self($statusCode);
    }

    public static function failure(int $statusCode = 500, string $error = 'endpoint failure'): self
    {
        return new self($statusCode, $error);
    }

    public function isSuccessful(): bool
    {
        return $this->statusCode >= 200 && $this->statusCode < 300;
    }
}
