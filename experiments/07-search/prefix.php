<?php

declare(strict_types=1);

use SystemDesignLab\Experiment\Experiment;
use SystemDesignLab\Experiment\Options;
use SystemDesignLab\Experiment\Output;
use SystemDesignLab\Search\Corpus;
use SystemDesignLab\Search\InvertedIndex;
use SystemDesignLab\Search\SearchCluster;
use SystemDesignLab\Search\Tokenizer;

/**
 * What does prefix completion cost, and what changes when it is sharded?
 *
 * A PrefixIndex keeps a hash set and sorts it lazily on the first query after
 * a write. The experiment checks that the sorted list is built once, then
 * compares one index with a fan-out across shards. Each shard has its own
 * dictionary and its own rebuild; the merged answer is exact because the
 * shard-local prefix ranges are disjoint in value, not in storage.
 */
return new Experiment(
    name: 'search:prefix',
    description: 'lazy prefix dictionary rebuilds and fan-out across search shards',
    supports: ['docs', 'vocabulary', 'terms-per-doc', 'shards', 'prefix', 'limit', 'seed'],
    run: static function (Options $options, Output $output): void {
        $documents = $options->int('docs', 2_000);
        $vocabulary = $options->int('vocabulary', 20_000);
        $termsPerDoc = $options->int('terms-per-doc', 80);
        $shards = $options->int('shards', 4);
        $prefix = $options->string('prefix', 'word00');
        $limit = $options->int('limit', 10);
        $seed = $options->seed('search-prefix');

        $output->heading(
            'Prefix completion',
            sprintf(
                '%s documents, %d shards, prefix "%s", limit %d.',
                number_format($documents),
                $shards,
                $prefix,
                $limit,
            ),
        );
        $output->line();

        $corpus = Corpus::generate($documents, $vocabulary, $termsPerDoc, $seed);
        $single = new InvertedIndex(new Tokenizer());
        $cluster = new SearchCluster($shards);

        foreach ($corpus as $document) {
            $single->addDocument($document);
            $cluster->addDocument($document);
        }

        $singleRebuildsBefore = $single->prefixRebuilds();
        $singleFirst = $single->complete($prefix, $limit);
        $singleRebuildsAfterFirst = $single->prefixRebuilds();
        $single->complete($prefix, $limit);
        $singleRebuildsAfterSecond = $single->prefixRebuilds();

        $clusterRebuildsBefore = $cluster->prefixRebuilds();
        $clusterFirst = $cluster->complete($prefix, $limit);
        $clusterRebuildsAfterFirst = $cluster->prefixRebuilds();
        $cluster->complete($prefix, $limit);
        $clusterRebuildsAfterSecond = $cluster->prefixRebuilds();

        $singleMatches = $single->countWithPrefix($prefix);
        $clusterMatches = $cluster->countWithPrefix($prefix);

        $rows = [
            ['dictionary: single index', number_format($single->dictionarySize())],
            ['dictionary: sum over shards', number_format($cluster->dictionarySize())],
            ['terms with prefix: single', number_format($singleMatches)],
            ['terms with prefix: merged', number_format($clusterMatches)],
            ['dictionary rebuilds: single, first query', (string) ($singleRebuildsAfterFirst - $singleRebuildsBefore)],
            ['dictionary rebuilds: single, second query', (string) ($singleRebuildsAfterSecond - $singleRebuildsAfterFirst)],
            ['dictionary rebuilds: shards, first query', (string) ($clusterRebuildsAfterFirst - $clusterRebuildsBefore)],
            ['dictionary rebuilds: shards, second query', (string) ($clusterRebuildsAfterSecond - $clusterRebuildsAfterFirst)],
        ];

        foreach ($rows as [$metric, $value]) {
            $output->line(sprintf('%-42s %s', $metric, $value));
        }

        $output->section('Completion results');
        $output->line('single index: ' . implode(', ', $singleFirst));
        $output->line('merged shards: ' . implode(', ', $clusterFirst));

        if ($singleFirst !== $clusterFirst) {
            throw new RuntimeException('Sharded completion does not match a single index');
        }

        $output->measure('single_dictionary_terms', $single->dictionarySize());
        $output->measure('shard_dictionary_sum', $cluster->dictionarySize());
        $output->measure('single_prefix_matches', $singleMatches);
        $output->measure('cluster_prefix_matches', $clusterMatches);
        $output->measure('single_rebuilds_first_query', $singleRebuildsAfterFirst - $singleRebuildsBefore);
        $output->measure('single_rebuilds_second_query', $singleRebuildsAfterSecond - $singleRebuildsAfterFirst);
        $output->measure('cluster_rebuilds_first_query', $clusterRebuildsAfterFirst - $clusterRebuildsBefore);
        $output->measure('cluster_rebuilds_second_query', $clusterRebuildsAfterSecond - $clusterRebuildsAfterFirst);
        $output->measure('cluster_prefix_shards_queried', $cluster->counters()->get('prefixShardsQueried'));

        $output->line();
        $output->line('The first query after a write sorts the dictionary; later queries only binary-search it.');
        $output->line('Each shard owns a separate dictionary, so one write invalidates that shard, not all of them.');
        $output->line('The shard dictionaries sum to more entries than the global dictionary when terms repeat.');
        $output->line('The merged prefix range is exact; it is not an approximate top-k over completions.');
    },
);
