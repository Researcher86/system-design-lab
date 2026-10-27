<?php

declare(strict_types=1);

namespace SystemDesignLab\Shortener;

use RuntimeException;

final class DuplicateCode extends RuntimeException
{
    public function __construct(public readonly string $shortCode)
    {
        parent::__construct(sprintf('Short code already exists: %s', $shortCode));
    }
}
