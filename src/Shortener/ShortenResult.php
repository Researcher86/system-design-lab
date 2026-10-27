<?php

declare(strict_types=1);

namespace SystemDesignLab\Shortener;

final readonly class ShortenResult
{
    public function __construct(
        public ShortLink $link,
        public bool $created,
        public int $collisions,
    ) {
    }
}
