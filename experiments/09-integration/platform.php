<?php

declare(strict_types=1);

use SystemDesignLab\Experiment\Experiment;
use SystemDesignLab\Experiment\Options;
use SystemDesignLab\Experiment\Output;
use SystemDesignLab\Foundation\ProjectRoot;
use SystemDesignLab\Integration\ShortLinkPlatformScenario;

/**
 * One deterministic flow through every chapter of the lab.
 *
 * The scenario is the MVP, not another benchmark. It admits a tenant request
 * stream, creates and resolves links, plans notifications and webhooks, drains
 * one job queue, fails a cache node, and checks the invariants that tie the
 * counters together. It writes both a text and a JSON report to var/results/,
 * so `make scenario` leaves an artifact even when stdout is redirected.
 */
return new Experiment(
    name: 'scenario:short-link-platform',
    description: 'one end-to-end flow through rate limiting, short links, notifications, queue, cache, webhooks and search',
    supports: [
        'seed',
        'requests',
        'tenants',
        'users',
        'links',
        'capacity',
        'refill-per-second',
        'nodes',
        'replicas',
        'shards',
        'max-attempts',
        'fail-every',
        'delay-ms',
        'fail-ms',
    ],
    run: static function (Options $options, Output $output): void {
        $scenario = new ShortLinkPlatformScenario(
            seed: $options->seed('short-link-platform'),
            requests: $options->int('requests', 900),
            tenants: $options->int('tenants', 3),
            users: $options->int('users', 20),
            links: $options->int('links', 300),
            capacity: $options->int('capacity', 20),
            refillPerSecond: $options->float('refill-per-second', 25.0),
            nodes: $options->int('nodes', 3),
            replicas: $options->int('replicas', 2),
            shards: $options->int('shards', 4),
            maxAttempts: $options->int('max-attempts', 4),
            failEvery: $options->int('fail-every', 7),
            delayMs: $options->int('delay-ms', 50),
            failMs: $options->int('fail-ms', 100),
        );
        $report = $scenario->run();

        foreach ($report->lines() as $line) {
            $output->line($line);
        }

        foreach ($report->measurements() as $name => $value) {
            $output->measure($name, $value);
        }

        $root = ProjectRoot::path();
        $report->writeText($root . '/var/results/scenario-short-link-platform.txt');
        $report->writeJson(
            $root . '/var/results/scenario-short-link-platform.json',
            'short-link-platform',
            $scenario->options(),
        );

        if (($report->measurements()['invariants_ok'] ?? false) !== true) {
            throw new RuntimeException('The scenario invariants failed; see var/results/scenario-short-link-platform.txt');
        }
    },
);
