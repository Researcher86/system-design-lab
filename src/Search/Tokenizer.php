<?php

declare(strict_types=1);

namespace SystemDesignLab\Search;

use InvalidArgumentException;

/**
 * Lowercases text and splits on everything that is not a letter or a number.
 *
 * This is deliberately a simple analyzer: no stemming, no stop words, no
 * phrase positions. The chapter measures the index and ranking, not linguistic
 * quality. A real analyzer would change both the dictionary and the postings.
 */
final class Tokenizer
{
    /**
     * @return list<string>
     */
    public function tokenize(string $text): array
    {
        $text = mb_strtolower($text, 'UTF-8');
        $parts = preg_split('/[^\p{L}\p{N}]+/u', $text, -1, PREG_SPLIT_NO_EMPTY);

        if ($parts === false) {
            throw new InvalidArgumentException('The tokenizer could not split the text');
        }

        return $parts;
    }
}
