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
 * How long may a code be, and what happens at the edge of the space?
 *
 * The capacity table is arithmetic: 62^length and the number of draws at which
 * the birthday probability of at least one collision reaches 1% and 50%. The
 * second half is a real run in a deliberately tiny space, where the edge is
 * reachable in milliseconds. Sequential ids stop exactly at capacity; random
 * and hashed ids stop earlier because the retry budget keeps drawing from an
 * increasingly occupied space.
 */
return new Experiment(
    name: 'shorten:space',
    description: 'base62 capacity, birthday occupancy and a real exhaustion run at a small code length',
    supports: ['links', 'code-length', 'max-attempts', 'seed'],
    run: static function (Options $options, Output $output): void {
        $links = $options->int('links', 5_000);
        $codeLength = $options->int('code-length', 2);
        $maxAttempts = $options->int('max-attempts', 20);
        $seed = $options->seed('shortener');

        $output->heading(
            'Short-code space',
            'Why seven characters are enough for capacity, and why allocation still needs a retry budget.',
        );

        $rows = [];

        for ($length = 1; $length <= 7; ++$length) {
            $capacity = Base62::capacity($length);
            $rows[] = [
                $length,
                number_format($capacity),
                number_format(birthdayDraws($capacity, 0.01)),
                number_format(birthdayDraws($capacity, 0.50)),
            ];
            $output->measure('capacity_' . $length, $capacity);
        }

        $output->table(
            ['length', 'capacity 62^n', 'draws at 1% collision', 'draws at 50% collision'],
            $rows,
        );
        $output->line('The table is arithmetic, not a run. "Draws at p" solves 1 - exp(-n^2 / 2m) = p.');
        $output->line('Capacity is not the same as a comfortable random-allocation zone.');
        $output->line();

        $space = Base62::capacity($codeLength);
        $output->line(sprintf(
            'Exhaustion run: %s draws into a %d-character space (capacity %s), retry budget %d.',
            number_format($links),
            $codeLength,
            number_format($space),
            $maxAttempts,
        ));
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

        $runRows = [];

        foreach ($generators as $name => $factory) {
            $repository = new InMemoryLinkRepository();
            $shortener = new UrlShortener($factory(), $repository, $codeLength, $maxAttempts);
            $created = 0;
            $exhaustedAt = null;

            for ($i = 0; $i < $links; ++$i) {
                try {
                    $shortener->shorten('tenant-a', 'https://example.com/articles/' . $i);
                    ++$created;
                } catch (CodeSpaceExhausted) {
                    $exhaustedAt = $i + 1;

                    break;
                }
            }

            $runRows[] = [
                $name,
                $created,
                sprintf('%.1f%%', $created / $space * 100),
                $shortener->counters()->get('collisions'),
                $exhaustedAt === null ? '-' : $exhaustedAt,
            ];

            $output->measure($name . '_created', $created);
            $output->measure($name . '_exhausted_at', $exhaustedAt ?? 0);
            $output->measure($name . '_collisions', $shortener->counters()->get('collisions'));
        }

        $output->table(
            ['strategy', 'created', 'fill', 'collisions', 'exhausted at'],
            $runRows,
        );
        $output->line();
        $output->line('A sequential counter reaches the last value and then cannot encode the next one.');
        $output->line('Random and hashed ids stop earlier: at high occupancy a bounded retry budget keeps losing.');
        $output->line(sprintf(
            'The same shape appears at seven characters, but the space is %s times larger.',
            number_format(Base62::capacity(7) / $space),
        ));
    },
);

/**
 * Number of uniform draws into `$space` at which the probability of at least
 * one collision reaches `$probability`, using the birthday approximation.
 */
function birthdayDraws(int $space, float $probability): int
{
    $draws = (int) floor(sqrt(2 * $space * log(1 / (1 - $probability))));

    return max(1, min($space, $draws));
}
