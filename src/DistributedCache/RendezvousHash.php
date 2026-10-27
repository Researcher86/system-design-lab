<?php

declare(strict_types=1);

namespace SystemDesignLab\DistributedCache;

use InvalidArgumentException;
use SystemDesignLab\Foundation\Hashers;

/**
 * Rendezvous (highest-random-weight) hashing.
 *
 * Every node scores every key; the highest scores win. Adding or removing a
 * node only changes the keys for which that node wins, so the expected share
 * of moved keys is 1/(N+1). The lookup is O(nodes) rather than O(log ring),
 * which is the trade-off against consistent hashing.
 */
final class RendezvousHash implements HashStrategy
{
    /** @var list<string> */
    private array $nodes = [];

    public function addNode(string $node): void
    {
        $node = trim($node);

        if ($node === '') {
            throw new InvalidArgumentException('Node name cannot be empty');
        }

        if (in_array($node, $this->nodes, true)) {
            throw new InvalidArgumentException(sprintf('Node %s already exists', $node));
        }

        $this->nodes[] = $node;
    }

    public function removeNode(string $node): void
    {
        $index = array_search($node, $this->nodes, true);

        if ($index === false) {
            throw new InvalidArgumentException(sprintf('Node %s does not exist', $node));
        }

        array_splice($this->nodes, $index, 1);
    }

    public function locate(string $key, int $replicas = 1): array
    {
        if ($replicas < 1) {
            throw new InvalidArgumentException('Replica count must be at least 1');
        }

        /** @var list<array{node: string, score: int}> $best */
        $best = [];

        foreach ($this->nodes as $node) {
            $score = Hashers::hash64($key . "\0" . $node);
            $position = count($best);

            while ($position > 0 && $best[$position - 1]['score'] < $score) {
                --$position;
            }

            if ($position >= $replicas) {
                continue;
            }

            array_splice($best, $position, 0, [['node' => $node, 'score' => $score]]);

            if (count($best) > $replicas) {
                array_pop($best);
            }
        }

        return array_map(static fn (array $entry): string => $entry['node'], $best);
    }

    public function nodes(): array
    {
        return $this->nodes;
    }
}
