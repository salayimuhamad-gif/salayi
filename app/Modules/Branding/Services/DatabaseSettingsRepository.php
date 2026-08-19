<?php

declare(strict_types=1);

namespace App\Modules\Branding\Services;

use App\Modules\Branding\Models\SiteSetting;
use App\Modules\Core\Contracts\SettingsRepository;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Eloquent-backed settings with a single cached read per request cycle.
 *
 * The whole table is cached as one entry rather than key-by-key. It is a small
 * table read on nearly every request, and on shared hosting the cache store is
 * the database itself — one round trip beats forty.
 */
final class DatabaseSettingsRepository implements SettingsRepository
{
    private const CACHE_KEY = 'mulkihawler:settings:all';

    private const CACHE_TTL = 3600;

    /** @var array<string, mixed>|null */
    private ?array $memo = null;

    public function __construct(private readonly Cache $cache) {}

    public function get(string $key, mixed $default = null): mixed
    {
        $all = $this->load();

        return array_key_exists($key, $all) ? $all[$key] : $default;
    }

    public function has(string $key): bool
    {
        return array_key_exists($key, $this->load());
    }

    public function set(string $key, mixed $value, ?int $actorId = null): void
    {
        $type = $this->inferType($value);

        DB::transaction(function () use ($key, $value, $type, $actorId): void {
            SiteSetting::query()->updateOrCreate(
                ['key' => $key],
                [
                    'group' => str_contains($key, '.') ? explode('.', $key)[0] : 'general',
                    'type' => $type,
                    'value' => $this->encode($value, $type),
                    'updated_by' => $actorId,
                ],
            );
        });

        $this->flushCache();
    }

    public function forget(string $key, ?int $actorId = null): void
    {
        SiteSetting::query()->where('key', $key)->delete();
        $this->flushCache();
    }

    /** @return array<string, mixed> */
    public function group(string $group): array
    {
        $prefix = $group.'.';

        return array_filter(
            $this->load(),
            static fn (string $key): bool => str_starts_with($key, $prefix),
            ARRAY_FILTER_USE_KEY,
        );
    }

    /** @return array<string, mixed> */
    public function all(): array
    {
        return $this->load();
    }

    public function flushCache(): void
    {
        $this->memo = null;
        $this->cache->forget(self::CACHE_KEY);
    }

    /** @return array<string, mixed> */
    private function load(): array
    {
        if ($this->memo !== null) {
            return $this->memo;
        }

        /** @var array<string, mixed> $values */
        $values = $this->cache->remember(self::CACHE_KEY, self::CACHE_TTL, function (): array {
            // Fail soft during installation: the table does not exist yet and
            // a hard failure here would break the installer's own pages.
            if (! $this->tableExists()) {
                return [];
            }

            $out = [];

            foreach (SiteSetting::query()->get(['key', 'type', 'value']) as $row) {
                $out[$row->key] = $this->decode($row->value, $row->type);
            }

            return $out;
        });

        return $this->memo = $values;
    }

    private function tableExists(): bool
    {
        try {
            return DB::getSchemaBuilder()->hasTable('site_settings');
        } catch (Throwable) {
            return false;
        }
    }

    private function inferType(mixed $value): string
    {
        return match (true) {
            is_bool($value) => 'boolean',
            is_int($value) => 'integer',
            is_float($value) => 'float',
            is_array($value) => 'json',
            default => 'string',
        };
    }

    private function encode(mixed $value, string $type): ?string
    {
        if ($value === null) {
            return null;
        }

        return match ($type) {
            'boolean' => $value ? '1' : '0',
            'json' => json_encode($value, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            default => (string) $value,
        };
    }

    private function decode(?string $value, string $type): mixed
    {
        if ($value === null) {
            return null;
        }

        return match ($type) {
            'boolean' => $value === '1',
            'integer' => (int) $value,
            'float' => (float) $value,
            'json' => json_decode($value, true, 512, JSON_THROW_ON_ERROR),
            default => $value,
        };
    }
}
