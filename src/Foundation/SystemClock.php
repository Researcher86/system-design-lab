<?php

declare(strict_types=1);

namespace SystemDesignLab\Foundation;

final class SystemClock implements Clock
{
    public function nowMs(): int
    {
        return (int) floor(microtime(true) * 1000);
    }
}
