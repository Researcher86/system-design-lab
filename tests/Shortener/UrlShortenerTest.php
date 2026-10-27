<?php

declare(strict_types=1);

namespace SystemDesignLab\Tests\Shortener;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SystemDesignLab\Foundation\DeterministicRandom;
use SystemDesignLab\Shortener\Base62;
use SystemDesignLab\Shortener\CodeSpaceExhausted;
use SystemDesignLab\Shortener\DuplicateCode;
use SystemDesignLab\Shortener\IdGenerator;
use SystemDesignLab\Shortener\InMemoryLinkRepository;
use SystemDesignLab\Shortener\RandomIdGenerator;
use SystemDesignLab\Shortener\SequentialIdGenerator;
use SystemDesignLab\Shortener\ShortLink;
use SystemDesignLab\Shortener\UrlShortener;

final class UrlShortenerTest extends TestCase
{
    public function testShortensAndResolvesALinkWithMetadata(): void
    {
        $repository = new InMemoryLinkRepository();
        $shortener = new UrlShortener(new SequentialIdGenerator(), $repository);

        $result = $shortener->shorten(
            tenantId: 'tenant-a',
            longUrl: 'https://example.com/articles/1',
            title: '  A title  ',
            tags: ['News', 'news', ' php ', ''],
            createdAtMs: 1_234,
        );

        self::assertTrue($result->created);
        self::assertSame(0, $result->collisions);
        self::assertSame('0000000', $result->link->code);
        self::assertSame('https://short.example/0000000', $result->link->url());
        self::assertSame('A title', $result->link->title);
        self::assertSame(['news', 'php'], $result->link->tags);
        self::assertSame(1_234, $result->link->createdAtMs);
        self::assertSame($result->link, $shortener->resolve('0000000'));
    }

    public function testSequentialCodesArePredictable(): void
    {
        $shortener = $this->shortener();

        self::assertSame('0000000', $shortener->shorten('tenant-a', 'https://example.com/a')->link->code);
        self::assertSame('0000001', $shortener->shorten('tenant-a', 'https://example.com/b')->link->code);
        self::assertSame('0000002', $shortener->shorten('tenant-a', 'https://example.com/c')->link->code);
    }

    public function testRepeatedSubmissionReturnsTheSameRow(): void
    {
        $repository = new InMemoryLinkRepository();
        $shortener = new UrlShortener(new SequentialIdGenerator(), $repository);
        $url = 'https://example.com/articles/1';

        $first = $shortener->shorten('tenant-a', $url);
        $second = $shortener->shorten('tenant-a', $url);

        self::assertTrue($first->created);
        self::assertFalse($second->created);
        self::assertSame($first->link, $second->link);
        self::assertSame(0, $second->collisions);
        self::assertSame(1, $repository->count());
        self::assertSame(1, $shortener->counters()->get('created'));
        self::assertSame(1, $shortener->counters()->get('idempotentHits'));
        self::assertSame(1, $repository->counters()->get('writes'));
    }

    public function testIdempotencyIsScopedByTenant(): void
    {
        $repository = new InMemoryLinkRepository();
        $shortener = new UrlShortener(new SequentialIdGenerator(), $repository);
        $url = 'https://example.com/articles/1';

        $first = $shortener->shorten('tenant-a', $url);
        $second = $shortener->shorten('tenant-b', $url);

        self::assertNotSame($first->link->code, $second->link->code);
        self::assertSame(2, $repository->count());
        self::assertSame(2, $shortener->counters()->get('created'));
    }

    public function testCustomAliasIsStoredAndResolved(): void
    {
        $shortener = $this->shortener();

        $result = $shortener->shorten(
            'tenant-a',
            'https://example.com/articles/1',
            alias: 'promo42',
        );

        self::assertSame('promo42', $result->link->code);
        self::assertSame($result->link, $shortener->resolve('promo42'));
    }

