<?php

declare(strict_types=1);

use SystemDesignLab\Experiment\Experiment;
use SystemDesignLab\Experiment\Options;
use SystemDesignLab\Experiment\Output;
use SystemDesignLab\Foundation\DeterministicRandom;
use SystemDesignLab\Shortener\Base62;
use SystemDesignLab\Shortener\CodeSpaceExhausted;
use SystemDesignLab\Shortener\HashIdGenerator;
use SystemDesignLab\Shortener\IdGenerator;
use SystemDesignLab\Shortener\InMemoryLinkRepository;
use SystemDesignLab\Shortener\RandomIdGenerator;
use SystemDesignLab\Shortener\SequentialIdGenerator;
use SystemDesignLab\Shortener\UrlShortener;

/**
 * Do random and hashed identifiers collide as often as the birthday bound
 * predicts?
 *
 * Sequential ids are the control: one writer, no collisions, predictable
 * codes. Random and hashed ids draw from the same code space, so the number of
 * failed draws should grow with the square of occupancy. The retry budget is
 * part of the measurement, not an implementation detail hidden behind it.
 */
return new Experiment(
    name: 'shorten:collisions',
    description: 'collision retries for sequential, random and hashed identifiers against the birthday bound',
    supports: ['links', 'code-length', 'max-attempts', 'seed'],
    run: static function (Options $options, Output $output): void {
        $links = $options->int('links', 20_000);
        $codeLength = $options->int('code-length', 3);
        $maxAttempts = $options->int('max-attempts', 20);
        $seed = $options->seed('shortener');
        $space = Base62::capacity($codeLength);

        $output->heading(
            'Short-code collisions',
            sprintf(
                '%s links into a %d-character code space (62^%d = %s); retry budget %d.',
                number_format($links),
                $codeLength,
                $codeLength,
                number_format($space),
                $maxAttempts,
            ),
        );
        $output->line();

        /** @var array<string, callable(): IdGenerator> $generators */
        $generators = [
            'sequential' => static fn (): IdGenerator => new SequentialIdGenerator(),
            'random' => static fn (): IdGenerator => new RandomIdGenerator(
                $space,
                new DeterministicRandom($seed . ':random'),
            ),
            'hash' => static fn (): IdGenerator => new HashIdGenerator($space),
        ];

        $rows = [];

        foreach ($generators as $name => $factory) {
            $repository = new InMemoryLinkRepository();
            $shortener = new UrlShortener($factory(), $repository, $codeLength, $maxAttempts);
            $created = 0;
            $exhaustedAt = null;

            for ($i = 0; $i < $links; ++$i) {
                try {
                    $result = $shortener->shorten('tenant-a', 'https://example.com/articles/' . $i);

                    if ($result->created) {
                        ++$created;
                    }
                } catch (CodeSpaceExhausted) {
                    $exhaustedAt = $i + 1;

                    break;
                }
            }

            $collisions = $shortener->counters()->get('collisions');
            $birthday = $created > 1 ? $created * ($created - 1) / (2 * $space) : 0.0;
            $fill = $created / $space;

            $rows[] = [
                $name,
                $created,
                $collisions,
                sprintf('%.4f', $collisions / max(1, $created)),
                sprintf('%.1f%%', $fill * 100),
                $exhaustedAt === null ? '-' : $exhaustedAt,
                sprintf('%.0f', $birthday),
            ];

            $output->measure($name . '_created', $created);
            $output->measure($name . '_collisions', $collisions);
            $output->measure($name . '_exhausted_at', $exhaustedAt ?? 0);
            $output->measure($name . '_fill', $fill);
        }

        $output->table(
            ['strategy', 'created', 'collisions', 'per link', 'fill', 'exhausted at', 'birthday estimate'],
            $rows,
        );
        $output->line();
        $output->line('Sequential ids are collision-free only because one counter owns the whole space.');
        $output->line('Random and hashed ids pay the birthday bound: collisions grow with occupancy squared.');
        $output->line('The birthday estimate is the expected number of failed draws if every draw were uniform.');
        $output->line('A collision is recovered by the next draw, so it is a retry cost until the budget runs out.');
    },
);
