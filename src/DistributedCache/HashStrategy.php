<?php

declare(strict_types=1);

namespace SystemDesignLab\DistributedCache;

/**
 * Maps a key to the nodes that should hold it, in preference order.
 *
 * The first node is the primary; the rest are replicas. Implementations differ
 * in what happens to the mapping when the membership changes, which is exactly
 * what the remapping experiment measures.
 */
interface HashStrategy
{
    /**
     * @return list<string> At most $replicas distinct node names.
     */
    public function locate(string $key, int $replicas = 1): array;

    /**
     * @return list<string>
     */
    public function nodes(): array;

    public function addNode(string $node): void;

    public function removeNode(string $node): void;
}
