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
 * What does partitioning do to a cache hit ratio?
 *
 * One large LRU has a single recency order. A cluster cuts the same total
 * memory into per-node orders, so a hot key can evict a neighbour in its own
 * shard even when another shard has spare room. Replication at the same total
 * memory stores fewer unique keys, which the failure experiment then pays back.
 *
 * Every variant starts cold and sees the same seeded Zipf request stream.
 */
return new Experiment(
    name: 'cache:hit-ratio',
    description: 'hit ratio for one LRU, sharded LRUs and replicated LRUs at the same total capacity',
    supports: ['keys', 'requests', 'capacity', 'nodes', 'vnodes', 'alpha'],
    run: static function (Options $options, Output $output): void {
        $keys = $options->int('keys', 20_000);
        $requests = $options->int('requests', 100_000);
        $capacity = $options->int('capacity', 5_000);
        $nodes = $options->int('nodes', 4);
        $vnodes = $options->int('vnodes', 150);
        $alpha = $options->float('alpha', 1.0);

        $output->heading(
            'Cache hit ratio',
            sprintf(
                '%s keys, %s Zipf requests, total capacity %s, %d base nodes, alpha %.2f.',
                number_format($keys),
                number_format($requests),
                number_format($capacity),
                $nodes,
                $alpha,
            ),
        );
        $output->line();

        /**
         * @return array{label: string, nodes: int, replicas: int, perNode: int, hits: int, misses: int, evictions: int}
         */
        $runVariant = static function (
            string $label,
            int $nodeCount,
            int $perNode,
            int $replicas,
            int $keys,
            int $requests,
            int $vnodes,
            float $alpha,
        ): array {
            $placement = new HashRing($vnodes);
            $stores = [];

            for ($index = 0; $index < $nodeCount; ++$index) {
                $node = 'cache-' . $index;
                $placement->addNode($node);
                $stores[$node] = new LruStore($perNode);
            }

            $cluster = new CacheCluster($placement, $stores, $replicas);
            $random = new DeterministicRandom('cache-hit-ratio');
            $zipf = new ZipfDistribution($keys, $alpha);

            for ($index = 0; $index < $requests; ++$index) {
                $key = 'link-' . $zipf->sample($random);
                $lookup = $cluster->get($key);

                if (!$lookup->found) {
                    $cluster->put($key, 'value-' . $key);
                }
            }

            $evictions = 0;

            foreach ($stores as $store) {
                $evictions += $store->counters()->get('evictions');
            }

            $hits = $cluster->counters()->get('primaryHits') + $cluster->counters()->get('replicaHits');

            return [
                'label' => $label,
                'nodes' => $nodeCount,
                'replicas' => $replicas,
                'perNode' => $perNode,
                'hits' => $hits,
                'misses' => $cluster->counters()->get('misses'),
                'evictions' => $evictions,
            ];
        };

        $variants = [
            $runVariant('single LRU', 1, $capacity, 1, $keys, $requests, $vnodes, $alpha),
            $runVariant('sharded', $nodes, max(1, intdiv($capacity, $nodes)), 1, $keys, $requests, $vnodes, $alpha),
            $runVariant('sharded 2x', $nodes * 2, max(1, intdiv($capacity, $nodes * 2)), 1, $keys, $requests, $vnodes, $alpha),
            $runVariant('replicated', $nodes, max(1, intdiv($capacity, $nodes)), 2, $keys, $requests, $vnodes, $alpha),
        ];
        $rows = [];

        foreach ($variants as $variant) {
            $hits = $variant['hits'];
            $misses = $variant['misses'];
            $reads = $hits + $misses;
            $rows[] = [
                $variant['label'],
                $variant['nodes'],
                $variant['replicas'],
                number_format($variant['perNode']),
                number_format($hits),
                number_format($misses),
                sprintf('%.1f%%', $reads === 0 ? 0.0 : $hits / $reads * 100),
                number_format($variant['evictions']),
            ];
            $output->measure('hits_' . str_replace(' ', '_', $variant['label']), $hits);
            $output->measure('misses_' . str_replace(' ', '_', $variant['label']), $misses);
        }

        $output->table(
            ['mode', 'nodes', 'RF', 'per-node', 'hits', 'misses', 'hit ratio', 'evictions'],
            $rows,
        );
        $output->line();
        $output->line('A single LRU keeps one global recency order; partitioning keeps one per node.');
        $output->line('At the same total memory, more shards mean smaller per-node working sets.');
        $output->line('Replication stores fewer unique keys because each copy occupies capacity.');
        $output->line('The next experiment shows what that extra copy buys when a node fails.');
    },
);
