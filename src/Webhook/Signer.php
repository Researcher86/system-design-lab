<?php

declare(strict_types=1);

namespace SystemDesignLab\Webhook;

use InvalidArgumentException;

/**
 * HMAC-SHA256 signatures with a timestamp.
 *
 * The signed bytes are `<timestamp>.<payload>`, so a captured signature is
 * bound to both the body and the time. `verify()` rejects stale or future
 * timestamps before comparing the digest with `hash_equals`.
 */
final class Signer
{
    public function sign(string $secret, string $payload, int $timestampMs): string
    {
        if ($secret === '') {
            throw new InvalidArgumentException('Signing secret cannot be empty');
        }

        if ($timestampMs < 0) {
            throw new InvalidArgumentException('Signature timestamp cannot be negative');
        }

        $digest = hash_hmac('sha256', $timestampMs . '.' . $payload, $secret);

        return sprintf('t=%d,v1=%s', $timestampMs, $digest);
    }

    /**
     * @param int $toleranceMs Maximum accepted distance between the signature
     *                         timestamp and the verifier's clock.
     */
    public function verify(
        string $secret,
        string $payload,
        string $signature,
        int $nowMs,
        int $toleranceMs = 300_000,
    ): bool {
        if ($secret === '' || $nowMs < 0 || $toleranceMs < 0) {
            return false;
        }

        if (preg_match('/^t=(\d+),v1=([0-9a-f]{64})$/', $signature, $matches) !== 1) {
            return false;
        }

        $timestamp = (int) $matches[1];

        if (abs($nowMs - $timestamp) > $toleranceMs) {
            return false;
        }

        $expected = hash_hmac('sha256', $timestamp . '.' . $payload, $secret);

        return hash_equals($expected, $matches[2]);
    }
}
