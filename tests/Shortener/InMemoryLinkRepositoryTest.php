<?php

declare(strict_types=1);

namespace SystemDesignLab\Tests\Shortener;

use PHPUnit\Framework\TestCase;
use SystemDesignLab\Shortener\DuplicateCode;
use SystemDesignLab\Shortener\InMemoryLinkRepository;
use SystemDesignLab\Shortener\ShortLink;

final class InMemoryLinkRepositoryTest extends TestCase
{
    public function testSaveAndFindByCodeAndUrl(): void
    {
        $repository = new InMemoryLinkRepository();
        $link = $this->link('0000001', 'https://example.com/article');

        $repository->save($link);

        self::assertSame($link, $repository->findByCode('0000001'));
        self::assertSame($link, $repository->findByUrl('tenant-a', 'https://example.com/article'));
        self::assertSame(1, $repository->count());
        self::assertSame([$link], $repository->all());
    }

    public function testFindByUrlIsTenantScoped(): void
    {
        $repository = new InMemoryLinkRepository();
        $first = $this->link('0000001', 'https://example.com/article', 'tenant-a');
        $second = $this->link('0000002', 'https://example.com/article', 'tenant-b');

        $repository->save($first);
        $repository->save($second);

        self::assertSame($first, $repository->findByUrl('tenant-a', 'https://example.com/article'));
        self::assertSame($second, $repository->findByUrl('tenant-b', 'https://example.com/article'));
        self::assertSame(2, $repository->count());
    }

    public function testCountersRecordReadsHitsMissesAndWrites(): void
    {
        $repository = new InMemoryLinkRepository();
        $repository->save($this->link('0000001', 'https://example.com/article'));

        self::assertNotNull($repository->findByCode('0000001'));
        self::assertNull($repository->findByCode('9999999'));
        self::assertNotNull($repository->findByUrl('tenant-a', 'https://example.com/article'));
        self::assertNull($repository->findByUrl('tenant-b', 'https://example.com/article'));

        self::assertSame(2, $repository->counters()->get('reads'));
        self::assertSame(1, $repository->counters()->get('hits'));
        self::assertSame(1, $repository->counters()->get('misses'));
        self::assertSame(2, $repository->counters()->get('urlReads'));
        self::assertSame(1, $repository->counters()->get('writes'));
    }

    public function testDuplicateCodeIsRejectedWithoutChangingTheStoredLink(): void
    {
        $repository = new InMemoryLinkRepository();
        $first = $this->link('0000001', 'https://example.com/first');
        $repository->save($first);

        try {
            $repository->save($this->link('0000001', 'https://example.com/second'));
            self::fail('A duplicate code must be rejected');
        } catch (DuplicateCode $exception) {
            self::assertSame('0000001', $exception->shortCode);
        }

        self::assertSame($first, $repository->findByCode('0000001'));
        self::assertSame(1, $repository->count());
    }

    public function testFindByCodeReturnsNullForAnEmptyRepository(): void
    {
        $repository = new InMemoryLinkRepository();

        self::assertNull($repository->findByCode('0000001'));
        self::assertNull($repository->findByUrl('tenant-a', 'https://example.com/article'));
        self::assertSame([], $repository->all());
    }

    private function link(
        string $code,
        string $longUrl,
        string $tenantId = 'tenant-a',
    ): ShortLink {
        return new ShortLink(
            code: $code,
            longUrl: $longUrl,
            tenantId: $tenantId,
            title: '',
            tags: [],
            createdAtMs: 0,
        );
    }
}
