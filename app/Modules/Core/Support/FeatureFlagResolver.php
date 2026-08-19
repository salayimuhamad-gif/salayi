<?php

declare(strict_types=1);

namespace App\Modules\Core\Support;

use App\Modules\Core\Exceptions\FeatureFlagAuthorizationException;

/**
 * Pure resolution logic for feature flags, with no storage dependency.
 *
 * Split out from the Eloquent-backed repository so the precedence rules can be
 * tested exhaustively without a database, and so the same rules cannot drift
 * between the admin UI, the middleware and the seeder.
 *
 * Precedence, highest first:
 *   1. Database override set by an administrator.
 *   2. Config default in config/features.php.
 *   3. OFF.
 *
 * Rule 3 is the important one. An unknown flag — a typo in a template
 * condition, a flag from a future step referenced early, a stale name after a
 * rename — resolves to OFF. Defaulting an unrecognised flag to ON would
 * silently expose a commercial or experimental surface, which is the exact
 * failure the flag system exists to prevent.
 */
final class FeatureFlagResolver
{
    /**
     * @param  array<string, bool>  $defaults  config/features.php defaults
     * @param  list<string>  $requiresSuperAdmin  flags gated behind Super Admin
     */
    public function __construct(
        private readonly array $defaults = [],
        private readonly array $requiresSuperAdmin = [],
    ) {}

    /**
     * @param  array<string, bool>  $overrides  values stored in feature_flags
     */
    public function resolve(string $flag, array $overrides = []): bool
    {
        if (array_key_exists($flag, $overrides)) {
            return $overrides[$flag];
        }

        if (array_key_exists($flag, $this->defaults)) {
            return $this->defaults[$flag];
        }

        return false;
    }

    /**
     * @param  array<string, bool>  $overrides
     * @return array<string, bool>
     */
    public function resolveAll(array $overrides = []): array
    {
        $names = array_values(array_unique(array_merge(
            array_keys($this->defaults),
            array_keys($overrides),
        )));

        sort($names);

        $resolved = [];

        foreach ($names as $name) {
            $resolved[$name] = $this->resolve($name, $overrides);
        }

        return $resolved;
    }

    public function isKnown(string $flag): bool
    {
        return array_key_exists($flag, $this->defaults);
    }

    public function requiresSuperAdmin(string $flag): bool
    {
        return in_array($flag, $this->requiresSuperAdmin, true);
    }

    /**
     * Gate a change before it reaches storage.
     *
     * Turning a gated flag OFF is restricted too: disabling `advertising`
     * mid-campaign has contractual consequences for advertisers, so it needs
     * the same authority as enabling it.
     *
     * @throws FeatureFlagAuthorizationException
     */
    public function assertMayChange(string $flag, bool $actorIsSuperAdmin): void
    {
        if ($this->requiresSuperAdmin($flag) && ! $actorIsSuperAdmin) {
            throw FeatureFlagAuthorizationException::requiresSuperAdmin($flag);
        }
    }
}
