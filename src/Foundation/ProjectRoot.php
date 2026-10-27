<?php

declare(strict_types=1);

namespace SystemDesignLab\Foundation;

final class ProjectRoot
{
    public static function path(): string
    {
        return dirname(__DIR__, 2);
    }
}
