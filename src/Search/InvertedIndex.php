<?php

declare(strict_types=1);

namespace SystemDesignLab\Search;

use InvalidArgumentException;

/**
 * An in-process inverted index with BM25 statistics.
 *
 * Postings map a term to `docId => term frequency`. The index also keeps each
 * document's token count, the total token count and a lazy prefix dictionary.
 * It stores no document bodies: search returns ids and scores, and a real
 * engine would fetch the stored fields from a separate forward index.
 */
final class InvertedIndex
{
    /** @var array<string, array<string, int>> */
    private array $postings = [];

    /** @var array<string, int> */
    private array $lengths = [];

    /** @var array<string, true> */
    private array $documentIds = [];

    private int $tokenCount = 0;

    private int $postingPairs = 0;

    private readonly PrefixIndex $prefix;

    public function __construct(
        private readonly Tokenizer $tokenizer,
        ?PrefixIndex $prefix = null,
    ) {
        $this->prefix = $prefix ?? new PrefixIndex();
    }

    public function addDocument(SearchDocument $document): void
    {
        if (isset($this->documentIds[$document->id])) {
            throw new InvalidArgumentException(sprintf('Document %s is already indexed', $document->id));
        }

        $tokens = $this->tokenizer->tokenize($document->title . ' ' . $document->body);
        $length = count($tokens);
        $this->documentIds[$document->id] = true;
        $this->lengths[$document->id] = $length;
        $this->tokenCount += $length;

        $frequencies = array_count_values($tokens);

        foreach ($frequencies as $term => $frequency) {
            $term = (string) $term;
            $this->prefix->add($term);

            if (!isset($this->postings[$term][$document->id])) {
                ++$this->postingPairs;
            }

            $this->postings[$term][$document->id] = $frequency;
        }
    }

    /**
     * @return array<string, int>
     */
    public function postings(string $term): array
    {
        return $this->postings[$term] ?? [];
    }

    public function documentFrequency(string $term): int
    {
        return count($this->postings[$term] ?? []);
    }

    public function documentLength(string $docId): int
    {
        return $this->lengths[$docId] ?? 0;
    }

    public function documentCount(): int
    {
        return count($this->documentIds);
    }

    public function averageDocumentLength(): float
    {
        $count = $this->documentCount();

        return $count === 0 ? 0.0 : $this->tokenCount / $count;
    }

    public function tokenCount(): int
    {
        return $this->tokenCount;
    }

    public function postingCount(): int
    {
        return $this->postingPairs;
    }

    public function dictionarySize(): int
    {
        return $this->prefix->size();
    }

    public function prefixRebuilds(): int
    {
        return $this->prefix->rebuilds();
    }

    /**
     * @return list<string>
     */
    public function complete(string $prefix, int $limit = 10): array
    {
        return $this->prefix->complete($prefix, $limit);
    }

    public function countWithPrefix(string $prefix): int
    {
        return $this->prefix->countWithPrefix($prefix);
    }
}
