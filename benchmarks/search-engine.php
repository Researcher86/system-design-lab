<?php

declare(strict_types=1);

use SystemDesignLab\Benchmark\Benchmark;
use SystemDesignLab\Search\Bm25Ranker;
use SystemDesignLab\Search\Corpus;
use SystemDesignLab\Search\InvertedIndex;
use SystemDesignLab\Search\SearchCluster;
use SystemDesignLab\Search\SearchDocument;
use SystemDesignLab\Search\Tokenizer;

$tokenizer = new Tokenizer();
$corpus = Corpus::generate(2_000, 20_000, 80, 'search-benchmark');
$index = new InvertedIndex($tokenizer);
$cluster = new SearchCluster(4, $tokenizer);

foreach ($corpus as $document) {
    $index->addDocument($document);
    $cluster->addDocument($document);
}

$ranker = new Bm25Ranker();
$rare = Corpus::topicTerm(42);
$common = Corpus::wordTerm(1, 20_000);
$mixed = $rare . ' ' . $common;
$growing = new InvertedIndex($tokenizer);
$growingCluster = new SearchCluster(4, $tokenizer);

return [
    new Benchmark(
        name: 'tokenize',
        iterations: 100_000,
        run: static fn (): array => $tokenizer->tokenize('Link 42 topic-0042 word-00001 quick brown fox'),
    ),
    new Benchmark(
        name: 'index document',
        iterations: 2_000,
        run: static function () use ($growing, $rare, $common): void {
            static $sequence = 0;
            $growing->addDocument(new SearchDocument(
                sprintf('bench-%06d', $sequence++),
                'Link ' . $rare,
                $rare . ' ' . $common . ' ' . $common . ' word-00002 word-00003',
            ));
        },
    ),
    new Benchmark(
        name: 'bm25 rare term',
        iterations: 20_000,
        run: static fn (): mixed => $ranker->rank($index, [$rare], 10),
    ),
    new Benchmark(
        name: 'bm25 common term',
        iterations: 2_000,
        run: static fn (): mixed => $ranker->rank($index, [$common], 10),
    ),
    new Benchmark(
        name: 'bm25 mixed terms',
        iterations: 2_000,
        run: static fn (): mixed => $ranker->rank($index, [$rare, $common], 10),
    ),
    new Benchmark(
        name: 'prefix complete',
        iterations: 100_000,
        run: static fn (): array => $index->complete('word00', 10),
    ),
    new Benchmark(
        name: 'cluster search 4 rare',
        iterations: 10_000,
        run: static fn (): mixed => $cluster->search($rare, 10),
    ),
    new Benchmark(
        name: 'cluster search 4 common',
        iterations: 1_000,
        run: static fn (): mixed => $cluster->search($common, 10),
    ),
    new Benchmark(
        name: 'cluster index document',
        iterations: 2_000,
        run: static function () use ($growingCluster, $rare, $common): void {
            static $sequence = 0;
            $growingCluster->addDocument(new SearchDocument(
                sprintf('bench-%06d', $sequence++),
                'Link ' . $rare,
                $rare . ' ' . $common . ' ' . $common . ' word-00002 word-00003',
            ));
        },
    ),
];
