<?php

declare(strict_types=1);

namespace SystemDesignLab\Integration;

use RuntimeException;

/**
 * A text and JSON report that does not depend on the experiment Output.
 *
 * The scenario writes both files itself, so `make scenario` leaves an artifact
 * even when the console output is redirected. The measurements are also
 * registered with the experiment runner, which keeps JSON experiments and the
 * scenario artifact consistent.
 */
final class ScenarioReport
{
    /** @var list<string> */
    private array $lines = [];

    /** @var array<string, int|float|string|bool> */
    private array $measurements = [];

    public function line(string $line = ''): void
    {
        $this->lines[] = $line;
    }

    public function heading(string $title, string $description = ''): void
    {
        $this->line(sprintf('Scenario: %s', $title));

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
     * @param list<string>                             $headers
     * @param list<list<int|float|string|bool|null>>   $rows
     */
    public function table(array $headers, array $rows): void
    {
        $widths = array_map(strlen(...), $headers);
        $cells = [];

        foreach ($rows as $row) {
            $rendered = [];

            foreach ($row as $value) {
                $rendered[] = $this->cell($value);
            }

            foreach ($rendered as $index => $value) {
                $widths[$index] = max($widths[$index] ?? 0, strlen($value));
            }

            $cells[] = $rendered;
        }

        $render = static function (array $values) use ($widths): string {
            $parts = [];

            foreach ($values as $index => $value) {
                $parts[] = str_pad((string) $value, $widths[$index]);
            }

            return rtrim(implode('  ', $parts));
        };

        $this->line($render($headers));
        $this->line(implode('  ', array_map(
            static fn (int $width): string => str_repeat('-', $width),
            $widths,
        )));

        foreach ($cells as $row) {
            $this->line($render($row));
        }
    }

    public function measure(string $name, int|float|string|bool $value): void
    {
        $this->measurements[$name] = $value;
    }

    /**
     * @return list<string>
     */
    public function lines(): array
    {
        return $this->lines;
    }

    /**
     * @return array<string, int|float|string|bool>
     */
    public function measurements(): array
    {
        return $this->measurements;
    }

    public function writeText(string $path): void
    {
        $this->ensureDirectory($path);
        $content = implode("\n", $this->lines) . "\n";

        if (file_put_contents($path, $content) === false) {
            throw new RuntimeException(sprintf('Unable to write scenario report to %s', $path));
        }
    }

    /**
     * @param array<string, int|float|string|bool> $options
     */
    public function writeJson(string $path, string $scenario, array $options = []): void
    {
        $this->ensureDirectory($path);
        $payload = [
            'scenario' => $scenario,
            'options' => (object) $options,
            'measurements' => (object) $this->measurements,
            'report' => implode("\n", $this->lines),
        ];
        $json = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);

        if (file_put_contents($path, $json . "\n") === false) {
            throw new RuntimeException(sprintf('Unable to write scenario JSON to %s', $path));
        }
    }

    private function cell(int|float|string|bool|null $value): string
    {
        if (is_bool($value)) {
            return $value ? 'yes' : 'no';
        }

        return $value === null ? '' : (string) $value;
    }

    private function ensureDirectory(string $path): void
    {
        $directory = dirname($path);

        if (!is_dir($directory) && !mkdir($directory, 0o777, true) && !is_dir($directory)) {
            throw new RuntimeException(sprintf('Unable to create %s', $directory));
        }
    }
}
