<?php

declare(strict_types=1);

namespace SystemDesignLab\Shortener;

use InvalidArgumentException;

/**
 * Base62 identifiers.
 *
 * The alphabet is digits, uppercase, lowercase. A seven-character code has
 * 62^7 = 3.5 trillion values, which is the reason seven characters are enough
 * for a service that will never create three trillion links. Fixed-length
 * encoding pads on the left; the decoder rejects characters outside the
 * alphabet instead of silently returning zero.
 */
final class Base62
{
    public const ALPHABET = '0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz';

    public static function encode(int $value, int $length = 0): string
    {
        if ($value < 0) {
            throw new InvalidArgumentException('Cannot encode a negative value');
        }

        if ($length < 0) {
            throw new InvalidArgumentException('Length cannot be negative');
        }

        $encoded = '';

        do {
            $encoded = self::ALPHABET[$value % 62] . $encoded;
            $value = intdiv($value, 62);
        } while ($value > 0);

        if ($length === 0) {
            return $encoded;
        }

        if (strlen($encoded) > $length) {
            throw new InvalidArgumentException(sprintf(
                'Value %s does not fit into %d base62 characters',
                $encoded,
                $length,
            ));
        }

        return str_pad($encoded, $length, '0', STR_PAD_LEFT);
    }

    public static function decode(string $code): int
    {
        if ($code === '') {
            throw new InvalidArgumentException('Cannot decode an empty code');
        }

        $value = 0;

        for ($i = 0, $length = strlen($code); $i < $length; ++$i) {
            $position = strpos(self::ALPHABET, $code[$i]);

            if ($position === false) {
                throw new InvalidArgumentException(sprintf('Invalid base62 character: %s', $code[$i]));
            }

            if ($value > intdiv(PHP_INT_MAX - $position, 62)) {
                throw new InvalidArgumentException('Code exceeds the integer range');
            }

            $value = $value * 62 + $position;
        }

        return $value;
    }

    public static function capacity(int $length): int
    {
        if ($length < 1) {
            throw new InvalidArgumentException('Code length must be at least 1');
        }

        $capacity = 1;

        for ($i = 0; $i < $length; ++$i) {
            if ($capacity > intdiv(PHP_INT_MAX, 62)) {
                throw new InvalidArgumentException(sprintf('%d base62 characters exceed the integer range', $length));
            }

            $capacity *= 62;
        }

        return $capacity;
    }
}
