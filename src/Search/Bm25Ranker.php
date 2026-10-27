<?php

declare(strict_types=1);

namespace SystemDesignLab\Search;

use InvalidArgumentException;

/**
 * Okapi BM25 with the usual k1 and b parameters.
 *
 * Each shard scores against its own document count, average length and
 * document frequencies. That keeps the ranker local and cheap; it also means
 * shard-local scores are not directly comparable when shard statistics differ,
 * which the shard experiment measures.
 */
final readonly class Bm25Ranker
{
    public function __construct(
        private float $k1 = 1.2,
        private float $b = 0.75,
    ) {
        if ($this->k1 <= 0.0) {
            throw new InvalidArgumentException('BM25 k1 must be positive');
        }

        if ($this->b < 0.0 || $this->b > 1.0) {
            throw new InvalidArgumentException('BM25 b must be between 0 and 1');
        }
    }

    /**
     * @param list<string> $tokens
     */
    public function rank(InvertedIndex $index, array $tokens, int $topK): RankedResults
    {
        if ($topK < 1) {
            throw new InvalidArgumentException('Top-k must be at least 1');
        }

        $tokens = array_values(array_unique($tokens));
        $documents = $index->documentCount();
        $averageLength = $index->averageDocumentLength();

        if ($tokens === [] || $documents === 0) {
            return new RankedResults([], 0, 0);
        }

        $scores = [];
        $postingsScanned = 0;

        foreach ($tokens as $term) {
            $postings = $index->postings($term);
            $documentFrequency = count($postings);

            if ($documentFrequency === 0) {
                continue;
            }

            $postingsScanned += $documentFrequency;
            $idf = log(1.0 + (($documents - $documentFrequency + 0.5) / ($documentFrequency + 0.5)));

            foreach ($postings as $docId => $frequency) {
                $docId = (string) $docId;
                $length = $index->documentLength($docId);
                $lengthRatio = $averageLength > 0.0 ? $length / $averageLength : 0.0;
                $denominator = $frequency + $this->k1 * (1.0 - $this->b + $this->b * $lengthRatio);
                $score = $idf * ($frequency * ($this->k1 + 1.0)) / $denominator;
                $scores[$docId] = ($scores[$docId] ?? 0.0) + $score;
            }
        }

        $docIds = array_keys($scores);
        usort($docIds, static function (int|string $left, int|string $right) use ($scores): int {
            $left = (string) $left;
            $right = (string) $right;
            $comparison = $scores[$right] <=> $scores[$left];

            return $comparison !== 0 ? $comparison : strcmp($left, $right);
        });
        $hits = [];

        foreach (array_slice($docIds, 0, $topK) as $docId) {
            $hits[] = new SearchHit((string) $docId, $scores[$docId]);
        }

        return new RankedResults($hits, $postingsScanned, count($scores));
    }
}
