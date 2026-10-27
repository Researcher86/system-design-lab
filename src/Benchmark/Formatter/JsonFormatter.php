<?php

declare(strict_types=1);

namespace SystemDesignLab\Benchmark\Formatter;

use JsonException;
use SystemDesignLab\Benchmark\BenchmarkReport;

final class JsonFormatter implements Formatter
{
    /**
     * @throws JsonException
     */
    public function format(BenchmarkReport $report): string
    {
        $payload = [
            'suite' => $report->suite,
            'environment' => $report->environment->toArray(),
            'results' => array_map(
                static fn ($result): array => $result->toArray(),
                $report->results,
            ),
        ];

        return json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
    }
}
