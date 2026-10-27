<?php

declare(strict_types=1);

namespace SystemDesignLab\Tests\Search;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use SystemDesignLab\Search\PrefixIndex;

final class PrefixIndexTest extends TestCase
{
    public function testCompletesSortedTermsThatStartWithThePrefix(): void
    {
        $index = $this->index(['cache', 'car', 'carbon', 'dog']);

        self::assertSame(['cache', 'car', 'carbon'], $index->complete('ca'));
        self::assertSame(['cache'], $index->complete('cac'));
    }

    public function testCompletionHonoursTheLimit(): void
    {
        $index = $this->index(['cache', 'car', 'carbon']);

        self::assertSame(['cache', 'car'], $index->complete('ca', 2));
    }

    public function testCompletionIsCaseInsensitiveAndDeduplicatesTerms(): void
    {
        $index = $this->index(['Cache', 'cache', 'CACHE']);

        self::assertSame(1, $index->size());
        self::assertSame(['cache'], $index->complete('CA'));
    }

    public function testMissingPrefixReturnsAnEmptyList(): void
    {
        $index = $this->index(['cache', 'car']);

        self::assertSame([], $index->complete('zzz'));
    }

    public function testEmptyPrefixReturnsTheDictionaryInOrder(): void
    {
        $index = $this->index(['dog', 'cache', 'car']);

        self::assertSame(['cache', 'car', 'dog'], $index->complete(''));
    }

    public function testCountWithPrefixCountsTheWholeRangeNotJustTheLimit(): void
    {
        $index = $this->index(['cache', 'car', 'carbon', 'dog']);

        self::assertSame(3, $index->countWithPrefix('ca'));
        self::assertSame(0, $index->countWithPrefix('z'));
    }

    public function testSortedDictionaryIsRebuiltLazilyAfterAWrite(): void
    {
        $index = $this->index(['cache', 'car']);

        self::assertSame(0, $index->rebuilds());

        $index->complete('ca');
        $index->complete('car');

        self::assertSame(1, $index->rebuilds());

        $index->add('cargo');
        $index->complete('ca');

        self::assertSame(2, $index->rebuilds());
    }

    public function testAddingADuplicateDoesNotInvalidateTheSortedDictionary(): void
    {
        $index = $this->index(['cache']);
        $index->complete('ca');
        $index->add('cache');
        $index->complete('ca');

        self::assertSame(1, $index->rebuilds());
        self::assertSame(1, $index->size());
    }

    public function testNumericTermsStayStringsAndSortLexicographically(): void
    {
        $index = $this->index(['123', '12', '2']);

        self::assertSame(['12', '123'], $index->complete('1'));
        self::assertSame(['2'], $index->complete('2'));
        self::assertSame(2, $index->countWithPrefix('1'));
    }

    public function testEmptyTermsAreRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new PrefixIndex()->add('  ');
    }

    public function testCompletionLimitMustBePositive(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new PrefixIndex()->complete('ca', 0);
    }

    /**
     * @param list<string> $terms
     */
    private function index(array $terms): PrefixIndex
    {
        $index = new PrefixIndex();

        foreach ($terms as $term) {
            $index->add($term);
        }

        return $index;
    }
}
