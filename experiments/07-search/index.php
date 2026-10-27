<?php

declare(strict_types=1);

use SystemDesignLab\Experiment\Experiment;
use SystemDesignLab\Experiment\Options;
use SystemDesignLab\Experiment\Output;
use SystemDesignLab\Foundation\ByteFormatter;
use SystemDesignLab\Instrumentation\MemoryMeter;
use SystemDesignLab\Search\Corpus;
use SystemDesignLab\Search\InvertedIndex;
use SystemDesignLab\Search\Tokenizer;

/**
 * What does an inverted index remember, and what does it cost?
 *
 * The run generates a deterministic corpus, keeps the documents alive, then
 * builds one index on top of them. The two memory deltas answer two different
 * questions: how much the corpus costs, and how much the index adds. Token and
 * posting counters are independent of the allocator and of this machine.
 */
return new Experiment(
    name: 'search:index',
    description: 'tokens, postings, dictionary size and PHP memory for one inverted index',
    supports: ['docs', 'vocabulary', 'terms-per-doc', 'seed'],
    run: static function (Options $options, Output $output): void {
        $documents = $options->int('docs', 5_000);
        $vocabulary = $options->int('vocabulary', 20_000);
        $termsPerDoc = $options->int('terms-per-doc', 80);
        $seed = $options->seed('search-index');

        $output->heading(
            'Search index',
            sprintf(
                '%s documents, %s-term vocabulary, about %d body terms per document, seed "%s".',
                number_format($documents),
                number_format($vocabulary),
                $termsPerDoc,
                $seed,
            ),
        );
        $output->line();

        gc_collect_cycles();
        $meter = new MemoryMeter();
        $corpus = Corpus::generate($documents, $vocabulary, $termsPerDoc, $seed);
        gc_collect_cycles();
        $afterCorpus = $meter->usage();
        $corpusBytes = $afterCorpus - $meter->baseline();

        $index = new InvertedIndex(new Tokenizer());

        foreach ($corpus as $document) {
            $index->addDocument($document);
        }

        gc_collect_cycles();
        $afterIndex = $meter->usage();
        $indexBytes = $afterIndex - $afterCorpus;
        $totalBytes = $afterIndex - $meter->baseline();
        $textBytes = 0;

        foreach ($corpus as $document) {
            $textBytes += strlen($document->id) + strlen($document->title) + strlen($document->body);
        }

        $dictionary = $index->dictionarySize();
        $rows = [
            ['documents', number_format($documents)],
            ['tokens', number_format($index->tokenCount())],
            ['tokens per document', sprintf('%.1f', $index->averageDocumentLength())],
            ['postings (term-document pairs)', number_format($index->postingCount())],
            ['postings per document', sprintf('%.1f', $index->postingCount() / $documents)],
            ['dictionary terms', number_format($dictionary)],
            ['average postings per term', sprintf('%.1f', $dictionary === 0 ? 0.0 : $index->postingCount() / $dictionary)],
            ['input text', ByteFormatter::format($textBytes)],
            ['PHP memory: corpus', ByteFormatter::format($corpusBytes)],
            ['PHP memory: index', ByteFormatter::format($indexBytes)],
            ['PHP memory: total', ByteFormatter::format($totalBytes)],
            ['PHP memory: index per document', ByteFormatter::perItem($indexBytes, $documents)],
        ];

        foreach ($rows as [$metric, $value]) {
            $output->line(sprintf('%-32s %s', $metric, $value));
        }

        $terms = $index->complete('', max(1, $dictionary));
        $frequencies = [];

        foreach ($terms as $term) {
            $frequencies[$term] = $index->documentFrequency($term);
        }

        uasort($frequencies, static fn (int $left, int $right): int => $right <=> $left);
        $topRows = [];
        $rank = 0;

        foreach ($frequencies as $term => $frequency) {
            $topRows[] = [
                (string) $term,
                number_format($frequency),
                sprintf('%.1f%%', $frequency / $documents * 100),
            ];

            if (++$rank === 5) {
                break;
            }
        }

        $output->section('Most frequent terms');
        $output->table(['term', 'documents', 'share'], $topRows);

        $output->measure('documents', $documents);
        $output->measure('tokens', $index->tokenCount());
        $output->measure('tokens_per_document', $index->averageDocumentLength());
        $output->measure('postings', $index->postingCount());
        $output->measure('postings_per_document', $index->postingCount() / $documents);
        $output->measure('dictionary_terms', $dictionary);
        $output->measure('corpus_php_bytes', $corpusBytes);
        $output->measure('index_php_bytes', $indexBytes);
        $output->measure('index_php_bytes_per_document', $indexBytes / $documents);
        $output->measure('total_php_bytes', $totalBytes);
        $output->measure('text_bytes', $textBytes);

        $output->line();
        $output->line('The index stores term-document pairs and document lengths, not the document bodies.');
        $output->line('A term that appears in every document still occupies one posting per document.');
        $output->line('The memory deltas are PHP allocator bytes, not RSS.');
        $output->line('A production index adds on-disk segments, compression and a stored-field index.');
    },
);
