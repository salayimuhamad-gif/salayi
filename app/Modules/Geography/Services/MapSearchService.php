<?php

declare(strict_types=1);

namespace App\Modules\Geography\Services;

use App\Modules\Geography\Models\Area;
use App\Modules\Geography\Models\Place;
use App\Modules\Localization\Support\SoraniText;
use App\Modules\Projects\Models\Project;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

/**
 * Unified trilingual map search (Map Phase 5): Areas, Projects and Places
 * from MULK's own database — never a geocoder, never an external provider.
 *
 * ONE normalization: the incoming query goes through the SAME
 * SoraniText::searchKey() that built every stored `search_key`
 * (HasTrilingualNames::syncSearchKey over name_ckb + name_ar + name_en +
 * aliases), so "شارى هه‌ولير" and "شاری هەولێر" reach identical keys and a
 * Latin query lowercases the way the index did. No second normalizer, no
 * parallel index, no schema change.
 *
 * VISIBILITY is each surface's own public rule, never a weaker search rule:
 * areas must be published WITH fully published ancestry (the profile /
 * LocationResolve contract); projects must be published with real
 * coordinates (the map layer's rule); places must pass the public-pin
 * gates — published, duplicate-primary, public, operating — and only exist
 * at all while the places feature is enabled.
 *
 * BOUNDED at every step: per type, two LIKE queries biased toward prefix
 * matches (a contains-flood can never crowd a prefix match out of the
 * candidate cut), a small candidate set re-ranked in PHP, and hard caps on
 * the wire. Identical behavior on SQLite and MariaDB: LIKE uses an
 * explicit `ESCAPE '!'` because SQLite has no default escape character
 * while MariaDB assumes backslash — a bang-escaped pattern reads the same
 * on both. (Folding already strips `%`/`_` into separators, so wildcards
 * cannot reach the pattern; the escape is defense-in-depth, pinned by
 * tests.)
 */
final class MapSearchService
{
    /** §9: autocomplete, not a database dump. */
    private const AREA_CAP = 5;

    private const PROJECT_CAP = 5;

    private const PLACE_CAP = 7;

    /**
     * Candidates fetched per type before PHP ranking — headroom so an
     * exact match ranked from a contains-candidate still surfaces, while
     * staying a handful of rows, never a table.
     */
    private const CANDIDATE_FACTOR = 3;

    /**
     * @return array{
     *     areas: list<array<string, mixed>>,
     *     projects: list<array<string, mixed>>,
     *     places: list<array<string, mixed>>
     * }
     */
    public function search(string $rawQuery): array
    {
        $key = SoraniText::searchKey($rawQuery);

        // "!!" or "؟" normalizes to nothing meaningful: an honest empty
        // answer, not an error — the UI renders its empty state.
        if (mb_strlen($key) < 2) {
            return ['areas' => [], 'projects' => [], 'places' => []];
        }

        return [
            'areas' => $this->areas($key),
            'projects' => $this->projects($key),
            'places' => feature('places.database') ? $this->places($key) : [],
        ];
    }

    /** @return list<array<string, mixed>> */
    private function areas(string $key): array
    {
        $candidates = $this->candidates(
            Area::query()->published(),
            $key,
            self::AREA_CAP,
        );

        if ($candidates->isEmpty()) {
            return [];
        }

        /*
         * Published-ancestry gate, bulk (the Area profile's rule): one query
         * for every ancestor the candidates reference, then a subset check
         * per candidate. The same rows double as the breadcrumb.
         */
        $ancestorIds = $candidates
            ->flatMap(static fn (Area $area): array => $area->ancestorIds())
            ->unique()
            ->values()
            ->all();

        $publishedAncestors = $ancestorIds === []
            ? collect()
            : Area::query()->published()->whereIn('id', $ancestorIds)->get()->keyBy('id');

        $visible = $candidates->filter(static function (Area $area) use ($publishedAncestors): bool {
            foreach ($area->ancestorIds() as $ancestorId) {
                if (! $publishedAncestors->has($ancestorId)) {
                    return false;
                }
            }

            return true;
        });

        return $this->ranked($visible, $key, static fn (Area $area): string => $area->name())
            ->take(self::AREA_CAP)
            ->map(static function (Area $area) use ($publishedAncestors): array {
                $bounds = $area->bbox_min_lat !== null
                    && $area->bbox_max_lat !== null
                    && $area->bbox_min_lng !== null
                    && $area->bbox_max_lng !== null
                    ? [
                        'north' => (float) $area->bbox_max_lat,
                        'south' => (float) $area->bbox_min_lat,
                        'east' => (float) $area->bbox_max_lng,
                        'west' => (float) $area->bbox_min_lng,
                    ]
                    : null;

                return [
                    'kind' => 'area',
                    'slug' => $area->slug,
                    'name' => $area->name(),
                    'type' => $area->type->value,
                    'type_label' => __('geography.public.type.'.$area->type->value),
                    'breadcrumb' => array_map(
                        static fn (int $id): array => ['name' => $publishedAncestors->get($id)?->name() ?? ''],
                        $area->ancestorIds(),
                    ),
                    'lat' => $area->latitude !== null ? (float) $area->latitude : null,
                    'lng' => $area->longitude !== null ? (float) $area->longitude : null,
                    // The cached bbox — never boundary WKT through autocomplete.
                    'bounds' => $bounds,
                ];
            })
            ->values()
            ->all();
    }

