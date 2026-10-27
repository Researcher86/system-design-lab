<?php

declare(strict_types=1);

namespace SystemDesignLab\Notification;

enum DeliveryStatus: string
{
    case Immediate = 'immediate';
    case Deferred = 'deferred';
}
