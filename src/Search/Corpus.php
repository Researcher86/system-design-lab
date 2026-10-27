<?php

declare(strict_types=1);

namespace SystemDesignLab\Search;

use InvalidArgumentException;
use SystemDesignLab\Foundation\DeterministicRandom;
use SystemDesignLab\Foundation\ZipfDistribution;

/**
 * A deterministic synthetic corpus for the search experiments.
 *
 * Real documents are not shipped with the repository; generated ones keep the
 * input reproducible and small enough to index in seconds. Each document gets
 * one topic term and a body sampled from a Zipf distribution over a fixed
 * vocabulary. The topic terms give the ranking and shard experiments a known
 * relevance signal; the Zipf tail gives them realistic document frequencies.
 *
 * This is experiment input, not part of the search engine.
 */
final class Corpus
{
    public const TOPICS = 100;

    private const ALPHA = 1.1;

    /**
     * @return list<SearchDocument>
     */
    public static function generate(
        int $documents,
        int $vocabulary,
        int $termsPerDoc,
        string $seed,
    ): array {
        if ($documents < 1 || $vocabulary < 1 || $termsPerDoc < 1) {
            throw new InvalidArgumentException('Corpus sizes must be positive');
        }

        $random = new DeterministicRandom($seed);
        $zipf = new ZipfDistribution($vocabulary, self::ALPHA);
        $result = [];

        for ($index = 0; $index < $documents; ++$index) {
            $topic = $random->intBetween(0, self::TOPICS - 1);
            $topicTerm = self::topicTerm($topic);
            $length = max(4, (int) round($termsPerDoc * (0.6 + 0.8 * $random->nextFloat())));
            $body = [];

            for ($position = 0; $position < $length; ++$position) {
                if ($position < 3) {
                    $body[] = $topicTerm;

                    continue;
                }

                $body[] = self::wordTerm($zipf->sample($random), $vocabulary);
            }

            $result[] = new SearchDocument(
                id: sprintf('doc-%06d', $index),
                title: sprintf('Link %d %s', $index, $topicTerm),
                body: implode(' ', $random->shuffle($body)),
            );
        }

        return $result;
    }

    public static function wordTerm(int $rank, int $vocabulary): string
    {
        if ($vocabulary < 1 || $rank < 1 || $rank > $vocabulary) {
            throw new InvalidArgumentException(sprintf(
                'Word rank must be in [1, %d]',
                $vocabulary,
            ));
        }

        return 'word' . str_pad(
            (string) $rank,
            max(5, strlen((string) ($vocabulary - 1))),
            '0',
            STR_PAD_LEFT,
        );
    }

    public static function topicTerm(int $topic): string
    {
        if ($topic < 0 || $topic >= self::TOPICS) {
            throw new InvalidArgumentException(sprintf('Topic must be in [0, %d)', self::TOPICS));
        }

        return sprintf('topic%04d', $topic);
    }
}
