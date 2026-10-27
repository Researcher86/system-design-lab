<?php

declare(strict_types=1);

namespace SystemDesignLab\Tests\Search;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use SystemDesignLab\Search\InvertedIndex;
use SystemDesignLab\Search\SearchDocument;
use SystemDesignLab\Search\Tokenizer;

final class InvertedIndexTest extends TestCase
{
    public function testIndexesTitleAndBodyIntoOneTermStream(): void
    {
        $index = $this->index();
        $index->addDocument(new SearchDocument('doc-1', 'Redis cache', 'cache cache'));

        self::assertSame(['doc-1' => 3], $index->postings('cache'));
        self::assertSame(['doc-1' => 1], $index->postings('redis'));
        self::assertSame(1, $index->documentFrequency('cache'));
        self::assertSame(4, $index->documentLength('doc-1'));
        self::assertSame(4, $index->tokenCount());
        self::assertSame(2, $index->postingCount());
        self::assertSame(2, $index->dictionarySize());
    }

    public function testPostingsForAnUnknownTermAreEmpty(): void
    {
        $index = $this->index();

        self::assertSame([], $index->postings('missing'));
        self::assertSame(0, $index->documentFrequency('missing'));
        self::assertSame(0, $index->documentLength('missing'));
    }

    public function testAverageLengthIsComputedOverDocuments(): void
    {
        $index = $this->index();
        $index->addDocument(new SearchDocument('doc-1', '', 'one two three'));
        $index->addDocument(new SearchDocument('doc-2', '', 'four'));

        self::assertSame(2, $index->documentCount());
        self::assertSame(2.0, $index->averageDocumentLength());
    }

    public function testAnEmptyIndexHasZeroAverageLength(): void
    {
        $index = $this->index();

        self::assertSame(0, $index->documentCount());
        self::assertSame(0.0, $index->averageDocumentLength());
        self::assertSame(0, $index->tokenCount());
        self::assertSame(0, $index->postingCount());
        self::assertSame(0, $index->dictionarySize());
    }

    public function testDuplicateDocumentIdsAreRejected(): void
    {
        $index = $this->index();
        $index->addDocument(new SearchDocument('doc-1', 'first', 'body'));

        $this->expectException(InvalidArgumentException::class);
        $index->addDocument(new SearchDocument('doc-1', 'second', 'body'));
    }

    public function testADocumentWithNoTokensIsStoredWithZeroLength(): void
    {
        $index = $this->index();
        $index->addDocument(new SearchDocument('empty', '...', '---'));

        self::assertSame(1, $index->documentCount());
        self::assertSame(0, $index->documentLength('empty'));
        self::assertSame(0, $index->postingCount());
        self::assertSame(0, $index->dictionarySize());
    }

    public function testCompletionAndPrefixCountsComeFromTheDictionary(): void
    {
        $index = $this->index();
        $index->addDocument(new SearchDocument('doc-1', '', 'cache car'));
        $index->addDocument(new SearchDocument('doc-2', '', 'carbon'));

        self::assertSame(['cache', 'car', 'carbon'], $index->complete('ca', 10));
        self::assertSame(2, $index->countWithPrefix('car'));
    }

    private function index(): InvertedIndex
    {
        return new InvertedIndex(new Tokenizer());
    }
}
