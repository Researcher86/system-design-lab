<?php

declare(strict_types=1);

namespace SystemDesignLab\Shortener;

use SystemDesignLab\Instrumentation\Counters;

interface LinkRepository
{
    public function findByCode(string $code): ?ShortLink;

    public function findByUrl(string $tenantId, string $longUrl): ?ShortLink;

    public function save(ShortLink $link): void;

    public function count(): int;

    /**
     * @return list<ShortLink>
     */
    public function all(): array;

    public function counters(): Counters;
}
