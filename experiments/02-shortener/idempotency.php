<?php

declare(strict_types=1);

use SystemDesignLab\Experiment\Experiment;
use SystemDesignLab\Experiment\Options;
use SystemDesignLab\Experiment\Output;
use SystemDesignLab\Foundation\DeterministicRandom;
use SystemDesignLab\Foundation\ZipfDistribution;
use SystemDesignLab\Shortener\InMemoryLinkRepository;
use SystemDesignLab\Shortener\SequentialIdGenerator;
use SystemDesignLab\Shortener\UrlShortener;

/**
 * What does a duplicate submission cost if the service is idempotent?
 *
 * A Zipf stream repeats a small set of links far more often than the tail. The
 * shortener looks up tenant + URL before allocating a code, so repeated
 * submissions become reads instead of writes. The repository row count is the
 * evidence; the counterfactual is one row per request.
 */
return new Experiment(
    name: 'shorten:idempotency',
    description: 'duplicate submissions collapse to one row per tenant and URL under a Zipf stream',
    supports: ['requests', 'links', 'tenants', 'alpha', 'seed'],
    run: static function (Options $options, Output $output): void {
        $requests = $options->int('requests', 50_000);
        $links = $options->int('links', 5_000);
        $tenants = $options->int('tenants', 3);
        $alpha = $options->float('alpha', 1.1);
        $seed = $options->seed('shortener');
        $random = new DeterministicRandom($seed);
        $zipf = new ZipfDistribution($links, $alpha);
        $repository = new InMemoryLinkRepository();
        $shortener = new UrlShortener(new SequentialIdGenerator(), $repository);

        $output->heading(
            'Short-link idempotency',
            sprintf(
                '%s submissions over %s URLs and %d tenants, Zipf alpha %.2f.',
                number_format($requests),
                number_format($links),
                $tenants,
                $alpha,
            ),
        );
        $output->line();

        for ($i = 0; $i < $requests; ++$i) {
            $rank = $zipf->sample($random);
            $tenant = 'tenant-' . $random->intBetween(0, $tenants - 1);
            $shortener->shorten($tenant, 'https://example.com/links/' . $rank);
        }

        $created = $shortener->counters()->get('created');
        $idempotent = $shortener->counters()->get('idempotentHits');
        $rows = $repository->count();
        $writes = $repository->counters()->get('writes');
        $urlReads = $repository->counters()->get('urlReads');
        $amplification = $requests / max(1, $rows);

        $output->table(
            ['metric', 'value'],
            [
                ['submissions', number_format($requests)],
                ['distinct tenant + URL rows', number_format($rows)],
                ['created links', number_format($created)],
                ['idempotent hits', number_format($idempotent)],
                ['rows without the lookup', number_format($requests)],
                ['row amplification avoided', sprintf('%.1fx', $amplification)],
                ['repository writes', number_format($writes)],
                ['repository URL reads', number_format($urlReads)],
            ],
        );

        $output->measure('requests', $requests);
        $output->measure('distinct_rows', $rows);
        $output->measure('created', $created);
        $output->measure('idempotent_hits', $idempotent);
        $output->measure('rows_without_lookup', $requests);
        $output->measure('row_amplification', $amplification);
        $output->measure('repository_writes', $writes);
        $output->measure('repository_url_reads', $urlReads);

        $output->line();
        $output->line('Every submission still performs a URL-index read; idempotency is not free.');
        $output->line('The saved cost is the second code allocation, the second row and the second write.');
        $output->line('The row count is bounded by tenant x distinct URLs, not by request volume.');
        $output->line('The index that makes this cheap is also the extra memory measured by shorten:memory.');
    },
);
