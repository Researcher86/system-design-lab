<?php

declare(strict_types=1);

namespace SystemDesignLab\Benchmark\Formatter;

use SystemDesignLab\Benchmark\BenchmarkReport;

final class TextFormatter implements Formatter
{
    public function format(BenchmarkReport $report): string
    {
        $environment = $report->environment;
        $lines = [
            sprintf('Benchmark suite: %s', $report->suite),
            sprintf(
                'PHP %s on %s %s, %s x %d, memory_limit %s, cgroup %s',
                $environment->phpVersion,
                $environment->os,
                $environment->kernel,
                $environment->architecture,
                $environment->cpuCount,
                $environment->memoryLimit,
                $environment->cgroupMemoryLimit === null ? 'unlimited' : (string) $environment->cgroupMemoryLimit,
            ),
            sprintf(
                'OPcache %s, JIT %s, Xdebug %s, allocator %s',
                $environment->opcacheEnabled ? 'on' : 'off',
                $environment->jitEnabled ? 'on' : 'off',
                $environment->xdebugMode,
                $environment->allocator,
            ),
            sprintf('git %s', $environment->commit),
            '',
        ];

        $rows = [];

        foreach ($report->results as $result) {
            $rows[] = [
                $result->name,
                (string) $result->iterations,
                sprintf('%.3f', $result->timings->median * 1000.0),
                sprintf('%.0f', $result->operationsPerSecond()),
                sprintf('%.3f', $result->timings->p95 * 1000.0),
                sprintf('%.3f', $result->timings->min * 1000.0),
                sprintf('%.3f', $result->timings->max * 1000.0),
                sprintf('%.3f', $result->timings->mad * 1000.0),
                (string) $result->phpDelta,
            ];
        }

        $headers = ['benchmark', 'iterations', 'median ms', 'ops/sec', 'p95 ms', 'min ms', 'max ms', 'MAD ms', 'PHP delta B'];
        $widths = array_map(strlen(...), $headers);

        foreach ($rows as $row) {
            foreach ($row as $index => $value) {
                $widths[$index] = max($widths[$index], strlen($value));
            }
        }

        $render = static function (array $cells) use ($widths): string {
            $parts = [];

            foreach ($cells as $index => $cell) {
                $parts[] = str_pad($cell, $widths[$index]);
            }

            return rtrim(implode('  ', $parts));
        };

        $lines[] = $render($headers);
        $lines[] = implode('  ', array_map(static fn (int $width): string => str_repeat('-', $width), $widths));

        foreach ($rows as $row) {
            $lines[] = $render($row);
        }

        $lines[] = '';
        $lines[] = 'Numbers describe this machine and this commit; they are not universal constants.';

        return implode("\n", $lines) . "\n";
    }
}
