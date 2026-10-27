<?php

declare(strict_types=1);

namespace SystemDesignLab\Benchmark\Formatter;

use SystemDesignLab\Benchmark\BenchmarkReport;

final class CsvFormatter implements Formatter
{
    public function format(BenchmarkReport $report): string
    {
        $lines = [
            'suite,benchmark,iterations,repetitions,median_ms,ops_per_second,p95_ms,min_ms,max_ms,mad_ms,php_delta_bytes,peak_delta_bytes',
        ];

        foreach ($report->results as $result) {
            $lines[] = implode(',', [
                $this->escape($report->suite),
                $this->escape($result->name),
                (string) $result->iterations,
                (string) $result->repetitions,
                sprintf('%.6f', $result->timings->median * 1000.0),
                sprintf('%.2f', $result->operationsPerSecond()),
                sprintf('%.6f', $result->timings->p95 * 1000.0),
                sprintf('%.6f', $result->timings->min * 1000.0),
                sprintf('%.6f', $result->timings->max * 1000.0),
                sprintf('%.6f', $result->timings->mad * 1000.0),
                (string) $result->phpDelta,
                (string) $result->peakDelta,
            ]);
        }

        return implode("\n", $lines) . "\n";
    }

    private function escape(string $value): string
    {
        return str_contains($value, ',') || str_contains($value, '"')
            ? '"' . str_replace('"', '""', $value) . '"'
            : $value;
    }
}
