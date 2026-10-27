<?php

declare(strict_types=1);

namespace SystemDesignLab\DistributedCache;

use InvalidArgumentException;
use SystemDesignLab\Foundation\Hashers;

/**
 * The baseline: hash the key, take the remainder modulo the node count.
 *
 * It is O(1) per lookup and perfectly even for a uniform key stream. It is
 * also the strategy that moves almost every key when the node count changes,
 * because the divisor in every mapping changes at once.
 */
final class ModuloHash implements HashStrategy
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

        $count = count($this->nodes);

        if ($count === 0) {
            return [];
        }

        $start = Hashers::hash64($key) % $count;
        $result = [];

        for ($offset = 0; $offset < $count && count($result) < $replicas; ++$offset) {
            $result[] = $this->nodes[($start + $offset) % $count];
        }

        return $result;
    }

    public function nodes(): array
    {
        return $this->nodes;
    }
}
