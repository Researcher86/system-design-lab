<?php

declare(strict_types=1);

namespace SystemDesignLab\DistributedCache;

use InvalidArgumentException;
use SystemDesignLab\Foundation\Hashers;

/**
 * Consistent hashing with virtual nodes.
 *
 * Each physical node owns `virtualNodes` positions on a 60-bit ring. A key is
 * placed clockwise from its hash, so adding or removing a node only moves the
 * keys in the arcs owned by that node. More virtual nodes make the arc sizes
 * more even at the cost of memory and a slightly larger binary search.
 */
final class HashRing implements HashStrategy
{
    /**
     * The hash space used by Hashers::hash64(). Positions are integers in
     * [0, SPACE - 1]; a collision probes the next free position.
     */
    private const SPACE = 1_152_921_504_606_846_976;

    /** @var array<int, string> */
    private array $owners = [];

    /** @var list<int> */
    private array $positions = [];

    /** @var array<string, list<int>> */
    private array $tokens = [];

    public function __construct(
        private readonly int $virtualNodes = 150,
    ) {
        if ($this->virtualNodes < 1) {
            throw new InvalidArgumentException('Virtual node count must be at least 1');
        }
    }

    public function addNode(string $node): void
    {
        $node = trim($node);

        if ($node === '') {
            throw new InvalidArgumentException('Node name cannot be empty');
        }

        if (isset($this->tokens[$node])) {
            throw new InvalidArgumentException(sprintf('Node %s already exists', $node));
        }

        $tokens = [];

        for ($index = 0; $index < $this->virtualNodes; ++$index) {
            $position = Hashers::hash64($node . '#' . $index);

            while (isset($this->owners[$position])) {
                $position = ($position + 1) % self::SPACE;
            }

            $this->owners[$position] = $node;
            $this->positions[] = $position;
            $tokens[] = $position;
        }

        sort($this->positions);
        $this->tokens[$node] = $tokens;
    }

    public function removeNode(string $node): void
    {
        $node = trim($node);

        if (!isset($this->tokens[$node])) {
            throw new InvalidArgumentException(sprintf('Node %s does not exist', $node));
        }

        foreach ($this->tokens[$node] as $position) {
            unset($this->owners[$position]);
        }

        unset($this->tokens[$node]);
        $this->positions = array_keys($this->owners);
        sort($this->positions);
    }

    public function locate(string $key, int $replicas = 1): array
    {
        if ($replicas < 1) {
            throw new InvalidArgumentException('Replica count must be at least 1');
        }

        $count = count($this->positions);

        if ($count === 0) {
            return [];
        }

        $hash = Hashers::hash64($key);
        $low = 0;
        $high = $count;

        while ($low < $high) {
            $middle = intdiv($low + $high, 2);

            if ($this->positions[$middle] < $hash) {
                $low = $middle + 1;
            } else {
                $high = $middle;
            }
        }

        $start = $low % $count;
        $result = [];
        $seen = [];

        for ($offset = 0; $offset < $count && count($result) < $replicas; ++$offset) {
            $position = $this->positions[($start + $offset) % $count];
            $node = $this->owners[$position];

            if (isset($seen[$node])) {
                continue;
            }

            $seen[$node] = true;
            $result[] = $node;
        }

        return $result;
    }

    public function nodes(): array
    {
        return array_keys($this->tokens);
    }

    public function tokenCount(): int
    {
        return count($this->positions);
    }
}
