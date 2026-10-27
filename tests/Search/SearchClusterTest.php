<?php

declare(strict_types=1);

namespace SystemDesignLab\Tests\Search;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use SystemDesignLab\Search\SearchCluster;
use SystemDesignLab\Search\SearchDocument;
use SystemDesignLab\Search\SearchHit;

final class SearchClusterTest extends TestCase
{
    public function testDocumentsAreRoutedToShardsByTheirId(): void
    {
        $cluster = new SearchCluster(4);

        for ($shard = 0; $shard < 4; ++$shard) {
            $cluster->addDocument($this->documentInShard($cluster, $shard, 'doc-' . $shard, 'alpha'));
        }

        for ($shard = 0; $shard < 4; ++$shard) {
            self::assertSame(1, $cluster->documentsInShard($shard));
        }

        self::assertSame(4, $cluster->documentsIndexed());
        self::assertSame(4, $cluster->postingsCount());
        self::assertSame(4, $cluster->dictionarySize());
        self::assertSame(4, $cluster->counters()->get('documentsIndexed'));
    }

    public function testSearchFansOutAndMergesTopKByScore(): void
    {
        $cluster = new SearchCluster(4);
        $high = $this->documentInShard($cluster, 0, 'high', 'alpha alpha alpha alpha');
        $medium = $this->documentInShard($cluster, 1, 'medium', 'alpha alpha');
        $low = $this->documentInShard($cluster, 2, 'low', 'alpha');
        $cluster->addDocument($high);
        $cluster->addDocument($medium);
        $cluster->addDocument($low);

        $response = $cluster->search('alpha', 2);

        self::assertSame('alpha', $response->query);
        self::assertSame([$high->id, $medium->id], array_map(
            static fn (SearchHit $hit): string => $hit->docId,
            $response->results,
        ));
        self::assertSame(4, $response->shardsQueried);
        self::assertSame(3, $response->postingsScanned);
        self::assertSame(3, $response->candidatesScored);
        self::assertSame(1, $cluster->counters()->get('queries'));
        self::assertSame(4, $cluster->counters()->get('shardsQueried'));
        self::assertSame(3, $cluster->counters()->get('postingsScanned'));
        self::assertSame(3, $cluster->counters()->get('candidatesScored'));
        self::assertSame(2, $cluster->counters()->get('resultsReturned'));
    }

    public function testSearchWithNoQueryTermsDoesNotTouchAnyShard(): void
    {
        $cluster = new SearchCluster(3);
        $cluster->addDocument($this->documentInShard($cluster, 0, 'doc', 'alpha'));

        $response = $cluster->search(' ... ', 10);

        self::assertSame([], $response->results);
        self::assertSame(0, $response->shardsQueried);
        self::assertSame(0, $response->postingsScanned);
        self::assertSame(0, $response->candidatesScored);
        self::assertSame(1, $cluster->counters()->get('queries'));
        self::assertSame(1, $cluster->counters()->get('emptyQueries'));
        self::assertSame(0, $cluster->counters()->get('shardsQueried'));
    }

    public function testDuplicateDocumentIdsAreRejectedOnTheOwningShard(): void
    {
        $cluster = new SearchCluster(2);
        $document = $this->documentInShard($cluster, 0, 'doc', 'alpha');
        $cluster->addDocument($document);

        $this->expectException(InvalidArgumentException::class);
        $cluster->addDocument($document);
    }

    public function testPrefixCompletionMergesShardDictionariesAndDeduplicatesTerms(): void
    {
        $cluster = new SearchCluster(2);
        $cluster->addDocument($this->documentInShard($cluster, 0, 'doc-a', 'apple apricot'));
        $cluster->addDocument($this->documentInShard($cluster, 1, 'doc-b', 'apple avocado'));

        self::assertSame(['apple', 'apricot', 'avocado'], $cluster->complete('a', 10));
        self::assertSame(['apple', 'apricot'], $cluster->complete('ap', 10));
        self::assertSame(['apple'], $cluster->complete('a', 1));
        self::assertSame(3, $cluster->countWithPrefix('a'));
        self::assertSame(2, $cluster->countWithPrefix('ap'));
        self::assertSame(5, $cluster->counters()->get('prefixQueries'));
        self::assertSame(10, $cluster->counters()->get('prefixShardsQueried'));
        self::assertSame(6, $cluster->counters()->get('prefixResultsReturned'));
        self::assertSame(2, $cluster->counters()->get('prefixCountQueries'));
    }

    public function testDictionarySizeSumsShardDictionaries(): void
    {
        $sameShard = new SearchCluster(2);
        $sameShard->addDocument($this->documentInShard($sameShard, 0, 'doc-a', 'alpha beta'));
        $sameShard->addDocument($this->documentInShard($sameShard, 0, 'doc-b', 'alpha gamma'));

        $differentShards = new SearchCluster(2);
        $differentShards->addDocument($this->documentInShard($differentShards, 0, 'doc-a', 'alpha beta'));
        $differentShards->addDocument($this->documentInShard($differentShards, 1, 'doc-b', 'alpha gamma'));

        self::assertSame(3, $sameShard->dictionarySize());
        self::assertSame(4, $differentShards->dictionarySize());
    }

    public function testShardCountMustBePositive(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new SearchCluster(0);
    }

    public function testTopKMustBePositive(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new SearchCluster()->search('alpha', 0);
    }

    public function testUnknownShardIndexIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new SearchCluster(2)->documentsInShard(2);
    }

    public function testCompletionLimitMustBePositive(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new SearchCluster()->complete('a', 0);
    }

    private function documentInShard(SearchCluster $cluster, int $shard, string $prefix, string $body): SearchDocument
    {
        for ($index = 0;; ++$index) {
            $id = $prefix . '-' . $index;

            if ($cluster->shardFor($id) === $shard) {
                return new SearchDocument($id, '', $body);
            }
        }
    }
}
