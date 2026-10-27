<?php

declare(strict_types=1);

namespace SystemDesignLab\Benchmark;

/**
 * What the machine was when the numbers were taken.
 *
 * A benchmark without this block is an anecdote. OPcache, JIT and Xdebug
 * change how the loop executes; the allocator changes what memory_get_usage()
 * means; the cgroup limit decides how much page cache was available.
 */
final readonly class Environment
{
    private function __construct(
        public string $phpVersion,
        public string $os,
        public string $architecture,
        public string $cpu,
        public int $cpuCount,
        public string $memoryLimit,
        public ?int $cgroupMemoryLimit,
        public bool $opcacheEnabled,
        public bool $jitEnabled,
        public string $xdebugMode,
        public string $allocator,
        public string $kernel,
        public string $commit,
    ) {
    }

    public static function detect(): self
    {
        return new self(
            phpVersion: PHP_VERSION,
            os: PHP_OS_FAMILY,
            architecture: php_uname('m'),
            cpu: self::cpuModel(),
            cpuCount: self::cpuCount(),
            memoryLimit: (string) ini_get('memory_limit'),
            cgroupMemoryLimit: self::cgroupMemoryLimit(),
            opcacheEnabled: filter_var(ini_get('opcache.enable_cli'), FILTER_VALIDATE_BOOL),
            jitEnabled: self::jitEnabled(),
            xdebugMode: self::xdebugMode(),
            allocator: getenv('USE_ZEND_ALLOC') === '0' ? 'system malloc' : 'Zend MM',
            kernel: php_uname('r'),
            commit: self::commit(),
        );
    }

    /**
     * @return array<string, scalar|null>
     */
    public function toArray(): array
    {
        return [
            'php_version' => $this->phpVersion,
            'os' => $this->os,
            'architecture' => $this->architecture,
            'cpu' => $this->cpu,
            'cpu_count' => $this->cpuCount,
            'memory_limit' => $this->memoryLimit,
            'cgroup_memory_limit' => $this->cgroupMemoryLimit,
            'opcache' => $this->opcacheEnabled,
            'jit' => $this->jitEnabled,
            'xdebug_mode' => $this->xdebugMode,
            'allocator' => $this->allocator,
            'kernel' => $this->kernel,
            'commit' => $this->commit,
        ];
    }

    private static function cpuModel(): string
    {
        foreach (self::procLines('/proc/cpuinfo') as $line) {
            if (preg_match('/^(model name|Model|Hardware)\s*:\s*(.+)$/', $line, $matches) === 1) {
                return trim($matches[2]);
            }
        }

        return php_uname('m');
    }

    private static function cpuCount(): int
    {
        $count = 0;

        foreach (self::procLines('/proc/cpuinfo') as $line) {
            if (str_starts_with($line, 'processor')) {
                ++$count;
            }
        }

        return max(1, $count);
    }

    private static function cgroupMemoryLimit(): ?int
    {
        foreach (['/sys/fs/cgroup/memory.max', '/sys/fs/cgroup/memory/memory.limit_in_bytes'] as $path) {
            if (!is_readable($path)) {
                continue;
            }

            $value = trim((string) file_get_contents($path));

            if ($value === 'max' || $value === '') {
                return null;
            }

            $limit = (int) $value;

            return $limit > 0 && $limit < PHP_INT_MAX / 2 ? $limit : null;
        }

        return null;
    }

    private static function jitEnabled(): bool
    {
        if (!function_exists('opcache_get_status')) {
            return false;
        }

        $status = @opcache_get_status(false);

        return is_array($status) && ($status['jit']['enabled'] ?? false) === true;
    }

    private static function xdebugMode(): string
    {
        if (!function_exists('xdebug_info')) {
            return 'not installed';
        }

        $info = xdebug_info('mode');

        if (!is_array($info)) {
            return 'unknown';
        }

        $enabled = [];

        foreach ($info as $feature => $active) {
            if ($active === true) {
                $enabled[] = (string) $feature;
            }
        }

        return $enabled === [] ? 'off' : implode(',', $enabled);
    }

    private static function commit(): string
    {
        if (!function_exists('shell_exec')) {
            return 'unknown';
        }

        $commit = shell_exec('git rev-parse --short HEAD 2>/dev/null');

        return is_string($commit) && trim($commit) !== '' ? trim($commit) : 'unknown';
    }

    /**
     * @return list<string>
     */
    private static function procLines(string $path): array
    {
        if (!is_readable($path)) {
            return [];
        }

        return file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
    }
}
