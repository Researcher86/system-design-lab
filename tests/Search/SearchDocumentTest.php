<?php

declare(strict_types=1);

namespace SystemDesignLab\Tests\Search;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use SystemDesignLab\Search\SearchDocument;

final class SearchDocumentTest extends TestCase
{
    public function testTitleAndBodyMayBeEmpty(): void
    {
        $document = new SearchDocument('doc-1', '', '');

        self::assertSame('doc-1', $document->id);
        self::assertSame('', $document->title);
        self::assertSame('', $document->body);
    }

    public function testEmptyIdIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new SearchDocument('', 'title', 'body');
    }

    public function testWhitespaceOnlyIdIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new SearchDocument('   ', 'title', 'body');
    }
}
