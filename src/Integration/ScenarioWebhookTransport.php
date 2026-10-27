<?php

declare(strict_types=1);

namespace SystemDesignLab\Integration;

use InvalidArgumentException;
use SystemDesignLab\Foundation\Hashers;
use SystemDesignLab\Webhook\Signer;
use SystemDesignLab\Webhook\WebhookRequest;
use SystemDesignLab\Webhook\WebhookResponse;
use SystemDesignLab\Webhook\WebhookTransport;

/**
 * A deterministic receiver for the integration scenario.
 *
 * It verifies the HMAC signature, keeps one side effect per idempotency key
 * and counts a repeated effect as suppressed. One endpoint path is permanently
 * down; every other endpoint is unavailable until `unavailableUntilMs`.
 *
 * A first attempt on a subset of deliveries performs the side effect and then
 * reports a lost response. The retry must not perform the effect twice, which
 * is the at-least-once contract in one place.
 */
final class ScenarioWebhookTransport implements WebhookTransport
{
    /** @var array<string, string> url => secret */
    private array $secrets;

    /** @var array<string, int> idempotency key => effect timestamp */
    private array $effects = [];

    private int $attempts = 0;

    private int $effectsCount = 0;

    private int $suppressedDuplicates = 0;

    private int $invalidSignatures = 0;

    private readonly Signer $signer;

    /**
     * @param array<string, string> $secrets
     */
    public function __construct(
        array $secrets,
        private readonly int $unavailableUntilMs = 0,
        ?Signer $signer = null,
    ) {
        if ($unavailableUntilMs < 0) {
            throw new InvalidArgumentException('Unavailable-until timestamp cannot be negative');
        }

        $this->secrets = $secrets;
        $this->signer = $signer ?? new Signer();
    }

    public function send(WebhookRequest $request): WebhookResponse
    {
        ++$this->attempts;
        $secret = $this->secrets[$request->url] ?? null;

        if ($secret === null || !$this->signer->verify(
            $secret,
            $request->body,
            $request->signature,
            $request->timestampMs,
        )) {
            ++$this->invalidSignatures;

            return WebhookResponse::failure(401, 'invalid signature');
        }

        if (str_ends_with($request->url, '/down')) {
            return WebhookResponse::failure(503, 'endpoint down');
        }

        if ($request->timestampMs < $this->unavailableUntilMs) {
            return WebhookResponse::failure(503, 'endpoint unavailable');
        }

        $key = $request->idempotencyKey;

        if (isset($this->effects[$key])) {
            if ($request->attempt > 1) {
                ++$this->suppressedDuplicates;
            }

            return WebhookResponse::success();
        }

        $this->effects[$key] = $request->timestampMs;
        ++$this->effectsCount;

        if ($request->attempt === 1 && Hashers::hash64($key) % 5 === 0) {
            return WebhookResponse::failure(500, 'response lost');
        }

        return WebhookResponse::success();
    }

    public function attempts(): int
    {
        return $this->attempts;
    }

    public function effects(): int
    {
        return $this->effectsCount;
    }

    public function suppressedDuplicates(): int
    {
        return $this->suppressedDuplicates;
    }

    public function invalidSignatures(): int
    {
        return $this->invalidSignatures;
    }
}
