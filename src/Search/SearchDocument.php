<?php

declare(strict_types=1);

namespace SystemDesignLab\Search;

use InvalidArgumentException;

/**
 * A document as the index sees it: an id, a title and a body.
 *
 * Titles and bodies are tokenized into one term stream. A title boost would
 * require per-field statistics; this chapter keeps one field so the BM25
 * numbers stay interpretable.
 */
final readonly class SearchDocument
{
    public function __construct(
        public string $id,
        public string $title,
        public string $body,
    ) {
        if (trim($this->id) === '') {
            throw new InvalidArgumentException('Document id cannot be empty');
        }
    }
}
