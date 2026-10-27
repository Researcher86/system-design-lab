<?php

declare(strict_types=1);

namespace SystemDesignLab\Tests\Cli;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use SystemDesignLab\Cli\Arguments;

final class ArgumentsTest extends TestCase
{
    public function testParsesCommandAndOptions(): void
    {
        $arguments = Arguments::parse(['demo:smoke', '--requests=10', '--seed=book']);

        self::assertSame('demo:smoke', $arguments->command);
        self::assertSame('10', $arguments->string('requests', '1'));
        self::assertSame('book', $arguments->string('seed', 'x'));
        self::assertSame(['requests' => '10', 'seed' => 'book'], $arguments->except());
        self::assertSame(['seed' => 'book'], $arguments->except('requests'));
        self::assertTrue($arguments->has('seed'));
        self::assertFalse($arguments->has('nope'));
    }

    public function testParsesIntegerBounds(): void
    {
        $arguments = Arguments::parse(['run', '--a=3', '--b=0']);

        self::assertSame(3, $arguments->positiveInt('a', 1));
        self::assertSame(0, $arguments->nonNegativeInt('b', 1));
        self::assertSame(5, $arguments->positiveInt('missing', 5));
    }

    public function testRejectsInvalidInteger(): void
    {
        $this->expectException(InvalidArgumentException::class);
        Arguments::parse(['run', '--a=1.5'])->positiveInt('a', 1);
    }

    public function testRejectsUnrecognisedArgument(): void
    {
        $this->expectException(InvalidArgumentException::class);
        Arguments::parse(['run', 'stray']);
    }
}
