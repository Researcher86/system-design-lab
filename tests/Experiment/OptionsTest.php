<?php

declare(strict_types=1);

namespace SystemDesignLab\Tests\Experiment;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use SystemDesignLab\Experiment\Options;

final class OptionsTest extends TestCase
{
    public function testParsesTypedValues(): void
    {
        $options = Options::fromStrings([
            'seed' => 'book',
            'requests' => '10',
            'alpha' => '1.2',
            'digest' => 'true',
            'priority' => '-3',
            'quiet-start' => '22',
        ]);

        self::assertSame('book', $options->seed('x'));
        self::assertSame(10, $options->int('requests', 1));
        self::assertSame(1.2, $options->float('alpha', 1.0));
        self::assertTrue($options->bool('digest', false));
        self::assertSame(-3, $options->int('priority', 0));
        self::assertSame(22, $options->int('quiet-start', 0));
        self::assertSame(['seed', 'requests', 'alpha', 'digest', 'priority', 'quiet-start'], $options->names());
    }

    public function testDefaultsAreUsedWhenAbsent(): void
    {
        $options = Options::none();

        self::assertSame('book', $options->seed('book'));
        self::assertSame(7, $options->int('requests', 7));
        self::assertSame(1.0, $options->float('alpha', 1.0));
        self::assertFalse($options->bool('digest', false));
        self::assertSame([], $options->toArray());
    }

    public function testRejectsUnknownOption(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Unknown option --nope');
        Options::fromStrings(['nope' => '1']);
    }

    public function testRejectsInvalidPositiveInteger(): void
    {
        $this->expectException(InvalidArgumentException::class);
        Options::fromStrings(['requests' => '0']);
    }

    public function testRejectsInvalidFloat(): void
    {
        $this->expectException(InvalidArgumentException::class);
        Options::fromStrings(['alpha' => 'nope']);
    }

    public function testRejectsInvalidBoolean(): void
    {
        $this->expectException(InvalidArgumentException::class);
        Options::fromStrings(['digest' => 'maybe']);
    }

    public function testRejectsInvalidQuietHour(): void
    {
        $this->expectException(InvalidArgumentException::class);
        Options::fromStrings(['quiet-start' => '24']);
    }

    public function testRejectsEmptyString(): void
    {
        $this->expectException(InvalidArgumentException::class);
        Options::fromStrings(['seed' => ' ']);
    }
}
