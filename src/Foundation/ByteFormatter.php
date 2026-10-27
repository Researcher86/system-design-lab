<?php

declare(strict_types=1);

namespace SystemDesignLab\Foundation;

final class ByteFormatter
{
    public static function format(int $bytes): string
    {
        $units = ['B', 'KiB', 'MiB', 'GiB'];
        $value = (float) $bytes;
        $unit = 0;

        while ($value >= 1024.0 && $unit < count($units) - 1) {
            $value /= 1024.0;
            ++$unit;
        }

        return $unit === 0
            ? sprintf('%d %s', $bytes, $units[$unit])
            : sprintf('%.1f %s', $value, $units[$unit]);
    }

    public static function perItem(int $bytes, int $items): string
    {
        if ($items < 1) {
            return 'n/a';
        }

        return sprintf('%.1f B', $bytes / $items);
    }
}
