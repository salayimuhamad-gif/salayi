<?php

declare(strict_types=1);

namespace App\Modules\Branding\Services;

use App\Modules\Branding\Models\FeatureFlag;
use App\Modules\Core\Contracts\FeatureFlagRepository;
use App\Modules\Core\Support\FeatureFlagResolver;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Storage adapter around the pure FeatureFlagResolver.
 *
 * All precedence and authorisation logic lives in the resolver; this class only
 * loads overrides, caches them and writes changes. Keeping it thin is what
 * makes the rules testable without a database.
 */
final class DatabaseFeatureFlagRepository implements FeatureFlagRepository
{
    private const CACHE_KEY = 'mulkihawler:features:overrides';

    /** @var array<string, bool>|null */
    private ?array $memo = null;

    public function __construct(
        private readonly FeatureFlagResolver $resolver,
        private readonly Cache $cache,
    ) {}

    public function enabled(string $flag): bool
    {
        return $this->resolver->resolve($flag, $this->overrides());
    }

    public function disabled(string $flag): bool
    {
        return ! $this->enabled($flag);
    }

    /** @return array<string, bool> */
    public function all(): array
    {
        return $this->resolver->resolveAll($this->overrides());
    }

    public function set(string $flag, bool $value, ?int $actorId = null, bool $actorIsSuperAdmin = false): void
    {
        $this->resolver->assertMayChange($flag, $actorIsSuperAdmin);

        DB::transaction(function () use ($flag, $value, $actorId): void {
            FeatureFlag::query()->updateOrCreate(
                ['flag' => $flag],
                ['enabled' => $value, 'updated_by' => $actorId],
            );
        });

        $this->flushCache();
    }

    public function flushCache(): void
    {
        $this->memo = null;
        $this->cache->forget(self::CACHE_KEY);
    }

    /** @return array<string, bool> */
    private function overrides(): array
    {
        if ($this->memo !== null) {
            return $this->memo;
        }

        $ttl = (int) config('features.cache_ttl_seconds', 300);

        /** @var array<string, bool> $overrides */
        $overrides = $this->cache->remember(self::CACHE_KEY, $ttl, function (): array {
            try {
                if (! DB::getSchemaBuilder()->hasTable('feature_flags')) {
                    return [];
                }
            } catch (Throwable) {
                return [];
            }

            return FeatureFlag::query()
                ->pluck('enabled', 'flag')
                ->map(static fn (mixed $v): bool => (bool) $v)
                ->all();
        });

        return $this->memo = $overrides;
    }
}
