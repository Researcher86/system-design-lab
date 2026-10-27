<?php

declare(strict_types=1);

namespace SystemDesignLab\Benchmark\Formatter;

use SystemDesignLab\Benchmark\BenchmarkReport;

interface Formatter
{
    public function format(BenchmarkReport $report): string;
}
