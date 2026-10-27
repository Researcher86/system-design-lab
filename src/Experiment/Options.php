<?php

declare(strict_types=1);

namespace SystemDesignLab\Experiment;

use InvalidArgumentException;

/**
 * Validated options for an experiment.
 *
 * The names are shared by every experiment; a descriptor declares which ones
 * it actually reads. An unknown or misplaced option is refused instead of
 * being silently ignored, because a typo in a book command must fail loudly.
 */
final readonly class Options
{
    /**
     * Every option the lab understands. Individual experiments declare the
     * subset they read, and the runner refuses the rest.
     *
     * @var array<string, string>
     */
    public const KNOWN = [
        'seed' => 'seed for a reproducible simulation',
        'requests' => 'number of requests in a simulation',
        'tenants' => 'number of tenants',
        'links' => 'number of short links',
        'limit' => 'policy limit: tokens, requests or candidates',
        'capacity' => 'capacity of a bucket, window or cache node',
        'window-ms' => 'window length in milliseconds',
        'refill-per-second' => 'token-bucket refill rate',
        'ids' => 'number of generated identifiers',
        'code-length' => 'short-code length in base62 characters',
        'users' => 'number of users in a notification simulation',
        'channels' => 'number of notification channels',
        'subscriptions' => 'number of webhook subscriptions',
        'events' => 'number of events',
        'duplicates' => 'number of duplicate deliveries to inject',
        'ttl-ms' => 'deduplication or lease TTL in milliseconds',
        'workers' => 'number of workers',
        'jobs' => 'number of jobs',
        'lease-ms' => 'job lease or visibility timeout in milliseconds',
        'max-attempts' => 'maximum delivery attempts before dead-lettering',
        'fail-ms' => 'how long a simulated endpoint stays down',
        'nodes' => 'number of cache nodes',
        'keys' => 'number of cache keys',
        'replicas' => 'replication factor',
        'vnodes' => 'virtual nodes per physical node',
        'shards' => 'number of search shards',
        'docs' => 'number of search documents',
        'queries' => 'number of search queries',
        'vocabulary' => 'number of distinct terms in a generated corpus',
        'terms-per-doc' => 'approximate terms per generated search document',
        'top-k' => 'number of results to keep',
        'alpha' => 'Zipf alpha',
        'rate' => 'generic rate per second',
        'mode' => 'strategy or processing mode',
        'prefix' => 'autocomplete prefix',
        'query' => 'search query',
        'endpoint' => 'simulated endpoint profile',
        'scenario' => 'scenario variant',
        'ticks' => 'number of simulation ticks',
        'tick-ms' => 'length of one simulation tick in milliseconds',
        'producer-rate' => 'jobs produced per tick',
        'consumer-rate' => 'jobs consumed per tick',
        'delay-ms' => 'delivery delay in milliseconds',
        'priority' => 'job priority; higher runs first',
        'fail-every' => 'fail every Nth operation, 0 disables failures',
        'digest' => 'whether notifications are digested',
        'quiet-start' => 'quiet-hours start hour, 0-23',
        'quiet-end' => 'quiet-hours end hour, 0-23',
        'day-ms' => 'length of a digest day in milliseconds',
        'circuit-threshold' => 'failures before a circuit opens',
        'cooldown-ms' => 'circuit-breaker cooldown in milliseconds',
        'jitter-ms' => 'maximum deterministic retry jitter',
        'crash' => 'inject a worker crash after sending',
        'warmup' => 'number of warm-up operations',
        'strategy' => 'implementation selected by the experiment',
        'rounds' => 'number of simulation rounds',
    ];

    private const POSITIVE_INTEGER_OPTIONS = [
        'requests',
        'tenants',
        'links',
        'limit',
        'capacity',
        'window-ms',
        'ids',
        'code-length',
        'users',
        'channels',
        'subscriptions',
        'events',
        'duplicates',
        'workers',
        'jobs',
        'lease-ms',
        'max-attempts',
        'nodes',
        'keys',
        'replicas',
        'vnodes',
        'shards',
        'docs',
        'queries',
        'vocabulary',
        'terms-per-doc',
        'top-k',
        'day-ms',
        'circuit-threshold',
        'cooldown-ms',
        'ticks',
        'tick-ms',
        'producer-rate',
        'consumer-rate',
        'rounds',
    ];

    private const NON_NEGATIVE_INTEGER_OPTIONS = [
        'ttl-ms',
        'fail-ms',
        'delay-ms',
        'fail-every',
        'jitter-ms',
        'warmup',
    ];

    private const FLOAT_OPTIONS = [
        'alpha' => true,
        'rate' => false,
        'refill-per-second' => false,
    ];

    private const BOOLEAN_OPTIONS = [
        'digest',
        'crash',
    ];

    /**
     * @param array<string, int|float|string|bool> $values
     */
    private function __construct(
        private array $values,
    ) {
    }

    /**
     * @param array<string, string> $raw
     *
     * @throws InvalidArgumentException on an unknown option or an invalid value
     */
    public static function fromStrings(array $raw): self
    {
        $values = [];

        foreach ($raw as $name => $value) {
            if (!isset(self::KNOWN[$name])) {
                throw new InvalidArgumentException(sprintf(
                    'Unknown option --%s. Known options: %s',
                    $name,
                    implode(', ', array_keys(self::KNOWN)),
                ));
            }

            if (in_array($name, self::POSITIVE_INTEGER_OPTIONS, true)) {
                if (preg_match('/^\d+$/', $value) !== 1 || (int) $value < 1) {
                    throw new InvalidArgumentException(sprintf(
                        '--%s must be a positive integer, got "%s"',
                        $name,
                        $value,
                    ));
                }

                $values[$name] = (int) $value;

                continue;
            }

            if (in_array($name, self::NON_NEGATIVE_INTEGER_OPTIONS, true)) {
                if (preg_match('/^\d+$/', $value) !== 1) {
                    throw new InvalidArgumentException(sprintf(
                        '--%s must be zero or a positive integer, got "%s"',
                        $name,
                        $value,
                    ));
                }

                $values[$name] = (int) $value;

                continue;
            }

            if ($name === 'priority') {
                if (preg_match('/^-?\d+$/', $value) !== 1) {
                    throw new InvalidArgumentException(sprintf(
                        '--priority must be an integer, got "%s"',
                        $value,
                    ));
                }

                $values[$name] = (int) $value;

                continue;
            }

            if (in_array($name, ['quiet-start', 'quiet-end'], true)) {
                if (preg_match('/^\d+$/', $value) !== 1 || (int) $value > 23) {
                    throw new InvalidArgumentException(sprintf(
                        '--%s must be an hour between 0 and 23, got "%s"',
                        $name,
                        $value,
                    ));
                }

                $values[$name] = (int) $value;

                continue;
            }

            if (isset(self::FLOAT_OPTIONS[$name])) {
                $number = is_numeric($value) ? (float) $value : -1.0;
                $minimumIsPositive = self::FLOAT_OPTIONS[$name];

                if ($number < 0.0 || ($minimumIsPositive && $number == 0.0)) {
                    throw new InvalidArgumentException(sprintf(
                        '--%s must be %s, got "%s"',
                        $name,
                        $minimumIsPositive ? 'positive' : 'zero or positive',
                        $value,
                    ));
                }

                $values[$name] = $number;

                continue;
            }

            if (in_array($name, self::BOOLEAN_OPTIONS, true)) {
                $parsed = filter_var($value, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE);

                if ($parsed === null) {
                    throw new InvalidArgumentException(sprintf(
                        '--%s must be true or false, got "%s"',
                        $name,
                        $value,
                    ));
                }

                $values[$name] = $parsed;

                continue;
            }

            if (trim($value) === '') {
                throw new InvalidArgumentException(sprintf('--%s cannot be empty', $name));
            }

            $values[$name] = $value;
        }

        return new self($values);
    }

    public static function none(): self
    {
        return new self([]);
    }

    public function seed(string $default): string
    {
        return (string) ($this->values['seed'] ?? $default);
    }

    public function int(string $name, int $default): int
    {
        if (!array_key_exists($name, $this->values)) {
            return $default;
        }

        return (int) $this->values[$name];
    }

    public function float(string $name, float $default): float
    {
        if (!array_key_exists($name, $this->values)) {
            return $default;
        }

        return (float) $this->values[$name];
    }

    public function string(string $name, string $default): string
    {
        return (string) ($this->values[$name] ?? $default);
    }

    public function bool(string $name, bool $default): bool
    {
        if (!array_key_exists($name, $this->values)) {
            return $default;
        }

        return (bool) $this->values[$name];
    }

    /**
     * @return list<string>
     */
    public function names(): array
    {
        return array_keys($this->values);
    }

    /**
     * @return array<string, int|float|string|bool>
     */
    public function toArray(): array
    {
        return $this->values;
    }
}