    public function testCustomAliasMustBeUnique(): void
    {
        $shortener = $this->shortener();
        $shortener->shorten('tenant-a', 'https://example.com/a', alias: 'promo42');

        try {
            $shortener->shorten('tenant-a', 'https://example.com/b', alias: 'promo42');
            self::fail('A duplicate alias must be rejected');
        } catch (DuplicateCode $exception) {
            self::assertSame('promo42', $exception->shortCode);
        }
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidAliases(): iterable
    {
        yield 'empty' => [''];
        yield 'too short' => ['abc'];
        yield 'too long' => [str_repeat('a', 33)];
        yield 'hyphen' => ['bad-alias'];
        yield 'space' => ['with space'];
    }

    #[DataProvider('invalidAliases')]
    public function testCustomAliasRejectsInvalidValues(string $alias): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->shortener()->shorten('tenant-a', 'https://example.com/a', alias: $alias);
    }

    public function testCustomAliasAcceptsBoundaryLengths(): void
    {
        $shortener = $this->shortener();

        self::assertSame(
            'abcd',
            $shortener->shorten('tenant-a', 'https://example.com/a', alias: 'abcd')->link->code,
        );
        self::assertSame(
            str_repeat('Z', 32),
            $shortener->shorten('tenant-a', 'https://example.com/b', alias: str_repeat('Z', 32))->link->code,
        );
    }

    public function testCollisionRetriesWithTheNextIdentifier(): void
    {
        $repository = new InMemoryLinkRepository();
        $shortener = new UrlShortener(
            $this->scriptedIds(42, 42, 43),
            $repository,
        );

        $first = $shortener->shorten('tenant-a', 'https://example.com/a');
        $second = $shortener->shorten('tenant-a', 'https://example.com/b');

        self::assertSame(Base62::encode(42, 7), $first->link->code);
        self::assertSame(Base62::encode(43, 7), $second->link->code);
        self::assertSame(1, $second->collisions);
        self::assertSame(2, $repository->count());
        self::assertSame(3, $shortener->counters()->get('generatedIds'));
        self::assertSame(1, $shortener->counters()->get('collisions'));
    }

    public function testExhaustedAttemptBudgetRaisesCodeSpaceExhausted(): void
    {
        $repository = new InMemoryLinkRepository();
        $shortener = new UrlShortener($this->scriptedIds(7), $repository, maxAttempts: 3);
        $shortener->shorten('tenant-a', 'https://example.com/a');

        try {
            $shortener->shorten('tenant-a', 'https://example.com/b');
            self::fail('A full retry budget must raise CodeSpaceExhausted');
        } catch (CodeSpaceExhausted) {
            self::assertSame(3, $shortener->counters()->get('collisions'));
            self::assertSame(4, $shortener->counters()->get('generatedIds'));
            self::assertSame(1, $repository->count());
        }
    }

    public function testIdentifierThatDoesNotFitTheCodeLengthRaisesCodeSpaceExhausted(): void
    {
        $shortener = new UrlShortener(
            $this->scriptedIds(Base62::capacity(2)),
            new InMemoryLinkRepository(),
            codeLength: 2,
        );

        $this->expectException(CodeSpaceExhausted::class);
        $this->expectExceptionMessage('does not fit into 2 base62 characters');
        $shortener->shorten('tenant-a', 'https://example.com/a');
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function invalidLinks(): iterable
    {
        yield 'empty tenant' => ['', 'https://example.com'];
        yield 'blank tenant' => ['   ', 'https://example.com'];
        yield 'empty url' => ['tenant-a', ''];
        yield 'not a url' => ['tenant-a', 'not a url'];
        yield 'no scheme' => ['tenant-a', 'example.com/article'];
        yield 'ftp scheme' => ['tenant-a', 'ftp://example.com/file'];
    }

    #[DataProvider('invalidLinks')]
    public function testInvalidInputIsRejected(string $tenantId, string $longUrl): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->shortener()->shorten($tenantId, $longUrl);
    }

    public function testConstructorRejectsAZeroCodeLength(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new UrlShortener(new SequentialIdGenerator(), new InMemoryLinkRepository(), codeLength: 0);
    }

    public function testConstructorRejectsAZeroAttemptBudget(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new UrlShortener(new SequentialIdGenerator(), new InMemoryLinkRepository(), maxAttempts: 0);
    }

