<?php

declare(strict_types=1);

namespace SystemDesignLab\Tests\Integration;

use PHPUnit\Framework\TestCase;
use SystemDesignLab\Foundation\Hashers;
use SystemDesignLab\Integration\ScenarioWebhookTransport;
use SystemDesignLab\Webhook\Signer;
use SystemDesignLab\Webhook\WebhookRequest;

final class ScenarioWebhookTransportTest extends TestCase
{
    private const URL = 'https://hooks.example.test/tenant-0';

    private const SECRET = 'scenario-secret';

    public function testAValidSignedRequestProducesOneEffect(): void
    {
        $transport = $this->transport();
        $response = $transport->send($this->request('delivery-a', 1, 100));

        self::assertTrue($response->isSuccessful());
        self::assertSame(1, $transport->attempts());
        self::assertSame(1, $transport->effects());
        self::assertSame(0, $transport->suppressedDuplicates());
        self::assertSame(0, $transport->invalidSignatures());
    }

    public function testARepeatedIdempotencyKeySuppressesTheSecondEffect(): void
    {
        $transport = $this->transport();
        $transport->send($this->request('delivery-a', 1, 100));
        $response = $transport->send($this->request('delivery-a', 2, 200));

        self::assertTrue($response->isSuccessful());
        self::assertSame(2, $transport->attempts());
        self::assertSame(1, $transport->effects());
        self::assertSame(1, $transport->suppressedDuplicates());
    }

    public function testAnInvalidSignatureIsRejected(): void
    {
        $transport = $this->transport();
        $request = $this->request('delivery-a', 1, 100, 'wrong-secret');

        $response = $transport->send($request);

        self::assertFalse($response->isSuccessful());
        self::assertSame(401, $response->statusCode);
        self::assertSame(0, $transport->effects());
        self::assertSame(1, $transport->invalidSignatures());
    }

    public function testAnUnavailableEndpointFailsUntilTheRecoveryTime(): void
    {
        $transport = new ScenarioWebhookTransport(
            [self::URL => self::SECRET],
            unavailableUntilMs: 100,
        );

        self::assertFalse($transport->send($this->request('delivery-a', 1, 50))->isSuccessful());
        self::assertTrue($transport->send($this->request('delivery-a', 2, 150))->isSuccessful());
        self::assertSame(1, $transport->effects());
    }

    public function testTheDownEndpointAlwaysFails(): void
    {
        $url = 'https://hooks.example.test/tenant-0/down';
        $transport = new ScenarioWebhookTransport([$url => self::SECRET]);

        self::assertFalse($transport->send($this->request('delivery-a', 1, 0, self::SECRET, $url))->isSuccessful());
        self::assertFalse($transport->send($this->request('delivery-b', 1, 1_000, self::SECRET, $url))->isSuccessful());
        self::assertSame(0, $transport->effects());
        self::assertSame(0, $transport->invalidSignatures());
    }

    public function testALostResponseStillProducesOneEffect(): void
    {
        $transport = $this->transport();
        $key = 'delivery-lost';

        while (Hashers::hash64($key) % 5 !== 0) {
            $key .= '-x';
        }

        $first = $transport->send($this->request($key, 1, 100));
        $retry = $transport->send($this->request($key, 2, 200));

        self::assertFalse($first->isSuccessful());
        self::assertSame(500, $first->statusCode);
        self::assertTrue($retry->isSuccessful());
        self::assertSame(1, $transport->effects());
        self::assertSame(1, $transport->suppressedDuplicates());
    }

    private function transport(): ScenarioWebhookTransport
    {
        return new ScenarioWebhookTransport([self::URL => self::SECRET]);
    }

    private function request(
        string $key,
        int $attempt,
        int $timestampMs,
        string $secret = self::SECRET,
        string $url = self::URL,
    ): WebhookRequest {
        $body = '{"event":"link.created","code":"abc"}';
        $signer = new Signer();

        return new WebhookRequest(
            url: $url,
            body: $body,
            signature: $signer->sign($secret, $body, $timestampMs),
            idempotencyKey: $key,
            attempt: $attempt,
            timestampMs: $timestampMs,
            eventId: 'event-1',
            subscriptionId: 'sub-1',
        );
    }
}
