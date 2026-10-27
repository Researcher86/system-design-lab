<?php

declare(strict_types=1);

use SystemDesignLab\Benchmark\Benchmark;
use SystemDesignLab\DistributedCache\CacheCluster;
use SystemDesignLab\DistributedCache\HashRing;
use SystemDesignLab\DistributedCache\LruStore;
use SystemDesignLab\DistributedCache\ModuloHash;
use SystemDesignLab\DistributedCache\RendezvousHash;

$ring = new HashRing(150);
$rendezvous = new RendezvousHash();
$modulo = new ModuloHash();

for ($index = 0; $index < 20; ++$index) {
    $node = 'cache-' . $index;
    $ring->addNode($node);
    $rendezvous->addNode($node);
    $modulo->addNode($node);
}

$lru = new LruStore(10_000);
$evicting = new LruStore(1_000);

for ($index = 0; $index < 10_000; ++$index) {
    $lru->put('link-' . $index, 'value-' . $index);
}

$stores = [];

foreach ($ring->nodes() as $node) {
    $stores[$node] = new LruStore(10_000);
}

$cluster = new CacheCluster($ring, $stores, 1);
$replicatedStores = [];

foreach ($ring->nodes() as $node) {
    $replicatedStores[$node] = new LruStore(20_000);
}

$replicated = new CacheCluster($ring, $replicatedStores, 2);

for ($index = 0; $index < 10_000; ++$index) {
    $cluster->put('link-' . $index, 'value-' . $index);
}

return [
    new Benchmark(
        name: 'modulo locate',
        iterations: 100_000,
        run: static function () use ($modulo): string {
            static $index = 0;

            return $modulo->locate('link-' . $index++)[0];
        },
    ),
    new Benchmark(
        name: 'ring locate',
        iterations: 100_000,
        run: static function () use ($ring): string {
            static $index = 0;

            return $ring->locate('link-' . $index++)[0];
        },
    ),
    new Benchmark(
        name: 'ring locate rf3',
        iterations: 100_000,
        run: static function () use ($ring): array {
            static $index = 0;

            return $ring->locate('link-' . $index++, 3);
        },
    ),
    new Benchmark(
        name: 'rendezvous locate',
        iterations: 100_000,
        run: static function () use ($rendezvous): string {
            static $index = 0;

            return $rendezvous->locate('link-' . $index++)[0];
        },
    ),
    new Benchmark(
        name: 'lru get hit',
        iterations: 100_000,
        run: static function () use ($lru): mixed {
            static $index = 0;

            return $lru->get('link-' . ($index++ % 10_000));
        },
    ),
    new Benchmark(
        name: 'lru put evict',
        iterations: 20_000,
        run: static function () use ($evicting): void {
            static $index = 0;

            $evicting->put('new-' . $index++, 'value');
        },
    ),
    new Benchmark(
        name: 'cluster get rf1',
        iterations: 100_000,
        run: static function () use ($cluster): mixed {
            static $index = 0;

            return $cluster->get('link-' . ($index++ % 10_000));
        },
    ),
    new Benchmark(
        name: 'cluster put rf2',
        iterations: 20_000,
        run: static function () use ($replicated): void {
            static $index = 0;

            $replicated->put('new-' . $index++, 'value');
        },
    ),
];
