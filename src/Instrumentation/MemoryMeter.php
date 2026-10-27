<?php

declare(strict_types=1);

namespace SystemDesignLab\Instrumentation;

/**
 * The PHP-memory side of a measurement.
 *
 * The engine allocator is the only part visible to memory_get_usage(); it is
 * not RSS and it is not native memory. That limitation is recorded rather than
 * hidden: the field is named php_delta_bytes everywhere it is reported.
 */
final class MemoryMeter
{
    private readonly int $baseline;

    public function __construct()
    {
        gc_collect_cycles();
        $this->baseline = memory_get_usage(false);
    }

    public function usage(): int
    {
        return memory_get_usage(false);
    }

    public function realUsage(): int
    {
        return memory_get_usage(true);
    }

    public function peak(): int
    {
        return memory_get_peak_usage(false);
    }

    public function peakReal(): int
    {
        return memory_get_peak_usage(true);
    }

    public function delta(): int
    {
        return $this->usage() - $this->baseline;
    }

    public function baseline(): int
    {
        return $this->baseline;
    }

    /**
     * @return array{usage: int, real_usage: int, peak: int, peak_real: int, delta: int}
     */
    public function snapshot(): array
    {
        return [
            'usage' => $this->usage(),
            'real_usage' => $this->realUsage(),
            'peak' => $this->peak(),
            'peak_real' => $this->peakReal(),
            'delta' => $this->delta(),
        ];
    }

    public function resetPeak(): void
    {
        memory_reset_peak_usage();
    }
}
