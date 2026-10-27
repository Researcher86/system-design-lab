<?php

declare(strict_types=1);

use SystemDesignLab\Experiment\Experiment;
use SystemDesignLab\Experiment\Options;
use SystemDesignLab\Experiment\Output;
use SystemDesignLab\Foundation\ByteFormatter;
use SystemDesignLab\Shortener\Base62;
use SystemDesignLab\Shortener\InMemoryLinkRepository;
use SystemDesignLab\Shortener\ShortLink;

/**
 * The memory bill for a link, and the price of the second index.
 *
 * Three layouts hold the same objects: a plain list, a code index (enough to
 * resolve) and the repository's code plus tenant+URL indexes (enough to
 * resolve and to deduplicate). The difference between the last two is what
 * idempotency costs in RAM. The measurement is PHP allocator memory, not RSS.
 */
return new Experiment(
    name: 'shorten:memory',
    description: 'PHP memory per stored link and the cost of the tenant + URL index',
    supports: ['links', 'code-length'],
    run: static function (Options $options, Output $output): void {
        $links = $options->int('links', 20_000);
        $codeLength = $options->int('code-length', 7);

        $makeLinks = static function (int $count, int $length): array {
            $result = [];

            for ($i = 0; $i < $count; ++$i) {
                $result[] = new ShortLink(
                    code: Base62::encode($i, $length),
                    longUrl: 'https://example.com/articles/' . $i,
                    tenantId: 'tenant-a',
                    title: '',
                    tags: [],
                    createdAtMs: 0,
                );
            }

            return $result;
        };

        $layouts = [
            'objects only' => static function () use ($makeLinks, $links, $codeLength): array {
                return $makeLinks($links, $codeLength);
            },
            'code index' => static function () use ($makeLinks, $links, $codeLength): array {
                $index = [];

                foreach ($makeLinks($links, $codeLength) as $link) {
                    $index[$link->code] = $link;
                }

                return $index;
            },
            'code + url index' => static function () use ($makeLinks, $links, $codeLength): InMemoryLinkRepository {
                $repository = new InMemoryLinkRepository();

                foreach ($makeLinks($links, $codeLength) as $link) {
                    $repository->save($link);
                }

                return $repository;
            },
        ];

        $output->heading(
            'Short-link memory',
            sprintf('%s links of %d base62 characters; PHP allocator memory, not RSS.', number_format($links), $codeLength),
        );
        $output->line();

        $rows = [];
        $deltas = [];

        foreach ($layouts as $name => $build) {
            gc_collect_cycles();
            $before = memory_get_usage(false);
            $structure = $build();
            gc_collect_cycles();
            $delta = memory_get_usage(false) - $before;
            $deltas[$name] = $delta;
            $rows[] = [
                $name,
                ByteFormatter::format($delta),
                sprintf('%.1f', $delta / $links),
            ];
            $output->measure(str_replace(' ', '_', $name) . '_bytes', $delta);
            $output->measure(str_replace(' ', '_', $name) . '_bytes_per_link', $delta / $links);
            unset($structure);
        }

        $urlIndexCost = $deltas['code + url index'] - $deltas['code index'];
        $rows[] = [
            'url index only',
            ByteFormatter::format($urlIndexCost),
            sprintf('%.1f', $urlIndexCost / $links),
        ];
        $output->measure('url_index_bytes', $urlIndexCost);
        $output->measure('url_index_bytes_per_link', $urlIndexCost / $links);

        $output->table(['layout', 'total', 'bytes/link'], $rows);
        $output->line();
        $output->line('The object itself is the floor; an index adds one hash bucket per entry.');
        $output->line('Idempotency buys fewer rows with a second index over the same objects.');
        $output->line('Titles and tags would add allocator blocks per link; this run keeps them empty.');
        $output->line('A production store moves both indexes to disk or a database, where the unit is a page, not a hash bucket.');
    },
);
