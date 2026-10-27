<?php

declare(strict_types=1);

namespace SystemDesignLab\Shortener;

use InvalidArgumentException;
use SystemDesignLab\Instrumentation\Counters;

/**
 * Creates and resolves short links.
 *
 * The service is deliberately explicit about the hard parts:
 * - a duplicate tenant + URL returns the existing link instead of allocating
 *   a second code and a second row;
 * - a generated code that already exists is a collision, not a fatal error,
 *   and the retry budget is bounded;
 * - an optional custom alias is checked before it is stored;
 * - the id generator is injectable, so the collision path is testable without
 *   a birthday paradox.
 */
final class UrlShortener
{
    private readonly Counters $counters;

    public function __construct(
        private readonly IdGenerator $ids,
        private readonly LinkRepository $repository,
        private readonly int $codeLength = 7,
        private readonly int $maxAttempts = 20,
        ?Counters $counters = null,
    ) {
        if ($this->codeLength < 1) {
            throw new InvalidArgumentException('Code length must be at least 1');
        }

        if ($this->maxAttempts < 1) {
            throw new InvalidArgumentException('Attempt budget must be at least 1');
        }

        Base62::capacity($this->codeLength);
        $this->counters = $counters ?? new Counters();
    }

    /**
     * @param list<string> $tags
     */
    public function shorten(
        string $tenantId,
        string $longUrl,
        string $title = '',
        array $tags = [],
        int $createdAtMs = 0,
        ?string $alias = null,
    ): ShortenResult {
        $tenantId = trim($tenantId);
        $longUrl = trim($longUrl);

        if ($tenantId === '') {
            throw new InvalidArgumentException('Tenant id cannot be empty');
        }

        if (!$this->isHttpUrl($longUrl)) {
            throw new InvalidArgumentException(sprintf('Not an HTTP(S) URL: %s', $longUrl));
        }

        $this->counters->add('shortenAttempts');
        $existing = $this->repository->findByUrl($tenantId, $longUrl);

        if ($existing !== null) {
            $this->counters->add('idempotentHits');

            return new ShortenResult($existing, false, 0);
        }

        $collisions = 0;

        if ($alias !== null) {
            if (preg_match('/^[0-9A-Za-z]{4,32}$/', $alias) !== 1) {
                throw new InvalidArgumentException('Alias must be 4-32 base62 characters');
            }

            if ($this->repository->findByCode($alias) !== null) {
                throw new DuplicateCode($alias);
            }

            $code = $alias;
        } else {
            $code = $this->generateCode($tenantId, $longUrl, $collisions);
        }

        $link = new ShortLink(
            code: $code,
            longUrl: $longUrl,
            tenantId: $tenantId,
            title: trim($title),
            tags: $this->normalizeTags($tags),
            createdAtMs: $createdAtMs,
        );
        $this->repository->save($link);
        $this->counters->add('created');

        return new ShortenResult($link, true, $collisions);
    }

    public function resolve(string $code): ?ShortLink
    {
        $code = trim($code);

        if ($code === '') {
            return null;
        }

        $this->counters->add('resolveAttempts');
        $link = $this->repository->findByCode($code);

        if ($link === null) {
            $this->counters->add('resolveMisses');

            return null;
        }

        $this->counters->add('resolveHits');

        return $link;
    }

    public function counters(): Counters
    {
        return $this->counters;
    }

    private function generateCode(string $tenantId, string $longUrl, int &$collisions): string
    {
        for ($attempt = 0; $attempt < $this->maxAttempts; ++$attempt) {
            $id = $this->ids->next($tenantId, $longUrl, $attempt);
            $this->counters->add('generatedIds');

            try {
                $code = Base62::encode($id, $this->codeLength);
            } catch (InvalidArgumentException) {
                throw new CodeSpaceExhausted(sprintf(
                    'Identifier %d does not fit into %d base62 characters',
                    $id,
                    $this->codeLength,
                ));
            }

            if ($this->repository->findByCode($code) === null) {
                return $code;
            }

            ++$collisions;
            $this->counters->add('collisions');
        }

        throw new CodeSpaceExhausted(sprintf(
            'No free code after %d attempts; the code space is too full for this generator',
            $this->maxAttempts,
        ));
    }

    /**
     * @param list<string> $tags
     *
     * @return list<string>
     */
    private function normalizeTags(array $tags): array
    {
        $normalized = [];

        foreach ($tags as $tag) {
            $tag = strtolower(trim($tag));

            if ($tag !== '' && !in_array($tag, $normalized, true)) {
                $normalized[] = $tag;
            }
        }

        return $normalized;
    }

    private function isHttpUrl(string $url): bool
    {
        if (filter_var($url, FILTER_VALIDATE_URL) === false) {
            return false;
        }

        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));

        return in_array($scheme, ['http', 'https'], true);
    }
}
