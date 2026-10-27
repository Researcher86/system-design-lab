<?php

declare(strict_types=1);

namespace SystemDesignLab\Search;

use InvalidArgumentException;
use SystemDesignLab\Foundation\Hashers;
use SystemDesignLab\Instrumentation\Counters;

/**
 * A fixed number of independent inverted indexes behind a document-id hash.
 *
 * Each document belongs to exactly one shard, chosen by hashing its id. A query
 * is tokenized once and sent to every shard; the shard-local BM25 scores are
 * merged by score and document id. Shards keep their own document counts,
 * average lengths and document frequencies, so a score is relative to the
 * shard that produced it. This is the honest simplification of the chapter: it
 * makes fan-out and shard-local statistics measurable without pretending to be
 * a replicated search cluster.
 *
 * Prefix completion is also fanned out and merged. The merged dictionary is
 * exact because every shard returns its own prefix range; a term that appears
 * on several shards is returned once.
 */
final class SearchCluster
{
    /** @var list<InvertedIndex> */
    private array $shards = [];

    private readonly Tokenizer $tokenizer;

    private readonly Bm25Ranker $ranker;

    private readonly Counters $counters;

    public function __construct(
        private readonly int $shardCount = 1,
        ?Tokenizer $tokenizer = null,
        ?Bm25Ranker $ranker = null,
        ?Counters $counters = null,
    ) {
        if ($this->shardCount < 1) {
            throw new InvalidArgumentException('A search cluster needs at least one shard');
        }

        $this->tokenizer = $tokenizer ?? new Tokenizer();
        $this->ranker = $ranker ?? new Bm25Ranker();
        $this->counters = $counters ?? new Counters();

        for ($index = 0; $index < $this->shardCount; ++$index) {
            $this->shards[] = new InvertedIndex($this->tokenizer, new PrefixIndex());
        }
    }

    public function addDocument(SearchDocument $document): void
    {
        $this->shards[$this->shardFor($document->id)]->addDocument($document);
        $this->counters->add('documentsIndexed');
    }

    /**
     * Fan a query out to every shard and merge the shard-local top-k lists.
     *
     * Taking `topK` per shard and merging is sufficient for a global top-k:
     * a document outside its shard's top-k cannot enter the global top-k if
     * the scores were comparable. The chapter measures what happens when they
     * are not: each shard normalizes against its own statistics.
     */
    public function search(string $query, int $topK = 10): SearchResponse
    {
        if ($topK < 1) {
            throw new InvalidArgumentException('Top-k must be at least 1');
        }

        $tokens = $this->tokenizer->tokenize($query);
        $this->counters->add('queries');

        if ($tokens === []) {
            $this->counters->add('emptyQueries');

            return new SearchResponse($query, [], 0, 0, 0);
        }

        $hits = [];
        $postingsScanned = 0;
        $candidatesScored = 0;

        foreach ($this->shards as $shard) {
            $ranked = $this->ranker->rank($shard, $tokens, $topK);
            $postingsScanned += $ranked->postingsScanned;
            $candidatesScored += $ranked->candidatesScored;

            foreach ($ranked->hits as $hit) {
                $hits[] = $hit;
            }
        }

        usort($hits, static function (SearchHit $left, SearchHit $right): int {
            $comparison = $right->score <=> $left->score;

            return $comparison !== 0 ? $comparison : strcmp($left->docId, $right->docId);
        });
        $results = array_slice($hits, 0, $topK);
        $this->counters->add('shardsQueried', $this->shardCount);
        $this->counters->add('postingsScanned', $postingsScanned);
        $this->counters->add('candidatesScored', $candidatesScored);
        $this->counters->add('resultsReturned', count($results));

        return new SearchResponse(
            query: $query,
            results: $results,
            shardsQueried: $this->shardCount,
            postingsScanned: $postingsScanned,
            candidatesScored: $candidatesScored,
        );
    }

    /**
     * @return list<string>
     */
    public function complete(string $prefix, int $limit = 10): array
    {
        if ($limit < 1) {
            throw new InvalidArgumentException('Completion limit must be at least 1');
        }

        $this->counters->add('prefixQueries');
        $this->counters->add('prefixShardsQueried', $this->shardCount);
        $results = array_slice($this->prefixTerms($prefix, $limit), 0, $limit);
        $this->counters->add('prefixResultsReturned', count($results));

        return $results;
    }

    /**
     * The distinct number of terms with the prefix across every shard.
     *
     * A normal completion asks each shard for `limit` terms; an exact count
     * has to pull every matching term out of every shard, so this is an
     * O(matches) operation and not the autocomplete path.
     */
    public function countWithPrefix(string $prefix): int
    {
        $this->counters->add('prefixQueries');
        $this->counters->add('prefixCountQueries');
        $this->counters->add('prefixShardsQueried', $this->shardCount);

        return count($this->prefixTerms($prefix, PHP_INT_MAX));
    }

    public function shardFor(string $docId): int
    {
        return Hashers::index($docId, $this->shardCount);
    }

    public function shardCount(): int
    {
        return $this->shardCount;
    }

    public function documentsInShard(int $index): int
    {
        return $this->shard($index)->documentCount();
    }

    public function documentsIndexed(): int
    {
        $total = 0;

        foreach ($this->shards as $shard) {
            $total += $shard->documentCount();
        }

        return $total;
    }

    public function postingsCount(): int
    {
        $total = 0;

        foreach ($this->shards as $shard) {
            $total += $shard->postingCount();
        }

        return $total;
    }

    /**
     * The sum of shard-local dictionaries. A term stored on several shards is
     * counted once per shard, so this is not a global distinct-term count.
     */
    public function dictionarySize(): int
    {
        $total = 0;

        foreach ($this->shards as $shard) {
            $total += $shard->dictionarySize();
        }

        return $total;
    }

    public function prefixRebuilds(): int
    {
        $total = 0;

        foreach ($this->shards as $shard) {
            $total += $shard->prefixRebuilds();
        }

        return $total;
    }

    public function counters(): Counters
    {
        return $this->counters;
    }

    /**
     * @return list<string>
     */
    private function prefixTerms(string $prefix, int $perShardLimit): array
    {
        $matches = [];

        foreach ($this->shards as $shard) {
            foreach ($shard->complete($prefix, $perShardLimit) as $term) {
                $matches[$term] = true;
            }
        }

        $terms = array_keys($matches);
        sort($terms, SORT_STRING);

        return $terms;
    }

    private function shard(int $index): InvertedIndex
    {
        if (!isset($this->shards[$index])) {
            throw new InvalidArgumentException(sprintf('Unknown search shard: %d', $index));
        }

        return $this->shards[$index];
    }
}
