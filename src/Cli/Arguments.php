<?php

declare(strict_types=1);

namespace SystemDesignLab\Cli;

use InvalidArgumentException;

/**
 * One command name and a bag of --name=value options.
 *
 * Both entry points in bin/ take the same shape: a subject followed by
 * options. Validation throws, leaving the caller to choose the exit code and
 * message instead of deciding three frames down.
 */
final readonly class Arguments
{
    /**
     * @param array<string, string> $options
     */
    private function __construct(
        public ?string $command,
        private array $options,
    ) {
    }

    /**
     * @param list<string> $arguments usually array_slice($argv, 1)
     *
     * @throws InvalidArgumentException on an unparseable argument or a second command name
     */
    public static function parse(array $arguments): self
    {
        $command = null;
        $options = [];

        foreach ($arguments as $argument) {
            if (preg_match('/^--([a-z][a-z-]*)=(.*)$/', $argument, $matches) === 1) {
                $options[$matches[1]] = $matches[2];

                continue;
            }

            if ($command === null && !str_starts_with($argument, '-')) {
                $command = $argument;

                continue;
            }

            throw new InvalidArgumentException(sprintf('Unrecognised argument: %s', $argument));
        }

        return new self($command, $options);
    }

    public function string(string $name, string $default): string
    {
        return $this->options[$name] ?? $default;
    }

    public function has(string $name): bool
    {
        return isset($this->options[$name]);
    }

    /**
     * @throws InvalidArgumentException when the value is not a positive integer
     */
    public function positiveInt(string $name, int $default): int
    {
        return $this->boundedInt($name, $default, 1, 'a positive integer');
    }

    /**
     * Zero is meaningful for warm-ups: it asks the harness to time the first
     * repetition instead of discarding it.
     *
     * @throws InvalidArgumentException when the value is negative or not a number
     */
    public function nonNegativeInt(string $name, int $default): int
    {
        return $this->boundedInt($name, $default, 0, 'zero or a positive integer');
    }

    /**
     * @throws InvalidArgumentException when the value is below $minimum or not a number
     */
    private function boundedInt(string $name, int $default, int $minimum, string $expected): int
    {
        if (!isset($this->options[$name])) {
            return $default;
        }

        $value = $this->options[$name];

        if (preg_match('/^\d+$/', $value) !== 1 || (int) $value < $minimum) {
            throw new InvalidArgumentException(sprintf('--%s must be %s, got "%s"', $name, $expected, $value));
        }

        return (int) $value;
    }

    /**
     * Everything except the named options, which lets an entry point keep the
     * options that belong to the script and pass the rest to an experiment.
     *
     * @return array<string, string>
     */
    public function except(string ...$names): array
    {
        return array_diff_key($this->options, array_flip($names));
    }
}
