<?php

declare(strict_types=1);

use SystemDesignLab\DistributedCache\HashRing;
use SystemDesignLab\DistributedCache\HashStrategy;
use SystemDesignLab\DistributedCache\ModuloHash;
use SystemDesignLab\DistributedCache\RendezvousHash;
use SystemDesignLab\Experiment\Experiment;
use SystemDesignLab\Experiment\Options;
use SystemDesignLab\Experiment\Output;

/**
 * How many keys move when a node joins?
 *
 * Modulo hashing changes the divisor for every key, so almost every mapping
 * changes. Consistent hashing and rendezvous hashing only move the keys whose
 * owner changed: about 1/(N+1) of them. The experiment uses the same key set
 * for all three strategies and one membership change.
 */
return new Experiment(
    name: 'cache:remap',
    description: 'keys moved by modulo, consistent and rendezvous hashing when a node joins',
    supports: ['keys', 'nodes', 'vnodes'],
    run: static function (Options $options, Output $output): void {
        $keys = $options->int('keys', 20_000);
        $nodes = $options->int('nodes', 8);
        $vnodes = $options->int('vnodes', 150);

        $output->heading(
            'Cache remapping',
            sprintf(
                '%s keys, %d -> %d nodes, ring with %d virtual nodes.',
                number_format($keys),
                $nodes,
                $nodes + 1,
                $vnodes,
            ),
        );
        $output->line();

        $strategies = [
            'modulo' => static function () use ($nodes): HashStrategy {
                $hash = new ModuloHash();

                for ($index = 0; $index < $nodes; ++$index) {
                    $hash->addNode('cache-' . $index);
                }

                return $hash;
            },
            'ring' => static function () use ($nodes, $vnodes): HashStrategy {
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
            $before = [];

            for ($index = 0; $index < $keys; ++$index) {
                $key = 'link-' . $index;
                $before[$key] = $placement->locate($key)[0];
            }

            $placement->addNode('cache-' . $nodes);
            $moved = 0;

            for ($index = 0; $index < $keys; ++$index) {
                $key = 'link-' . $index;

                if ($placement->locate($key)[0] !== $before[$key]) {
                    ++$moved;
                }
            }

            $expected = $name === 'modulo' ? $nodes / ($nodes + 1) : 1 / ($nodes + 1);
            $rows[] = [
                $name,
                number_format($moved),
                sprintf('%.1f%%', $moved / $keys * 100),
                sprintf('%.1f%%', $expected * 100),
            ];
            $output->measure('moved_' . $name, $moved);
            $output->measure('moved_share_' . $name, $moved / $keys);
        }

        $output->table(['strategy', 'moved', 'share', 'expected'], $rows);
        $output->line();
        $output->line('Modulo hashing rewrites the divisor for every key at once.');
        $output->line('A ring and rendezvous hashing move only the keys whose owner changed.');
        $output->line('The more nodes there are, the smaller the blast radius of one membership change.');
    },
);
