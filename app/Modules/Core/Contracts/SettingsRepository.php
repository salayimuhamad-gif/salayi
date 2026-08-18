<?php

declare(strict_types=1);

namespace App\Modules\Core\Contracts;

/**
 * Runtime, admin-editable site settings (spec 8).
 *
 * Declared in Core and implemented in Branding so that every other module can
 * read settings without importing Branding's Eloquent models — spec 27.3,
 * "no direct cross-module database writes without a service contract".
 */
interface SettingsRepository
{
    public function get(string $key, mixed $default = null): mixed;

    public function set(string $key, mixed $value, ?int $actorId = null): void;

    public function has(string $key): bool;

    public function forget(string $key, ?int $actorId = null): void;

    /** @return array<string, mixed> */
    public function group(string $group): array;

    /** @return array<string, mixed> */
    public function all(): array;

    public function flushCache(): void;
}
