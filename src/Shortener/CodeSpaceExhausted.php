<?php

declare(strict_types=1);

namespace SystemDesignLab\Shortener;

use RuntimeException;

final class CodeSpaceExhausted extends RuntimeException
{
    public function __construct(string $message = 'No free short code was found')
    {
        parent::__construct($message);
    }
}
