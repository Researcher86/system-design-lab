<?php

declare(strict_types=1);

namespace SystemDesignLab\Notification;

/**
 * Per-tenant, per-user preferences with an explicit default.
 *
 * A missing record is a real state: the user has not configured anything, so
 * the store returns the default instead of inventing all channels.
 */
final class InMemoryPreferenceStore implements PreferenceStore
{
    /** @var array<string, UserPreferences> */
    private array $preferences = [];

    private readonly UserPreferences $default;

    public function __construct(?UserPreferences $default = null)
    {
        $this->default = $default ?? UserPreferences::none();
    }

    public function get(string $tenantId, string $userId): UserPreferences
    {
        return $this->preferences[$this->key($tenantId, $userId)] ?? $this->default;
    }

    public function set(string $tenantId, string $userId, UserPreferences $preferences): void
    {
        $this->preferences[$this->key($tenantId, $userId)] = $preferences;
    }

    public function count(): int
    {
        return count($this->preferences);
    }

    private function key(string $tenantId, string $userId): string
    {
        return $tenantId . "\0" . $userId;
    }
}
