<?php

declare(strict_types=1);

namespace SystemDesignLab\Tests\Search;

use PHPUnit\Framework\TestCase;
use SystemDesignLab\Search\Tokenizer;

final class TokenizerTest extends TestCase
{
    public function testLowercasesAndSplitsOnPunctuation(): void
    {
        $tokens = new Tokenizer()->tokenize('Hello, WORLD! 42');

        self::assertSame(['hello', 'world', '42'], $tokens);
    }

    public function testKeepsUnicodeLetters(): void
    {
        $tokens = new Tokenizer()->tokenize('Привет, мир!');

        self::assertSame(['привет', 'мир'], $tokens);
    }

    public function testEmptyAndPunctuationOnlyTextProducesNoTokens(): void
    {
        $tokenizer = new Tokenizer();

        self::assertSame([], $tokenizer->tokenize(''));
        self::assertSame([], $tokenizer->tokenize(' ... --- !!! '));
    }

    public function testRepeatedTermsAreReturnedAndLeftForTheIndexToCount(): void
    {
        self::assertSame(['link', 'link', 'short'], new Tokenizer()->tokenize('link link short'));
    }

    public function testDigitsAreTokensAndAreNotStripped(): void
    {
        self::assertSame(['php', '8', '5', '2026'], new Tokenizer()->tokenize('PHP 8.5 / 2026'));
    }

    public function testHyphenatedTermsSplitIntoTwoTokens(): void
    {
        self::assertSame(['word', '00001'], new Tokenizer()->tokenize('word-00001'));
    }
}
