<?php

declare(strict_types=1);

namespace App\Modules\Core\Contracts;

use App\Modules\Core\Exceptions\FeatureFlagAuthorizationException;

/**
 * Feature flags (spec Appendix D, principle "Admin-controlled growth").
 */
interface FeatureFlagRepository
{
    public function enabled(string $flag): bool;

    public function disabled(string $flag): bool;

    /**
     * @return array<string, bool> every known flag with its effective value
     */
    public function all(): array;

    /**
     * @throws FeatureFlagAuthorizationException
     *                                           when the flag requires a Super Admin and the actor is not one
     */
    public function set(string $flag, bool $value, ?int $actorId = null, bool $actorIsSuperAdmin = false): void;

    public function flushCache(): void;
}
