<?php

declare(strict_types=1);

use SystemDesignLab\Experiment\Experiment;
use SystemDesignLab\Experiment\Options;
use SystemDesignLab\Experiment\Output;
use SystemDesignLab\Foundation\DeterministicRandom;
use SystemDesignLab\Search\Bm25Ranker;
use SystemDesignLab\Search\Corpus;
use SystemDesignLab\Search\InvertedIndex;
use SystemDesignLab\Search\RankedResults;
use SystemDesignLab\Search\SearchHit;
use SystemDesignLab\Search\Tokenizer;

/**
 * What does a query actually scan, and how does BM25 change the order?
 *
 * Three query shapes share one index: a rare topic term, a common vocabulary
 * term and both terms together. For every shape the run reports postings
 * scanned, candidates scored and how much of the candidate set survives into
 * top-k. Raw term frequency and BM25 with b=0 are differential baselines: the
 * first ignores length normalization and document frequency; the second keeps
 * IDF but removes the length penalty.
 */
return new Experiment(
    name: 'search:ranking',
    description: 'query cost and ranking overlap for rare, common and mixed terms',
    supports: ['docs', 'vocabulary', 'terms-per-doc', 'queries', 'top-k', 'seed'],
    run: static function (Options $options, Output $output): void {
        $documents = $options->int('docs', 2_000);
        $vocabulary = $options->int('vocabulary', 20_000);
        $termsPerDoc = $options->int('terms-per-doc', 80);
        $queries = $options->int('queries', 200);
        $topK = $options->int('top-k', 10);
        $seed = $options->seed('search-ranking');

        $output->heading(
            'Search ranking',
            sprintf(
                '%s documents, %s queries per shape, top-%d, vocabulary %s.',
                number_format($documents),
                number_format($queries),
                $topK,
                number_format($vocabulary),
            ),
        );
        $output->line();

        $tokenizer = new Tokenizer();
        $index = new InvertedIndex($tokenizer);

        foreach (Corpus::generate($documents, $vocabulary, $termsPerDoc, $seed) as $document) {
            $index->addDocument($document);
        }

        $ranker = new Bm25Ranker();
        $withoutLength = new Bm25Ranker(b: 0.0);
        $random = new DeterministicRandom($seed . ':queries');
        $commonRanks = min(50, $vocabulary);

        $rankByTf = static function (array $tokens, int $limit) use ($index): array {
            /** @var list<string> $tokens */
            $scores = [];

            foreach (array_unique($tokens) as $term) {
                foreach ($index->postings($term) as $docId => $frequency) {
                    $scores[$docId] = ($scores[$docId] ?? 0) + $frequency;
                }
            }

            arsort($scores, SORT_NUMERIC);

            return array_slice(array_keys($scores), 0, $limit);
        };

        /** @return list<string> */
        $ids = static fn (RankedResults $results): array => array_map(
            static fn (SearchHit $hit): string => $hit->docId,
            $results->hits,
        );

        $overlap = static function (array $left, array $right): float {
            /** @var list<string> $left */
            /** @var list<string> $right */
            if ($left === []) {
                return 0.0;
            }

            return count(array_intersect($left, $right)) / count($left);
        };

        $totals = [
            'rare' => ['postings' => 0, 'candidates' => 0, 'returned' => 0, 'tf' => 0.0, 'b0' => 0.0],
            'common' => ['postings' => 0, 'candidates' => 0, 'returned' => 0, 'tf' => 0.0, 'b0' => 0.0],
            'mixed' => ['postings' => 0, 'candidates' => 0, 'returned' => 0, 'tf' => 0.0, 'b0' => 0.0],
        ];

        for ($query = 0; $query < $queries; ++$query) {
            $topic = $random->intBetween(0, Corpus::TOPICS - 1);
            $common = Corpus::wordTerm($random->intBetween(1, $commonRanks), $vocabulary);
            $shapes = [
                'rare' => [Corpus::topicTerm($topic)],
                'common' => [$common],
                'mixed' => [Corpus::topicTerm($topic), $common],
            ];

            foreach ($shapes as $shape => $terms) {
                $queryText = implode(' ', $terms);
                $tokens = $tokenizer->tokenize($queryText);
                $ranked = $ranker->rank($index, $tokens, $topK);
                $without = $withoutLength->rank($index, $tokens, $topK);
                $bm25Ids = $ids($ranked);
                $totals[$shape]['postings'] += $ranked->postingsScanned;
                $totals[$shape]['candidates'] += $ranked->candidatesScored;
                $totals[$shape]['returned'] += count($ranked->hits);
                $totals[$shape]['tf'] += $overlap($bm25Ids, $rankByTf($tokens, $topK));
                $totals[$shape]['b0'] += $overlap($bm25Ids, $ids($without));
            }
        }

        $rows = [];

        foreach ($totals as $shape => $total) {
            $postings = $total['postings'] / $queries;
            $candidates = $total['candidates'] / $queries;
            $returned = $total['returned'] / $queries;
            $rows[] = [
                $shape,
                sprintf('%.1f', $postings),
                sprintf('%.1f', $candidates),
                sprintf('%.1f', $returned),
                sprintf('%.1f%%', $candidates === 0.0 ? 0.0 : $returned / $candidates * 100),
                sprintf('%.1f%%', $total['tf'] / $queries * 100),
                sprintf('%.1f%%', $total['b0'] / $queries * 100),
            ];
            $output->measure('avg_postings_' . $shape, $postings);
            $output->measure('avg_candidates_' . $shape, $candidates);
            $output->measure('avg_returned_' . $shape, $returned);
            $output->measure('topk_share_of_candidates_' . $shape, $candidates === 0.0 ? 0.0 : $returned / $candidates);
            $output->measure('tf_overlap_' . $shape, $total['tf'] / $queries);
            $output->measure('b0_overlap_' . $shape, $total['b0'] / $queries);
        }

        $output->section('Average query shape');
        $output->table(
            ['shape', 'postings', 'candidates', 'returned', 'candidates in top-k', 'TF overlap', 'b=0 overlap'],
            $rows,
        );

        $output->section('Example term document frequency');
        $exampleRows = [];

        foreach ([
            'rare' => [Corpus::topicTerm(0)],
            'common' => [Corpus::wordTerm(1, $vocabulary)],
            'mixed' => [Corpus::topicTerm(0), Corpus::wordTerm(1, $vocabulary)],
        ] as $shape => $terms) {
            $candidates = [];

            foreach ($terms as $term) {
                $candidates += array_fill_keys(array_keys($index->postings($term)), true);
            }

            $exampleRows[] = [
                $shape,
                implode(' + ', $terms),
                number_format($index->documentFrequency($terms[0])),
                number_format(count($candidates)),
            ];
        }

        $output->table(['shape', 'terms', 'first term df', 'candidate union'], $exampleRows);

        $output->line();
        $output->line('A rare term touches few postings; a common term touches a large part of the index.');
        $output->line('BM25 top-k is compared with raw TF and with BM25 b=0 on the same postings.');
        $output->line('The overlap is a ranking difference, not a relevance judgement: the corpus has one synthetic topic signal.');
        $output->line('Document frequency sets the cost floor before any scoring happens.');
    },
);
