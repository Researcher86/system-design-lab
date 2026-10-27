<?php

declare(strict_types=1);

namespace SystemDesignLab\Tests\Shortener;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SystemDesignLab\Shortener\Base62;

final class Base62Test extends TestCase
{
    /**
     * @return iterable<string, array{int}>
     */
    public static function values(): iterable
    {
        yield 'zero' => [0];
        yield 'one' => [1];
        yield 'last single digit' => [61];
        yield 'first two-digit value' => [62];
        yield 'second two-digit value' => [63];
        yield 'last two-digit value' => [3843];
        yield 'first three-digit value' => [3844];
        yield 'largest seven-digit value' => [Base62::capacity(7) - 1];
    }

    #[DataProvider('values')]
    public function testRoundTripWithoutPadding(int $value): void
    {
        self::assertSame($value, Base62::decode(Base62::encode($value)));
    }

    #[DataProvider('values')]
    public function testRoundTripWithPadding(int $value): void
    {
        $code = Base62::encode($value, 7);

        self::assertSame(7, strlen($code));
        self::assertSame($value, Base62::decode($code));
    }

    public function testFixedLengthCodesAreLexicographicallyOrdered(): void
    {
        self::assertSame('0000000', Base62::encode(0, 7));
        self::assertSame('0000001', Base62::encode(1, 7));
        self::assertSame('000000z', Base62::encode(61, 7));
        self::assertSame('0000010', Base62::encode(62, 7));
    }

    public function testValueThatDoesNotFitIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        Base62::encode(Base62::capacity(3), 3);
    }

    public function testNegativeValueIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        Base62::encode(-1);
    }

    public function testNegativeLengthIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        Base62::encode(1, -1);
    }

    public function testEmptyCodeIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        Base62::decode('');
    }

    public function testCharacterOutsideTheAlphabetIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        Base62::decode('abc-def');
    }

    public function testDecodeRejectsAValueAboveTheIntegerRange(): void
    {
        $this->expectException(InvalidArgumentException::class);
        Base62::decode(str_repeat('z', 11));
    }

    public function testCapacityIsSixtyTwoToTheLength(): void
    {
        self::assertSame(62, Base62::capacity(1));
        self::assertSame(3844, Base62::capacity(2));
        self::assertSame(238_328, Base62::capacity(3));
        self::assertSame(3_521_614_606_208, Base62::capacity(7));
    }

    public function testCapacityRejectsAZeroLength(): void
    {
        $this->expectException(InvalidArgumentException::class);
        Base62::capacity(0);
    }

    public function testCapacityRejectsAValueAboveTheIntegerRange(): void
    {
        $this->expectException(InvalidArgumentException::class);
        Base62::capacity(11);
    }
}
