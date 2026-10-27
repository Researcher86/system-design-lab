<?php

declare(strict_types=1);

namespace SystemDesignLab\Tests\Integration;

use InvalidArgumentException;
use JsonException;
use PHPUnit\Framework\TestCase;
use SystemDesignLab\Integration\ShortLinkPlatformScenario;

final class ShortLinkPlatformScenarioTest extends TestCase
{
    public function testTheScenarioConnectsEveryChapterAndKeepsItsInvariants(): void
    {
        $report = $this->scenario()->run();
        $measurements = $report->measurements();

        self::assertTrue($measurements['invariants_ok']);
        self::assertSame(120, $measurements['requests']);
        self::assertGreaterThan(0, $measurements['denied']);
        self::assertGreaterThan(0, $measurements['creates']);
        self::assertGreaterThan(0, $measurements['resolves']);
        self::assertSame(1, $measurements['cache_node_failures']);
        self::assertSame(1, $measurements['cache_node_recoveries']);
        self::assertGreaterThan(0, $measurements['cache_lost_entries']);
        self::assertGreaterThan(0, $measurements['webhook_duplicates_suppressed']);
        self::assertSame(0, $measurements['webhook_invalid_signatures']);
        self::assertSame(
            $measurements['webhook_down_deliveries'],
            $measurements['webhook_down_dead_lettered'],
        );
        self::assertSame(0, $measurements['queue_pending']);
        self::assertSame(0, $measurements['queue_leases']);
        self::assertStringContainsString('Invariants', implode("\n", $report->lines()));
    }

    public function testTheSameSeedProducesTheSameReport(): void
    {
        $first = $this->scenario()->run();
        $second = $this->scenario()->run();

        self::assertSame($first->lines(), $second->lines());
        self::assertSame($first->measurements(), $second->measurements());
    }

    /**
     * @throws JsonException
     */
    public function testTheReportWritesTextAndJsonArtifacts(): void
    {
        $scenario = $this->scenario();
        $report = $scenario->run();
        $directory = sys_get_temp_dir() . '/sdl-scenario-' . bin2hex(random_bytes(6));
        $textPath = $directory . '/report.txt';
        $jsonPath = $directory . '/report.json';

        self::assertTrue(mkdir($directory));

        try {
            $report->writeText($textPath);
            $report->writeJson($jsonPath, 'short-link-platform', $scenario->options());

            self::assertFileExists($textPath);
            self::assertFileExists($jsonPath);
            self::assertStringContainsString('Scenario: Multi-tenant short-link platform', (string) file_get_contents($textPath));

            /** @var array{
             *     scenario: string,
             *     options: array<string, mixed>,
             *     measurements: array<string, mixed>,
             *     report: string
             * } $payload
             */
            $payload = json_decode((string) file_get_contents($jsonPath), true, 512, JSON_THROW_ON_ERROR);

            self::assertSame('short-link-platform', $payload['scenario']);
            self::assertSame('integration-test', $payload['options']['seed']);
            self::assertSame(true, $payload['measurements']['invariants_ok']);
            self::assertStringContainsString('Invariants', $payload['report']);
        } finally {
            @unlink($textPath);
            @unlink($jsonPath);
            @rmdir($directory);
        }
    }

    public function testReplicationCannotExceedTheNodeCount(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new ShortLinkPlatformScenario(
            requests: 120,
            tenants: 3,
            users: 5,
            links: 30,
            nodes: 3,
            replicas: 4,
        );
    }

    public function testTooFewRequestsAreRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new ShortLinkPlatformScenario(requests: 9);
    }

    private function scenario(): ShortLinkPlatformScenario
    {
        return new ShortLinkPlatformScenario(
            seed: 'integration-test',
            requests: 120,
            tenants: 3,
            users: 5,
            links: 30,
            capacity: 5,
            refillPerSecond: 2.0,
            nodes: 3,
            replicas: 2,
            shards: 2,
            maxAttempts: 4,
            failEvery: 7,
            delayMs: 10,
            failMs: 10,
        );
    }
}
