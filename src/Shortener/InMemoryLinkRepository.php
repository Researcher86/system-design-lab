<?php

declare(strict_types=1);

namespace SystemDesignLab\Shortener;

use SystemDesignLab\Instrumentation\Counters;

/**
 * An in-memory repository with two indexes: by code and by tenant + URL.
 *
 * The second index is what makes shortening idempotent. It doubles the key
 * storage and is a real cost; the `shorten:memory` experiment reports it.
 */
final class InMemoryLinkRepository implements LinkRepository
{
    /** @var array<string, ShortLink> */
    private array $byCode = [];

    /** @var array<string, ShortLink> */
    private array $byUrl = [];

    private readonly Counters $counters;

    public function __construct(?Counters $counters = null)
    {
        $this->counters = $counters ?? new Counters();
    }

    public function findByCode(string $code): ?ShortLink
    {
        $this->counters->add('reads');
        $link = $this->byCode[$code] ?? null;

        if ($link === null) {
            $this->counters->add('misses');

            return null;
        }

        $this->counters->add('hits');

        return $link;
    }

    public function findByUrl(string $tenantId, string $longUrl): ?ShortLink
    {
        $this->counters->add('urlReads');

        return $this->byUrl[$this->urlKey($tenantId, $longUrl)] ?? null;
    }

    public function save(ShortLink $link): void
    {
        if (isset($this->byCode[$link->code])) {
            throw new DuplicateCode($link->code);
        }

        $this->byCode[$link->code] = $link;
        $this->byUrl[$this->urlKey($link->tenantId, $link->longUrl)] = $link;
        $this->counters->add('writes');
    }

    public function count(): int
    {
        return count($this->byCode);
    }

    /**
     * @return list<ShortLink>
     */
    public function all(): array
    {
        return array_values($this->byCode);
    }

    public function counters(): Counters
    {
        return $this->counters;
    }

    private function urlKey(string $tenantId, string $longUrl): string
    {
        return $tenantId . "\0" . $longUrl;
    }
}
