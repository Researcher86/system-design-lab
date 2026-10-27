<?php

declare(strict_types=1);

use SystemDesignLab\Benchmark\Benchmark;
use SystemDesignLab\Foundation\DeterministicRandom;
use SystemDesignLab\Shortener\Base62;
use SystemDesignLab\Shortener\HashIdGenerator;
use SystemDesignLab\Shortener\InMemoryLinkRepository;
use SystemDesignLab\Shortener\RandomIdGenerator;
use SystemDesignLab\Shortener\SequentialIdGenerator;
use SystemDesignLab\Shortener\UrlShortener;

$sequential = new SequentialIdGenerator();
$random = new RandomIdGenerator(Base62::capacity(7), new DeterministicRandom('url-shortener'));
$hash = new HashIdGenerator(Base62::capacity(7));

$resolveRepository = new InMemoryLinkRepository();
$resolveShortener = new UrlShortener(new SequentialIdGenerator(), $resolveRepository);
$codes = [];

for ($i = 0; $i < 1_000; ++$i) {
    $codes[] = $resolveShortener->shorten('tenant-a', 'https://example.com/preloaded/' . $i)->link->code;
}

$createRepository = new InMemoryLinkRepository();
$createShortener = new UrlShortener(new SequentialIdGenerator(), $createRepository);

$idempotentShortener = new UrlShortener(new SequentialIdGenerator(), new InMemoryLinkRepository());
$idempotentShortener->shorten('tenant-a', 'https://example.com/idempotent');

return [
    new Benchmark(
        name: 'base62 encode 7',
        iterations: 100_000,
        run: static fn (): string => Base62::encode(3_521_614_606_207, 7),
    ),
    new Benchmark(
        name: 'base62 decode 7',
        iterations: 100_000,
        run: static fn (): int => Base62::decode('zzzzzzz'),
    ),
    new Benchmark(
        name: 'sequential id',
        iterations: 100_000,
        run: static fn (): int => $sequential->next('tenant-a', 'https://example.com/article'),
    ),
    new Benchmark(
        name: 'random id',
        iterations: 100_000,
        run: static fn (): int => $random->next('tenant-a', 'https://example.com/article'),
    ),
    new Benchmark(
        name: 'hash id',
        iterations: 100_000,
        run: static fn (): int => $hash->next('tenant-a', 'https://example.com/article'),
    ),
    new Benchmark(
        name: 'shorten new link',
        iterations: 5_000,
        run: static function () use ($createShortener): mixed {
            static $index = 0;

            return $createShortener->shorten(
                'tenant-a',
                'https://example.com/created/' . $index++,
            );
        },
    ),
    new Benchmark(
        name: 'shorten idempotent hit',
        iterations: 50_000,
        run: static fn (): mixed => $idempotentShortener->shorten(
            'tenant-a',
            'https://example.com/idempotent',
        ),
    ),
    new Benchmark(
        name: 'resolve hit',
        iterations: 100_000,
        run: static function () use ($resolveShortener, $codes): mixed {
            static $index = 0;

            return $resolveShortener->resolve($codes[$index++ % 1_000]);
        },
    ),
    new Benchmark(
        name: 'resolve miss',
        iterations: 100_000,
        run: static fn (): mixed => $resolveShortener->resolve('zzzzzzz'),
    ),
];
