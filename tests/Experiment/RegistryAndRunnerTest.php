<?php

declare(strict_types=1);

namespace SystemDesignLab\Tests\Experiment;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use SystemDesignLab\Experiment\ExperimentRunner;
use SystemDesignLab\Experiment\Options;
use SystemDesignLab\Experiment\Output;
use SystemDesignLab\Experiment\Registry;

final class RegistryAndRunnerTest extends TestCase
{
    public function testRegistryDiscoversAndSortsExperiments(): void
    {
        $directory = $this->temporaryDirectory();
        $this->writeExperiment($directory . '/topic-b', 'b.php', 'test:b', []);
        $this->writeExperiment($directory . '/topic-a', 'a.php', 'test:a', []);

        $registry = new Registry($directory);

        self::assertSame(['test:a', 'test:b'], array_keys($registry->all()));
        self::assertTrue($registry->has('test:a'));
        self::assertFalse($registry->has('test:c'));
    }

    public function testRunnerPassesOptionsAndRecordsMeasurements(): void
    {
        $directory = $this->temporaryDirectory();
        $this->writeExperiment($directory . '/topic', 'one.php', 'test:one', ['requests']);
        $stream = fopen('php://temp', 'wb+');
        self::assertIsResource($stream);
        $output = new Output($stream);

        $result = new ExperimentRunner(new Registry($directory))->run(
            'test:one',
            Options::fromStrings(['requests' => '3']),
            $output,
        );

        self::assertSame('test:one', $result->name);
        self::assertSame(['requests' => 3], $result->options);
        self::assertSame(3, $result->measurements['value']);
        self::assertGreaterThanOrEqual(0.0, $result->elapsedSeconds);
    }

    public function testRunnerRefusesUnsupportedOption(): void
    {
        $directory = $this->temporaryDirectory();
        $this->writeExperiment($directory . '/topic', 'one.php', 'test:one', []);
        $stream = fopen('php://temp', 'wb+');
        self::assertIsResource($stream);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('does not use --requests');
        new ExperimentRunner(new Registry($directory))->run(
            'test:one',
            Options::fromStrings(['requests' => '1']),
            new Output($stream),
        );
    }

    public function testRegistryRefusesDuplicateNames(): void
    {
        $directory = $this->temporaryDirectory();
        $this->writeExperiment($directory . '/topic-a', 'one.php', 'test:same', []);
        $this->writeExperiment($directory . '/topic-b', 'two.php', 'test:same', []);

        $this->expectException(\RuntimeException::class);
        new Registry($directory)->all();
    }

    private function temporaryDirectory(): string
    {
        $directory = sys_get_temp_dir() . '/system-design-lab-' . bin2hex(random_bytes(6));
        mkdir($directory, 0o777, true);

        return $directory;
    }

    /**
     * @param list<string> $supports
     */
    private function writeExperiment(string $directory, string $file, string $name, array $supports): void
    {
        if (!is_dir($directory)) {
            mkdir($directory, 0o777, true);
        }

        $supportsPhp = var_export($supports, true);
        $code = sprintf(
            "<?php\n\ndeclare(strict_types=1);\n\nuse SystemDesignLab\\Experiment\\Experiment;\nuse SystemDesignLab\\Experiment\\Options;\nuse SystemDesignLab\\Experiment\\Output;\n\nreturn new Experiment(\n    name: %s,\n    description: 'fixture',\n    supports: %s,\n    run: static function (Options \$options, Output \$output): void {\n        \$output->measure('value', \$options->int('requests', 3));\n    },\n);\n",
            var_export($name, true),
            $supportsPhp,
        );

        file_put_contents($directory . '/' . $file, $code);
    }
}
