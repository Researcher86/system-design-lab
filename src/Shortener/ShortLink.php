<?php

declare(strict_types=1);

namespace SystemDesignLab\Shortener;

/**
 * One stored short link.
 *
 * The title and tags are extra metadata for the search chapter; the shortener
 * itself only needs the code, the destination and the tenant.
 */
final readonly class ShortLink
{
    /**
     * @param list<string> $tags
     */
    public function __construct(
        public string $code,
        public string $longUrl,
        public string $tenantId,
        public string $title,
        public array $tags,
        public int $createdAtMs,
    ) {
    }

    public function url(): string
    {
        return 'https://short.example/' . $this->code;
    }
}