    public function testConstructorRejectsACodeSpaceAboveTheIntegerRange(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new UrlShortener(new SequentialIdGenerator(), new InMemoryLinkRepository(), codeLength: 11);
    }

    public function testResolveReturnsNullForAnEmptyCodeWithoutCountingAnAttempt(): void
    {
        $shortener = $this->shortener();

        self::assertNull($shortener->resolve(''));
        self::assertNull($shortener->resolve('   '));
        self::assertSame(0, $shortener->counters()->get('resolveAttempts'));
    }

    public function testCountersRecordCreateResolveAndMisses(): void
    {
        $repository = new InMemoryLinkRepository();
        $shortener = new UrlShortener(new SequentialIdGenerator(), $repository);
        $shortener->shorten('tenant-a', 'https://example.com/a');
        $shortener->shorten('tenant-a', 'https://example.com/b');
        $shortener->shorten('tenant-a', 'https://example.com/a');
        $shortener->resolve('0000000');
        $shortener->resolve('9999999');

        self::assertSame(3, $shortener->counters()->get('shortenAttempts'));
        self::assertSame(2, $shortener->counters()->get('created'));
        self::assertSame(1, $shortener->counters()->get('idempotentHits'));
        self::assertSame(2, $shortener->counters()->get('generatedIds'));
        self::assertSame(2, $shortener->counters()->get('resolveAttempts'));
        self::assertSame(1, $shortener->counters()->get('resolveHits'));
        self::assertSame(1, $shortener->counters()->get('resolveMisses'));
        self::assertSame(4, $repository->counters()->get('reads'));
        self::assertSame(1, $repository->counters()->get('hits'));
        self::assertSame(3, $repository->counters()->get('misses'));
        self::assertSame(3, $repository->counters()->get('urlReads'));
        self::assertSame(2, $repository->counters()->get('writes'));
    }

    public function testRandomIdentifiersSurviveCollisionsAndStayIdempotent(): void
    {
        $repository = new InMemoryLinkRepository();
        $shortener = new UrlShortener(
            new RandomIdGenerator(256, new DeterministicRandom('differential')),
            $repository,
            codeLength: 3,
        );
        $model = [];

        for ($tenant = 0; $tenant < 3; ++$tenant) {
            for ($link = 0; $link < 20; ++$link) {
                $result = $shortener->shorten(
                    'tenant-' . $tenant,
                    'https://example.com/links/' . $link,
                );

                self::assertTrue($result->created);
                $model[$tenant . ':' . $link] = $result->link->code;
            }
        }

        $random = new DeterministicRandom('differential-replay');

        for ($i = 0; $i < 200; ++$i) {
            $tenant = $random->intBetween(0, 2);
            $link = $random->intBetween(0, 19);
            $result = $shortener->shorten(
                'tenant-' . $tenant,
                'https://example.com/links/' . $link,
            );

            self::assertFalse($result->created);
            self::assertSame($model[$tenant . ':' . $link], $result->link->code);
        }

        self::assertCount(60, $model);
        self::assertCount(60, array_unique($model));
        self::assertSame(60, $repository->count());
        self::assertSame(60, $shortener->counters()->get('created'));
        self::assertSame(200, $shortener->counters()->get('idempotentHits'));

        foreach ($model as $code) {
            self::assertInstanceOf(ShortLink::class, $shortener->resolve($code));
        }
    }

    private function shortener(): UrlShortener
    {
        return new UrlShortener(new SequentialIdGenerator(), new InMemoryLinkRepository());
    }

    private function scriptedIds(int ...$values): IdGenerator
    {
        return new class (array_values($values)) implements IdGenerator {
            /** @var list<int> */
            private array $values;

            private int $index = 0;

            /**
             * @param list<int> $values
             */
            public function __construct(array $values)
            {
                $this->values = $values;
            }

            public function next(string $tenantId, string $longUrl, int $attempt = 0): int
            {
                $value = $this->values[min($this->index, count($this->values) - 1)];
                ++$this->index;

                return $value;
            }
        };
    }
}
