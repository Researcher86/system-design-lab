<?php

declare(strict_types=1);

namespace SystemDesignLab\Experiment;

/**
 * Where an experiment writes prose and records named measurements.
 *
 * Prose goes to the stream as it is produced so a slow experiment does not
 * look hung. Measurements are collected separately and are what JSON output
 * carries; quiet mode suppresses only the prose.
 */
final class Output
{
    /** @var array<string, mixed> */
    private array $measurements = [];

    /**
     * @param resource $stream
     */
    public function __construct(
        private readonly mixed $stream = STDOUT,
        private readonly bool $quiet = false,
    ) {
    }

    public function line(string $line = ''): void
    {
        $this->write($line . "\n");
    }

    public function write(string $text): void
    {
        if (!$this->quiet) {
            fwrite($this->stream, $text);
        }
    }

    public function printf(string $format, int|float|string ...$arguments): void
    {
        $this->write(sprintf($format, ...$arguments));
    }

    public function heading(string $title, string $description = ''): void
    {
        $this->line(sprintf('Experiment: %s', $title));

        if ($description !== '') {
            $this->line($description);
        }

        $this->line();
    }

    public function section(string $title): void
    {
        $this->line();
        $this->line($title);
        $this->line(str_repeat('-', strlen($title)));
    }

    /**
     * A terminal table. Values are converted to strings; the caller decides
     * the precision. This is prose, not a CSV export.
     *
     * @param list<string>             $headers
     * @param list<list<int|float|string|null>> $rows
     */
    public function table(array $headers, array $rows): void
    {
        $widths = array_map(strlen(...), $headers);

        foreach ($rows as $row) {
            foreach ($row as $index => $value) {
                $widths[$index] = max($widths[$index] ?? 0, strlen($this->cell($value)));
            }
        }

        $render = static function (array $cells) use ($widths): string {
            $parts = [];

            foreach ($cells as $index => $cell) {
                $text = is_int($cell) || is_float($cell) || $cell === null
                    ? (string) $cell
                    : $cell;
                $parts[] = str_pad((string) $text, $widths[$index]);
            }

            return rtrim(implode('  ', $parts));
        };

        $this->line($render($headers));
        $this->line(implode('  ', array_map(static fn (int $width): string => str_repeat('-', $width), $widths)));

        foreach ($rows as $row) {
            $this->line($render($row));
        }
    }

    public function bullet(string $text): void
    {
        $this->line('  - ' . $text);
    }

    public function measure(string $name, mixed $value): void
    {
        $this->measurements[$name] = $value;
    }

    /**
     * @return array<string, mixed>
     */
    public function measurements(): array
    {
        return $this->measurements;
    }

    private function cell(int|float|string|null $value): string
    {
        return $value === null ? '' : (string) $value;
    }
}
