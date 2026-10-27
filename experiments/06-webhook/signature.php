<?php

declare(strict_types=1);

use SystemDesignLab\Experiment\Experiment;
use SystemDesignLab\Experiment\Options;
use SystemDesignLab\Experiment\Output;
use SystemDesignLab\Webhook\Signer;

/**
 * What does an HMAC signature actually reject?
 *
 * The signature covers a timestamp and the exact payload. A valid signature
 * is accepted; a changed body, a different secret, a stale timestamp, a future
 * timestamp and a malformed header are rejected. The timestamp is inside the
 * signed bytes, so changing it invalidates the digest as well.
 */
return new Experiment(
    name: 'webhook:signature',
    description: 'HMAC validity, tampering, wrong secrets and timestamp windows',
    supports: ['requests', 'window-ms'],
    run: static function (Options $options, Output $output): void {
        $requests = $options->int('requests', 10_000);
        $toleranceMs = $options->int('window-ms', 300_000);
        $signer = new Signer();
        $payload = '{"event":"link.created","id":"link-1"}';
        $now = 1_700_000_000_000;
        $signature = $signer->sign('secret', $payload, $now);

        $output->heading(
            'Webhook signatures',
            sprintf('%s checks per scenario, timestamp tolerance %d ms.', number_format($requests), $toleranceMs),
        );
        $output->line();

        $scenarios = [
            'valid' => static fn (): bool => $signer->verify('secret', $payload, $signature, $now, $toleranceMs),
            'tampered body' => static fn (): bool => $signer->verify('secret', $payload . 'x', $signature, $now, $toleranceMs),
            'wrong secret' => static fn (): bool => $signer->verify('other-secret', $payload, $signature, $now, $toleranceMs),
            'stale timestamp' => static fn (): bool => $signer->verify('secret', $payload, $signature, $now + $toleranceMs + 1, $toleranceMs),
            'future timestamp' => static fn (): bool => $signer->verify('secret', $payload, $signature, $now - $toleranceMs - 1, $toleranceMs),
            'malformed header' => static fn (): bool => $signer->verify('secret', $payload, 'v1=deadbeef', $now, $toleranceMs),
        ];
        $rows = [];

        foreach ($scenarios as $name => $check) {
            $accepted = 0;

            for ($index = 0; $index < $requests; ++$index) {
                if ($check()) {
                    ++$accepted;
                }
            }

            $rows[] = [
                $name,
                number_format($requests),
                number_format($accepted),
                number_format($requests - $accepted),
            ];
            $output->measure('accepted_' . str_replace(' ', '_', $name), $accepted);
        }

        $output->table(['scenario', 'checks', 'accepted', 'rejected'], $rows);
        $output->line();
        $output->line('The signature covers the timestamp and the payload, not only the payload.');
        $output->line('A stale signature is rejected even when the digest itself is correct.');
        $output->line('hash_equals compares the digest without an early-exit timing leak.');
        $output->line('Timestamp checks bound replay, but the receiver still needs idempotency.');
    },
);
