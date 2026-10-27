<?php

declare(strict_types=1);

namespace SystemDesignLab\Tests\Webhook;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use SystemDesignLab\Webhook\Signer;

final class SignerTest extends TestCase
{
    private Signer $signer;

    private const PAYLOAD = '{"event":"link.created","id":"link-1"}';

    private const NOW = 1_700_000_000_000;

    protected function setUp(): void
    {
        $this->signer = new Signer();
    }

    public function testValidSignatureIsAccepted(): void
    {
        $signature = $this->signer->sign('secret', self::PAYLOAD, self::NOW);

        self::assertTrue($this->signer->verify('secret', self::PAYLOAD, $signature, self::NOW));
    }

    public function testSignatureCoversThePayload(): void
    {
        $signature = $this->signer->sign('secret', self::PAYLOAD, self::NOW);

        self::assertFalse($this->signer->verify('secret', self::PAYLOAD . 'x', $signature, self::NOW));
    }

    public function testSignatureIsBoundToTheSecret(): void
    {
        $signature = $this->signer->sign('secret-a', self::PAYLOAD, self::NOW);

        self::assertFalse($this->signer->verify('secret-b', self::PAYLOAD, $signature, self::NOW));
    }

    public function testStaleSignatureIsRejected(): void
    {
        $signature = $this->signer->sign('secret', self::PAYLOAD, self::NOW);

        self::assertFalse($this->signer->verify('secret', self::PAYLOAD, $signature, self::NOW + 300_001));
    }

    public function testFutureSignatureIsRejected(): void
    {
        $signature = $this->signer->sign('secret', self::PAYLOAD, self::NOW);

        self::assertFalse($this->signer->verify('secret', self::PAYLOAD, $signature, self::NOW - 300_001));
    }

    public function testSignatureAtTheToleranceBoundaryIsAccepted(): void
    {
        $signature = $this->signer->sign('secret', self::PAYLOAD, self::NOW);

        self::assertTrue($this->signer->verify('secret', self::PAYLOAD, $signature, self::NOW + 300_000, 300_000));
    }

    public function testMalformedSignatureIsRejected(): void
    {
        self::assertFalse($this->signer->verify('secret', self::PAYLOAD, 'v1=deadbeef', self::NOW));
        self::assertFalse($this->signer->verify('secret', self::PAYLOAD, 't=abc,v1=deadbeef', self::NOW));
        self::assertFalse($this->signer->verify('secret', self::PAYLOAD, '', self::NOW));
    }

    public function testEmptySecretCannotSign(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->signer->sign('', self::PAYLOAD, self::NOW);
    }

    public function testNegativeTimestampCannotSign(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->signer->sign('secret', self::PAYLOAD, -1);
    }

    public function testVerificationRejectsInvalidArgumentsInsteadOfThrowing(): void
    {
        $signature = $this->signer->sign('secret', self::PAYLOAD, self::NOW);

        self::assertFalse($this->signer->verify('', self::PAYLOAD, $signature, self::NOW));
        self::assertFalse($this->signer->verify('secret', self::PAYLOAD, $signature, -1));
        self::assertFalse($this->signer->verify('secret', self::PAYLOAD, $signature, self::NOW, -1));
    }

    public function testSignatureContainsTimestampAndVersion(): void
    {
        $signature = $this->signer->sign('secret', self::PAYLOAD, self::NOW);

        self::assertMatchesRegularExpression('/^t=\d+,v1=[0-9a-f]{64}$/', $signature);
        self::assertStringStartsWith('t=' . self::NOW . ',v1=', $signature);
    }
}
