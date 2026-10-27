<?php

declare(strict_types=1);

namespace SystemDesignLab\DistributedCache;

/**
 * Where a read was served from.
 */
enum CacheSource: string
{
    case Primary = 'primary';
    case Replica = 'replica';
    case Miss = 'miss';
}
