<?php

declare(strict_types=1);

use SystemDesignLab\DistributedCache\CacheCluster;
use SystemDesignLab\DistributedCache\HashRing;
use SystemDesignLab\DistributedCache\LruStore;
use SystemDesignLab\Experiment\Experiment;
use SystemDesignLab\Experiment\Options;
use SystemDesignLab\Experiment\Output;
use SystemDesignLab\Foundation\DeterministicRandom;
use SystemDesignLab\Foundation\ZipfDistribution;

/**
 * What does a cache node failure cost with and without replication?
 *
 * Both clusters are warmed on the same request stream, measured before the
 * failure, then the busiest node is failed with data loss. With RF=1 the keys
 * on that node miss until the node returns and is filled again. With RF=2 the
 * replica serves them; a read after recovery repairs the primary.
 */
return new Experiment(
    name: 'cache:failure',
    description: 'hit ratio before, during and after a cache node failure at RF=1 and RF=2',
    supports: ['keys', 'requests', 'capacity', 'nodes', 'vnodes', 'alpha', 'warmup'],
    run: static function (Options $options, Output $output): void {
        $keys = $options->int('keys', 20_000);
        $requests = $options->int('requests', 20_000);
        $capacity = $options->int('capacity', 1_250);
        $nodes = $options->int('nodes', 4);
        $vnodes = $options->int('vnodes', 150);
        $alpha = $options->float('alpha', 1.0);
        $warmup = $options->int('warmup', 50_000);

        if ($nodes < 2) {
            throw new InvalidArgumentException('cache:failure needs at least two nodes');
        }

        $output->heading(
            'Cache failure',
            sprintf(
                '%d nodes, %s per node, %s warm-up requests, %s requests per phase.',
                $nodes,
                number_format($capacity),
                number_format($warmup),
                number_format($requests),
            ),
        );
        $output->line();

        $random = new DeterministicRandom('cache-failure');
        $zipf = new ZipfDistribution($keys, $alpha);
        $stream = [];

        for ($index = 0; $index < $warmup + $requests * 3; ++$index) {
            $stream[] = 'link-' . $zipf->sample($random);
        }

        /**
         * @param list<string> $stream
         */
        $runRequests = static function (CacheCluster $cluster, array $stream, int $start, int $count): void {
            for ($index = $start; $index < $start + $count; ++$index) {
                $key = $stream[$index];
                $lookup = $cluster->get($key);

                if (!$lookup->found) {
                    $cluster->put($key, 'value-' . $key);
                }
            }
        };

        /**
         * @return array{reads: int, primary: int, replica: int, misses: int, repairs: int}
         */
        $snapshot = static fn (CacheCluster $cluster): array => [
            'reads' => $cluster->counters()->get('reads'),
            'primary' => $cluster->counters()->get('primaryHits'),
            'replica' => $cluster->counters()->get('replicaHits'),
            'misses' => $cluster->counters()->get('misses'),
            'repairs' => $cluster->counters()->get('readRepairs'),
        ];

        /**
         * @return array{replicas: int, lost: int, phases: array<string, array{reads: int, primary: int, replica: int, misses: int, repairs: int}>}
         */
        $runVariant = static function (int $replicas) use ($nodes, $capacity, $vnodes, $warmup, $requests, $stream, $runRequests, $snapshot): array {
            $placement = new HashRing($vnodes);
            $stores = [];

            for ($index = 0; $index < $nodes; ++$index) {
                $node = 'cache-' . $index;
                $placement->addNode($node);
                $stores[$node] = new LruStore($capacity);
            }

            $cluster = new CacheCluster($placement, $stores, $replicas);
            $runRequests($cluster, $stream, 0, $warmup);
            $cluster->counters()->reset();

            $runRequests($cluster, $stream, $warmup, $requests);
            $before = $snapshot($cluster);

            $lost = $cluster->failNode('cache-0');
            $cluster->counters()->reset();

            $runRequests($cluster, $stream, $warmup + $requests, $requests);
            $during = $snapshot($cluster);

            $cluster->recoverNode('cache-0');
            $cluster->counters()->reset();

            $runRequests($cluster, $stream, $warmup + $requests * 2, $requests);
            $after = $snapshot($cluster);

            return [
                'replicas' => $replicas,
                'lost' => $lost,
                'phases' => ['before' => $before, 'during' => $during, 'after' => $after],
            ];
        };

        $variants = [$runVariant(1), $runVariant(2)];
        $rows = [];

        foreach ($variants as $variant) {
            foreach ($variant['phases'] as $phase => $values) {
                $hits = $values['primary'] + $values['replica'];
                $rows[] = [
                    $variant['replicas'],
                    $phase,
                    number_format($values['reads']),
                    number_format($hits),
                    sprintf('%.1f%%', $values['reads'] === 0 ? 0.0 : $hits / $values['reads'] * 100),
                    number_format($values['primary']),
                    number_format($values['replica']),
                    number_format($values['misses']),
                    number_format($values['repairs']),
                ];
                $output->measure(sprintf('rf%d_%s_hits', $variant['replicas'], $phase), $hits);
                $output->measure(sprintf('rf%d_%s_misses', $variant['replicas'], $phase), $values['misses']);
            }

            $output->line(sprintf(
                'RF=%d lost %s entries when cache-0 failed.',
                $variant['replicas'],
                number_format($variant['lost']),
            ));
        }

        $output->line();
        $output->table(
            ['RF', 'phase', 'reads', 'hits', 'hit ratio', 'primary', 'replica', 'misses', 'repairs'],
            $rows,
        );
        $output->line();
        $output->line('RF=1 loses the keys on the failed node until they are loaded again.');
        $output->line('RF=2 serves those keys from the replica while the primary is down.');
        $output->line('After recovery, a replica hit repairs the empty primary on the next read.');
        $output->line('Replication buys availability with memory; it does not remove the cache miss.');
    },
);
