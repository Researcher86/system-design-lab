<?php

declare(strict_types=1);

namespace SystemDesignLab\Search;

/**
 * One shard's ranked output plus the work that produced it.
 *
 * `postingsScanned` counts term-document pairs read; `candidatesScored` counts
 * distinct documents that received at least one term contribution. The two
 * differ because a multi-term query can score the same document more than
 * once.
 */
final readonly class RankedResults
{
    /**
     * @param list<SearchHit> $hits
     */
    public function __construct(
        public array $hits,
        public int $postingsScanned,
        public int $candidatesScored,
    ) {
    }
}
