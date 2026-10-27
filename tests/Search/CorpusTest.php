<?php

declare(strict_types=1);

namespace SystemDesignLab\Tests\Search;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use SystemDesignLab\Search\Corpus;

final class CorpusTest extends TestCase
{
    public function testTheSameSeedProducesTheSameCorpus(): void
    {
        $first = Corpus::generate(20, 100, 10, 'seed-a');
        $second = Corpus::generate(20, 100, 10, 'seed-a');

        self::assertEquals($first, $second);
    }

    public function testEveryDocumentHasAUniqueIdAndATopicTerm(): void
    {
        $corpus = Corpus::generate(50, 100, 10, 'seed-a');
        $ids = [];

        foreach ($corpus as $document) {
            $ids[$document->id] = true;
            self::assertMatchesRegularExpression('/topic\d{4}/', $document->title);
            self::assertMatchesRegularExpression('/topic\d{4}/', $document->body);
        }

        self::assertCount(50, $ids);
    }

    public function testWordTermsKeepTheirRankWidth(): void
    {
        self::assertSame('word00001', Corpus::wordTerm(1, 20_000));
        self::assertSame('word00009', Corpus::wordTerm(9, 20_000));
        self::assertSame('word12345', Corpus::wordTerm(12_345, 20_000));
    }

    public function testTopicTermsArePaddedAndRangeChecked(): void
    {
        self::assertSame('topic0000', Corpus::topicTerm(0));
        self::assertSame('topic0099', Corpus::topicTerm(99));

        $this->expectException(InvalidArgumentException::class);
        Corpus::topicTerm(100);
    }

    public function testCorpusSizesMustBePositive(): void
    {
        $this->expectException(InvalidArgumentException::class);
        Corpus::generate(0, 100, 10, 'seed-a');
    }

    public function testWordRankMustFitTheVocabulary(): void
    {
        $this->expectException(InvalidArgumentException::class);
        Corpus::wordTerm(101, 100);
    }
}
