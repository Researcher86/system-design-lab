<?php

declare(strict_types=1);

namespace SystemDesignLab\Tests;

use PHPUnit\Framework\TestCase;
use SystemDesignLab\Experiment\Registry;
use SystemDesignLab\Foundation\ProjectRoot;

/**
 * The documentation is executable enough to check.
 *
 * A README that names a document, a path or a command that does not exist is
 * worse than no README: it sends a reader down a dead end. This test keeps the
 * obvious drift out of CI.
 */
final class DocumentationTest extends TestCase
{
    public function testReadmeLinksEveryDocumentThatExists(): void
    {
        $root = ProjectRoot::path();
        $readme = (string) file_get_contents($root . '/README.md');

        preg_match_all('/\]\((?P<path>[^)#\s]+\.md)(?:#[^)]*)?\)/', $readme, $matches);

        self::assertNotEmpty($matches['path']);

        foreach ($matches['path'] as $path) {
            if (str_contains($path, '://')) {
                continue;
            }

            self::assertFileExists($root . '/' . $path, sprintf('README link does not resolve: %s', $path));
        }
    }

    public function testBacktickPathsExist(): void
    {
        $root = ProjectRoot::path();
        $documents = [$root . '/README.md'];

        foreach (glob($root . '/docs/*.md') ?: [] as $document) {
            $documents[] = $document;
        }

        $checked = 0;

        foreach ($documents as $document) {
            $text = (string) file_get_contents($document);
            preg_match_all('/`([^`\s]+)`/', $text, $matches);

            foreach ($matches[1] as $path) {
                if (!str_contains($path, '/') || str_contains($path, '<') || str_contains($path, '*') || str_contains($path, 'NN')) {
                    continue;
                }

                $prefix = strtok($path, '/');

                if (!in_array($prefix, ['src', 'tests', 'experiments', 'benchmarks', 'docs', 'bin', '.github', 'var'], true)) {
                    continue;
                }

                ++$checked;
                self::assertTrue(
                    file_exists($root . '/' . $path) || is_dir($root . '/' . $path),
                    sprintf('Backtick path does not exist in %s: %s', $document, $path),
                );
            }
        }

        self::assertGreaterThan(0, $checked);
    }

    public function testDocumentedExperimentsExist(): void
    {
        $root = ProjectRoot::path();
        $readme = (string) file_get_contents($root . '/README.md');
        $registry = new Registry($root . '/experiments');

        preg_match_all('/ARGS="(?P<name>[a-z][a-z0-9-]*:[a-z][a-z0-9-]*)/', $readme, $matches);

        self::assertNotEmpty($matches['name']);

        foreach ($matches['name'] as $name) {
            self::assertTrue($registry->has($name), sprintf('Documented experiment does not exist: %s', $name));
        }
    }
}
