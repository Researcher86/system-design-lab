<?php

declare(strict_types=1);

namespace SystemDesignLab\Search;

/**
 * One ranked document and its BM25 score.
 */
final readonly class SearchHit
{
    public function __construct(
        public string $docId,
        public float $score,
    ) {
    }
}