    /** @return list<array<string, mixed>> */
    private function projects(string $key): array
    {
        $candidates = $this->candidates(
            Project::query()
                ->published()
                ->whereNotNull('latitude')
                ->whereNotNull('longitude')
                ->with(['area:id,slug,name_ckb,name_ar,name_en']),
            $key,
            self::PROJECT_CAP,
        );

        return $this->ranked($candidates, $key, static fn (Project $project): string => $project->name())
            ->take(self::PROJECT_CAP)
            ->map(static fn (Project $project): array => [
                'kind' => 'project',
                'slug' => $project->slug,
                'name' => $project->name(),
                'project_type' => $project->project_type->value,
                'area_name' => $project->area?->name(),
                'area_slug' => $project->area?->slug,
                'lat' => (float) $project->latitude,
                'lng' => (float) $project->longitude,
            ])
            ->values()
            ->all();
    }

    /** @return list<array<string, mixed>> */
    private function places(string $key): array
    {
        $candidates = $this->candidates(
            Place::query()
                ->published()
                ->where('is_public', true)
                ->operating()
                ->with([
                    'category:id,key,name_ckb,name_ar,name_en',
                    'area:id,name_ckb,name_ar,name_en',
                ]),
            $key,
            self::PLACE_CAP,
        );

        return $this->ranked($candidates, $key, static fn (Place $place): string => $place->name())
            ->take(self::PLACE_CAP)
            ->map(static fn (Place $place): array => [
                'kind' => 'place',
                'slug' => $place->slug,
                'name' => $place->name(),
                'category' => $place->category?->key,
                'category_name' => $place->category?->name(),
                'area_name' => $place->area?->name(),
                'lat' => (float) $place->latitude,
                'lng' => (float) $place->longitude,
            ])
            ->values()
            ->all();
    }

    /**
     * Two bounded passes, prefix first: `key%` candidates fill the budget
     * before any `%key%` candidate may, so ranking never loses a
     * starts-with match to an alphabetically earlier contains match. Both
     * passes are deterministic (name, then id) and hard-limited.
     *
     * @template TModel of Model
     *
     * @param  Builder<TModel>  $base
     * @return EloquentCollection<int, TModel>
     */
    private function candidates(Builder $base, string $key, int $cap): EloquentCollection
    {
        $budget = $cap * self::CANDIDATE_FACTOR;

        /** @var EloquentCollection<int, TModel> $prefix The base builder is Builder<TModel>; orderBy/limit passthroughs drop the generic. */
        $prefix = (clone $base)
            ->whereRaw("search_key like ? escape '!'", [self::likePattern($key).'%'])
            ->orderBy('name_ckb')
            ->orderBy('id')
            ->limit($budget)
            ->get();

        if ($prefix->count() >= $budget) {
            return $prefix;
        }

        /** @var EloquentCollection<int, TModel> $contains */
        $contains = (clone $base)
            ->whereRaw("search_key like ? escape '!'", ['%'.self::likePattern($key).'%'])
            ->whereNotIn('id', $prefix->modelKeys())
            ->orderBy('name_ckb')
            ->orderBy('id')
            ->limit($budget - $prefix->count())
            ->get();

        return $prefix->concat($contains);
    }

    /**
     * §8's relevance order, computed over the small candidate set with the
     * SAME folding the index used:
     *
     *   0 — a single stored name or alias folds to exactly the query;
     *   1 — a stored name or alias starts with it;
     *   2 — the combined search key starts with it;
     *   3 — it appears somewhere inside.
     *
     * Ties break on the localized display name, then slug — deterministic
     * and popularity-free.
     *
     * @template TModel of Model
     *
     * @param  Collection<int, TModel>  $candidates
     * @param  Closure(TModel): string  $displayName
     * @return Collection<int, TModel>
     */
    private function ranked(Collection $candidates, string $key, Closure $displayName): Collection
    {
        return $candidates
            ->map(fn (Model $row): array => [
                'row' => $row,
                'rank' => $this->rank($row, $key),
                'name' => $displayName($row),
                'slug' => (string) ($row->getAttribute('slug') ?? ''),
            ])
            ->sort(static function (array $a, array $b): int {
                return [$a['rank'], $a['name'], $a['slug']] <=> [$b['rank'], $b['name'], $b['slug']];
            })
            ->map(static fn (array $entry): Model => $entry['row'])
            ->values();
    }

    private function rank(Model $row, string $key): int
    {
        $fields = [
            $row->getAttribute('name_ckb'),
            $row->getAttribute('name_ar'),
            $row->getAttribute('name_en'),
        ];

        $aliases = $row->getAttribute('aliases');

        if (is_array($aliases)) {
            foreach ($aliases as $alias) {
                if (is_string($alias)) {
                    $fields[] = $alias;
                }
            }
        }

        $best = 3;

        foreach ($fields as $field) {
            if (! is_string($field) || $field === '') {
                continue;
            }

            $fieldKey = SoraniText::searchKey($field);

            if ($fieldKey === $key) {
                return 0;
            }

            if ($best > 1 && str_starts_with($fieldKey, $key)) {
                $best = 1;
            }
        }

        if ($best > 2 && str_starts_with((string) $row->getAttribute('search_key'), $key)) {
            $best = 2;
        }

        return $best;
    }

    /**
     * LIKE-safe pattern body. `!` is the explicit escape character in the
     * query (`ESCAPE '!'`) because it means the same thing on SQLite and
     * MariaDB, unlike backslash.
     */
    private static function likePattern(string $key): string
    {
        return str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $key);
    }
}
