<?php

declare(strict_types=1);

namespace SystemDesignLab\Search;

use InvalidArgumentException;

/**
 * A sorted term dictionary for prefix completion.
 *
 * Terms are inserted into a hash set. The sorted list is built lazily on the
 * first query after a change, then a lower-bound binary search finds the start
 * of the prefix range. The cost is O(T log T) on the first query after a
 * write and O(log T + results) on later queries.
 */
final class PrefixIndex
{
    /** @var array<string, true> */
    private array $terms = [];

    /** @var list<string>|null */
    private ?array $sorted = null;

    private int $rebuilds = 0;

    public function add(string $term): void
    {
        $term = mb_strtolower(trim($term), 'UTF-8');

        if ($term === '') {
            throw new InvalidArgumentException('A term cannot be empty');
        }

        if (isset($this->terms[$term])) {
            return;
        }

        $this->terms[$term] = true;
        $this->sorted = null;
    }

    /**
     * @return list<string>
     */
    public function complete(string $prefix, int $limit = 10): array
    {
        if ($limit < 1) {
            throw new InvalidArgumentException('Completion limit must be at least 1');
        }

        $prefix = mb_strtolower($prefix, 'UTF-8');
        $terms = $this->sortedTerms();
        $start = $this->lowerBound($terms, $prefix);
        $result = [];

        for ($index = $start; $index < count($terms) && count($result) < $limit; ++$index) {
            $term = $terms[$index];

            if (!str_starts_with($term, $prefix)) {
                break;
            }

            $result[] = $term;
        }

        return $result;
    }

    public function countWithPrefix(string $prefix): int
    {
        $prefix = mb_strtolower($prefix, 'UTF-8');
        $terms = $this->sortedTerms();
        $start = $this->lowerBound($terms, $prefix);
        $count = 0;

        for ($index = $start; $index < count($terms); ++$index) {
            if (!str_starts_with($terms[$index], $prefix)) {
                break;
            }

            ++$count;
        }

        return $count;
    }

    public function size(): int
    {
        return count($this->terms);
    }

    /**
     * How many times the sorted dictionary was rebuilt. A write invalidates
     * the list, so one rebuild happens on the first query after a write.
     */
    public function rebuilds(): int
    {
        return $this->rebuilds;
    }

    /**
     * @return list<string>
     */
    private function sortedTerms(): array
    {
        if ($this->sorted === null) {
            $terms = array_map(
                static fn (int|string $term): string => (string) $term,
                array_keys($this->terms),
            );
            sort($terms, SORT_STRING);
            $this->sorted = $terms;
            ++$this->rebuilds;
        }

        return $this->sorted;
    }

    /**
     * @param list<string> $terms
     */
    private function lowerBound(array $terms, string $prefix): int
    {
        $low = 0;
        $high = count($terms);

        while ($low < $high) {
            $middle = intdiv($low + $high, 2);

            if (strcmp($terms[$middle], $prefix) < 0) {
                $low = $middle + 1;
            } else {
                $high = $middle;
            }
        }

        return $low;
    }
}
