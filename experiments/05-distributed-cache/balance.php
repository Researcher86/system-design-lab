<?php

declare(strict_types=1);

use SystemDesignLab\DistributedCache\HashRing;
use SystemDesignLab\DistributedCache\HashStrategy;
use SystemDesignLab\DistributedCache\ModuloHash;
use SystemDesignLab\DistributedCache\RendezvousHash;
use SystemDesignLab\Experiment\Experiment;
use SystemDesignLab\Experiment\Options;
use SystemDesignLab\Experiment\Output;
use SystemDesignLab\Foundation\DeterministicRandom;
use SystemDesignLab\Foundation\ZipfDistribution;

/**
 * Does an even key count mean an even traffic share?
 *
 * Key placement is only half of the story: the cache pays for the requests
 * that land on the busiest node. A single token per physical node leaves the
 * ring arcs to chance; virtual nodes make them smaller and more even. Modulo
 * and rendezvous hashing are included as controls.
 */
return new Experiment(
    name: 'cache:balance',
    description: 'key and Zipf traffic distribution across modulo, ring and rendezvous placement',
    supports: ['keys', 'requests', 'nodes', 'vnodes', 'alpha'],
    run: static function (Options $options, Output $output): void {
        $keys = $options->int('keys', 20_000);
        $requests = $options->int('requests', 100_000);
        $nodes = $options->int('nodes', 8);
        $vnodes = $options->int('vnodes', 150);
        $alpha = $options->float('alpha', 1.0);

        if ($nodes > $keys) {
            throw new InvalidArgumentException('cache:balance needs at least one key per node');
        }

        $output->heading(
            'Cache balance',
            sprintf(
                '%s keys, %s Zipf requests, %d nodes, alpha %.2f.',
                number_format($keys),
                number_format($requests),
                $nodes,
                $alpha,
            ),
        );
        $output->line();

        $random = new DeterministicRandom('cache-balance');
        $zipf = new ZipfDistribution($keys, $alpha);
        $requestKeys = [];

        for ($index = 0; $index < $requests; ++$index) {
            $requestKeys[] = 'link-' . $zipf->sample($random);
        }

        $strategies = [
            'modulo' => static function () use ($nodes): HashStrategy {
                $hash = new ModuloHash();

                for ($index = 0; $index < $nodes; ++$index) {
                    $hash->addNode('cache-' . $index);
                }

                return $hash;
            },
            'ring v=1' => static function () use ($nodes): HashStrategy {
                $ring = new HashRing(1);

                for ($index = 0; $index < $nodes; ++$index) {
                    $ring->addNode('cache-' . $index);
                }

                return $ring;
            },
            sprintf('ring v=%d', $vnodes) => static function () use ($nodes, $vnodes): HashStrategy {
                $ring = new HashRing($vnodes);

                for ($index = 0; $index < $nodes; ++$index) {
                    $ring->addNode('cache-' . $index);
                }

                return $ring;
            },
            'rendezvous' => static function () use ($nodes): HashStrategy {
                $hash = new RendezvousHash();

                for ($index = 0; $index < $nodes; ++$index) {
                    $hash->addNode('cache-' . $index);
                }

                return $hash;
            },
        ];
        $rows = [];

        foreach ($strategies as $name => $build) {
            $placement = $build();
            $keyCounts = array_fill_keys($placement->nodes(), 0);
            $trafficCounts = array_fill_keys($placement->nodes(), 0);

            for ($index = 0; $index < $keys; ++$index) {
                $node = $placement->locate('link-' . $index)[0];
                $keyCounts[$node] = ($keyCounts[$node] ?? 0) + 1;
            }

            foreach ($requestKeys as $key) {
                $node = $placement->locate($key)[0];
                $trafficCounts[$node] = ($trafficCounts[$node] ?? 0) + 1;
            }

            $maxTraffic = 0;
            $minTraffic = $requests;
            $maxKeys = 0;
            $mean = $requests / $nodes;
            $variance = 0.0;

            foreach ($keyCounts as $count) {
                $maxKeys = max($maxKeys, $count);
            }

            foreach ($trafficCounts as $count) {
                $maxTraffic = max($maxTraffic, $count);
                $minTraffic = min($minTraffic, $count);
                $variance += ($count - $mean) ** 2;
            }

            $busiest = (string) array_search($maxTraffic, $trafficCounts, true);
            $coefficient = $mean === 0.0 ? 0.0 : sqrt($variance / $nodes) / $mean;
            $rows[] = [
                $name,
                $busiest,
                sprintf('%.1f%%', $maxKeys / $keys * 100),
                sprintf('%.1f%%', $maxTraffic / $requests * 100),
                sprintf('%.1f%%', $minTraffic / $requests * 100),
                sprintf('%.3f', $coefficient),
            ];
            $output->measure('max_traffic_share_' . str_replace([' ', '='], '_', $name), $maxTraffic / $requests);
            $output->measure('traffic_cv_' . str_replace([' ', '='], '_', $name), $coefficient);
        }

        $output->table(
            ['strategy', 'busiest', 'max key share', 'max traffic share', 'min traffic share', 'CV traffic'],
            $rows,
        );
        $output->line();
        $output->line('One token per node leaves the ring arcs to chance; virtual nodes shrink them.');
        $output->line('Modulo and rendezvous hashing already spread independent keys evenly.');
        $output->line('Key count is not traffic share: Zipf popularity can still crowd one node.');
        $output->line('The busiest node sets the capacity and latency the whole cluster needs.');
    },
);
