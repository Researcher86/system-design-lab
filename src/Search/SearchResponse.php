<?php

declare(strict_types=1);

namespace SystemDesignLab\Search;

/**
 * The merged answer to one query, with the work that produced it.
 */
final readonly class SearchResponse
{
    /**
     * @param list<SearchHit> $results
     */
    public function __construct(
        public string $query,
        public array $results,
        public int $shardsQueried,
        public int $postingsScanned,
        public int $candidatesScored,
    ) {
    }
}
