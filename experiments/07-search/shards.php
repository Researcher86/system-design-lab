<?php

declare(strict_types=1);

use SystemDesignLab\Experiment\Experiment;
use SystemDesignLab\Experiment\Options;
use SystemDesignLab\Experiment\Output;
use SystemDesignLab\Foundation\DeterministicRandom;
use SystemDesignLab\Search\Bm25Ranker;
use SystemDesignLab\Search\Corpus;
use SystemDesignLab\Search\InvertedIndex;
use SystemDesignLab\Search\SearchCluster;
use SystemDesignLab\Search\SearchHit;
use SystemDesignLab\Search\Tokenizer;

/**
 * What does fan-out buy, and what does shard-local scoring cost?
 *
 * The same corpus is indexed once globally and once per shard count. Every
 * query runs on both. Recall@k compares the returned ids with the global
 * ranking; postings and candidates show the work. Each shard normalizes BM25
 * against its own document count, average length and document frequencies,
 * so the merged order is not guaranteed to equal the global order.
 */
return new Experiment(
    name: 'search:shards',
    description: 'fan-out cost and ranking drift when one index is split into shards',
    supports: ['docs', 'vocabulary', 'terms-per-doc', 'queries', 'top-k', 'shards', 'seed'],
    run: static function (Options $options, Output $output): void {
        $documents = $options->int('docs', 2_000);
        $vocabulary = $options->int('vocabulary', 20_000);
        $termsPerDoc = $options->int('terms-per-doc', 80);
        $queries = $options->int('queries', 100);
        $topK = $options->int('top-k', 10);
        $maxShards = $options->int('shards', 8);
        $seed = $options->seed('search-shards');

        $output->heading(
            'Search shards',
            sprintf(
                '%s documents, %d queries per shape, top-%d, shard counts up to %d.',
                number_format($documents),
                $queries,
                $topK,
                $maxShards,
            ),
        );
        $output->line();

        $tokenizer = new Tokenizer();
        $corpus = Corpus::generate($documents, $vocabulary, $termsPerDoc, $seed);
        $global = new InvertedIndex($tokenizer);

        foreach ($corpus as $document) {
            $global->addDocument($document);
        }

        $ranker = new Bm25Ranker();
        $random = new DeterministicRandom($seed . ':queries');
        $commonRanks = min(50, $vocabulary);
        $queryRows = [];

        for ($index = 0; $index < $queries; ++$index) {
            $topic = Corpus::topicTerm($random->intBetween(0, Corpus::TOPICS - 1));
            $common = Corpus::wordTerm($random->intBetween(1, $commonRanks), $vocabulary);
            $queryRows[] = ['rare', $topic];
            $queryRows[] = ['mixed', $topic . ' ' . $common];
        }

        /** @var list<list<string>> $globalIds */
        $globalIds = [];
        $globalPostings = 0;
        $globalCandidates = 0;

        foreach ($queryRows as $row) {
            $ranked = $ranker->rank($global, $tokenizer->tokenize($row[1]), $topK);
            $globalIds[] = array_map(
                static fn (SearchHit $hit): string => $hit->docId,
                $ranked->hits,
            );
            $globalPostings += $ranked->postingsScanned;
            $globalCandidates += $ranked->candidatesScored;
        }

        $shardCounts = [1];
        $current = 1;

        while ($current < $maxShards) {
            $current = min($maxShards, $current * 2);
            $shardCounts[] = $current;
        }

        $costRows = [];
        $rankingRows = [];

        foreach ($shardCounts as $shards) {
            $cluster = new SearchCluster($shards);

            foreach ($corpus as $document) {
                $cluster->addDocument($document);
            }

            $counts = [];

            for ($index = 0; $index < $shards; ++$index) {
                $counts[] = $cluster->documentsInShard($index);
            }

            $postings = 0;
            $candidates = 0;
            $shapeTotals = [
                'rare' => ['overlap' => 0.0, 'top1' => 0, 'count' => 0],
                'mixed' => ['overlap' => 0.0, 'top1' => 0, 'count' => 0],
            ];

            foreach ($queryRows as $queryIndex => $row) {
                $response = $cluster->search($row[1], $topK);
                $postings += $response->postingsScanned;
                $candidates += $response->candidatesScored;
                $expectedIds = $globalIds[$queryIndex];
                $actualIds = array_map(
                    static fn (SearchHit $hit): string => $hit->docId,
                    $response->results,
                );
                $overlap = $expectedIds === []
                    ? 0.0
                    : count(array_intersect($expectedIds, $actualIds)) / count($expectedIds);
                $shapeTotals[$row[0]]['overlap'] += $overlap;
                $shapeTotals[$row[0]]['count'] += 1;
                $shapeTotals[$row[0]]['top1'] += $expectedIds !== [] && $actualIds !== [] && $expectedIds[0] === $actualIds[0] ? 1 : 0;
            }

            $queryCount = count($queryRows);
            $costRows[] = [
                (string) $shards,
                sprintf('%d/%d', min($counts), max($counts)),
                sprintf('%.1f', $postings / $queryCount),
                sprintf('%.1f', $candidates / $queryCount),
                (string) $shards,
            ];
            $rankingRows[] = [
                (string) $shards,
                sprintf('%.1f%%', $shapeTotals['rare']['overlap'] / $shapeTotals['rare']['count'] * 100),
                sprintf('%.1f%%', $shapeTotals['mixed']['overlap'] / $shapeTotals['mixed']['count'] * 100),
                sprintf('%.1f%%', $shapeTotals['rare']['top1'] / $shapeTotals['rare']['count'] * 100),
                sprintf('%.1f%%', $shapeTotals['mixed']['top1'] / $shapeTotals['mixed']['count'] * 100),
            ];
            $output->measure('avg_postings_' . $shards, $postings / $queryCount);
            $output->measure('avg_candidates_' . $shards, $candidates / $queryCount);
            $output->measure('rare_recall_' . $shards, $shapeTotals['rare']['overlap'] / $shapeTotals['rare']['count']);
            $output->measure('mixed_recall_' . $shards, $shapeTotals['mixed']['overlap'] / $shapeTotals['mixed']['count']);
            $output->measure('rare_top1_' . $shards, $shapeTotals['rare']['top1'] / $shapeTotals['rare']['count']);
            $output->measure('mixed_top1_' . $shards, $shapeTotals['mixed']['top1'] / $shapeTotals['mixed']['count']);
        }

        $output->section('Fan-out cost');
        $output->table(
            ['shards', 'docs/shard min/max', 'postings', 'candidates', 'shard queries'],
            $costRows,
        );
        $output->line(sprintf(
            'A single global index scans %.1f postings and scores %.1f candidates per query.',
            $globalPostings / count($queryRows),
            $globalCandidates / count($queryRows),
        ));

        $output->section('Ranking against a single global index');
        $output->table(
            ['shards', 'rare recall@k', 'mixed recall@k', 'rare top-1', 'mixed top-1'],
            $rankingRows,
        );

        $output->line();
        $output->line('Every query is sent to every shard; the merge takes the best k from each local top-k.');
        $output->line('Shard-local document counts and frequencies change the score scale, so recall can fall below 100%.');
        $output->line('The counters are exact; the ranking comparison is synthetic relevance, not a search-quality benchmark.');
        $output->line('A production cluster would need global statistics or a score-normalization step to make shard scores comparable.');
    },
);
