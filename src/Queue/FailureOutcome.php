<?php

declare(strict_types=1);

namespace SystemDesignLab\Queue;

final readonly class FailureOutcome
{
    public function __construct(
        public FailureStatus $status,
        public int $retryDelayMs = 0,
    ) {
    }

    public static function retried(int $retryDelayMs): self
    {
        return new self(FailureStatus::Retried, $retryDelayMs);
    }

    public static function deadLettered(): self
    {
        return new self(FailureStatus::DeadLettered);
    }

    public static function stale(): self
    {
        return new self(FailureStatus::Stale);
    }
}
