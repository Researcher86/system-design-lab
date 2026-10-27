<?php

declare(strict_types=1);

namespace SystemDesignLab\Shortener;

interface IdGenerator
{
    /**
     * @param int $attempt Zero-based attempt inside one allocation. A generator
     *                     that derives the value from the link must mix the
     *                     attempt in, otherwise a collision can never be
     *                     retried successfully.
     */
    public function next(string $tenantId, string $longUrl, int $attempt = 0): int;
}
