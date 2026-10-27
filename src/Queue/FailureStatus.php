<?php

declare(strict_types=1);

namespace SystemDesignLab\Queue;

enum FailureStatus: string
{
    case Retried = 'retried';
    case DeadLettered = 'dead-lettered';
    case Stale = 'stale';
}
