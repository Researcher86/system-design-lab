<?php

declare(strict_types=1);

namespace SystemDesignLab\Tests\Search;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use SystemDesignLab\Search\Bm25Ranker;
use SystemDesignLab\Search\InvertedIndex;
use SystemDesignLab\Search\SearchDocument;
use SystemDesignLab\Search\SearchHit;
use SystemDesignLab\Search\Tokenizer;

final class Bm25RankerTest extends TestCase
{
    public function testScoresFollowTheBm25Formula(): void
    {
        $index = $this->index();

        $results = new Bm25Ranker()->rank($index, ['alpha'], 10);

        self::assertCount(2, $results->hits);
        // N = 3, df = 2, average length = 2, d1 has tf 2 and length 3.
        self::assertEqualsWithDelta(0.56657971744691, $results->hits[0]->score, 1e-12);
        self::assertEqualsWithDelta(log(1.6), $results->hits[1]->score, 1e-12);
        self::assertSame('doc-1', $results->hits[0]->docId);
        self::assertSame('doc-2', $results->hits[1]->docId);
    }

    public function testHigherTermFrequencyOutranksLowerTermFrequencyAtTheSameLength(): void
    {
        $index = new InvertedIndex(new Tokenizer());
        $index->addDocument(new SearchDocument('more', '', 'alpha alpha alpha'));
        $index->addDocument(new SearchDocument('less', '', 'alpha beta beta'));

        $results = new Bm25Ranker()->rank($index, ['alpha'], 10);

        self::assertSame('more', $results->hits[0]->docId);
        self::assertGreaterThan($results->hits[1]->score, $results->hits[0]->score);
    }

    public function testEqualScoresAreBrokenByDocumentId(): void
    {
        $index = new InvertedIndex(new Tokenizer());
        $index->addDocument(new SearchDocument('doc-b', '', 'alpha'));
        $index->addDocument(new SearchDocument('doc-a', '', 'alpha'));

        $results = new Bm25Ranker()->rank($index, ['alpha'], 10);

        self::assertSame(['doc-a', 'doc-b'], array_map(
            static fn (SearchHit $hit): string => $hit->docId,
            $results->hits,
        ));
    }

    public function testTopKLimitsTheReturnedHitsButNotTheScoredCandidates(): void
    {
        $index = $this->index();

        $results = new Bm25Ranker()->rank($index, ['alpha'], 1);

        self::assertCount(1, $results->hits);
        self::assertSame(2, $results->candidatesScored);
        self::assertSame(2, $results->postingsScanned);
    }

    public function testDuplicateQueryTermsAreScoredOnce(): void
    {
        $ranker = new Bm25Ranker();
        $index = $this->index();

        $once = $ranker->rank($index, ['alpha'], 10);
        $twice = $ranker->rank($index, ['alpha', 'alpha'], 10);

        self::assertSame($once->hits[0]->score, $twice->hits[0]->score);
        self::assertSame($once->postingsScanned, $twice->postingsScanned);
    }

    public function testUnknownTermsAreSkipped(): void
    {
        $results = new Bm25Ranker()->rank($this->index(), ['missing'], 10);

        self::assertSame([], $results->hits);
        self::assertSame(0, $results->postingsScanned);
        self::assertSame(0, $results->candidatesScored);
    }

    public function testPartiallyKnownQueryStillCountsKnownPostings(): void
    {
        $results = new Bm25Ranker()->rank($this->index(), ['alpha', 'missing'], 10);

        self::assertCount(2, $results->hits);
        self::assertSame(2, $results->postingsScanned);
        self::assertSame(2, $results->candidatesScored);
    }

    public function testEmptyQueryAndEmptyIndexReturnNoWork(): void
    {
        $ranker = new Bm25Ranker();

        $emptyQuery = $ranker->rank($this->index(), [], 10);
        $emptyIndex = $ranker->rank(new InvertedIndex(new Tokenizer()), ['alpha'], 10);

        self::assertSame([], $emptyQuery->hits);
        self::assertSame(0, $emptyQuery->postingsScanned);
        self::assertSame([], $emptyIndex->hits);
        self::assertSame(0, $emptyIndex->postingsScanned);
    }

    public function testNumericDocumentIdsAreReturnedAsStrings(): void
    {
        $index = new InvertedIndex(new Tokenizer());
        $index->addDocument(new SearchDocument('123', '', 'alpha'));

        $results = new Bm25Ranker()->rank($index, ['alpha'], 10);

        self::assertSame('123', $results->hits[0]->docId);
    }

    public function testTopKMustBePositive(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new Bm25Ranker()->rank($this->index(), ['alpha'], 0);
    }

    public function testK1MustBePositive(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new Bm25Ranker(k1: 0.0);
    }

    public function testBMustStayInTheUnitInterval(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new Bm25Ranker(b: 1.1);
    }

    private function index(): InvertedIndex
    {
        $index = new InvertedIndex(new Tokenizer());
        $index->addDocument(new SearchDocument('doc-1', '', 'alpha alpha beta'));
        $index->addDocument(new SearchDocument('doc-2', '', 'alpha gamma'));
        $index->addDocument(new SearchDocument('doc-3', '', 'delta'));

        return $index;
    }
}
