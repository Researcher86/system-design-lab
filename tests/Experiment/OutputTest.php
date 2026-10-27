<?php

declare(strict_types=1);

namespace SystemDesignLab\Tests\Experiment;

use PHPUnit\Framework\TestCase;
use SystemDesignLab\Experiment\Output;

final class OutputTest extends TestCase
{
    public function testWritesLinesMeasurementsAndTables(): void
    {
        $stream = fopen('php://temp', 'wb+');
        self::assertIsResource($stream);

        $output = new Output($stream);
        $output->heading('Title', 'Description');
        $output->section('Section');
        $output->bullet('item');
        $output->table(['a', 'bb'], [[1, 'x'], [20, 'yy']]);
        $output->measure('answer', 42);

        rewind($stream);
        $text = (string) stream_get_contents($stream);

        self::assertStringContainsString('Experiment: Title', $text);
        self::assertStringContainsString('Section', $text);
        self::assertStringContainsString('- item', $text);
        self::assertStringContainsString('a   bb', $text);
        self::assertStringContainsString('20  yy', $text);
        self::assertSame(['answer' => 42], $output->measurements());
    }

    public function testQuietModeSuppressesProseButKeepsMeasurements(): void
    {
        $stream = fopen('php://temp', 'wb+');
        self::assertIsResource($stream);

        $output = new Output($stream, quiet: true);
        $output->line('hidden');
        $output->measure('answer', 42);

        rewind($stream);
        self::assertSame('', stream_get_contents($stream));
        self::assertSame(['answer' => 42], $output->measurements());
    }
}
