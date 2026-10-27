<?php

declare(strict_types=1);

namespace SystemDesignLab\Notification;

interface PreferenceStore
{
    public function get(string $tenantId, string $userId): UserPreferences;
}
