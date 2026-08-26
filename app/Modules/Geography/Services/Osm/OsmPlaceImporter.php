<?php

declare(strict_types=1);

namespace App\Modules\Geography\Services\Osm;

use App\Modules\Geography\Models\Place;
use App\Modules\Geography\Models\PlaceCategory;
use App\Modules\Geography\Services\AreaResolver;
use App\Modules\Geography\ValueObjects\Coordinates;
use App\Modules\Projects\Enums\PublicationStatus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Writes OSM place candidates into the existing `places` table (Map Phase 2).
 *
 * The two rules everything here serves:
 *
 * IDEMPOTENT BY EXTERNAL ID. `osm:{type}:{id}` is the identity; the same OSM
 * object can be imported any number of times and yields exactly one row —
 * found again, it is refreshed or left alone, never duplicated. Matching is
 * ONLY ever by external_id: a name that looks similar or a coordinate that
 * sits nearby is not an identity claim this importer is allowed to make.
 *
 * HUMAN WORK OUTRANKS EXTERNAL REFRESH. A row is curator-touched — and
 * therefore untouchable here — when any of these hold:
 *
 *   - verification_status is no longer 'unverified'
 *   - reviewed_by or verified_at is set
 *   - publication_status is not draft (publishing IS a human decision)
 *   - the row's source is not 'openstreetmap' (someone else authored it)
 *   - the row was soft-deleted (an administrator removed it on purpose;
 *     recreating it would resurrect their decision — and the unique
 *     external_id index would refuse anyway)
 *
 * Everything else about the row's lifecycle is deliberately boring: new rows
 * enter as draft + unverified + medium confidence, invisible to every public
 * surface until an administrator publishes them through the existing flow.
 * Because drafts are invisible to the nearby-place calculator and the public
 * map alike, creation and refresh run inside withoutEvents() — the
 * PlaceObserver's project-invalidation scan has nothing to invalidate for a
 * draft, and skipping it keeps a thousand-row import from running a thousand
 * needless scans. The observer fires exactly where visibility really
 * changes: the admin's publish action, which uses normal saves.
 *
 * Area assignment mirrors ProjectGeometryObserver's policy through the SAME
 * resolver: most-specific published area, provenance fields move together,
 * and a manual assignment (area_is_manual, or any link this importer did not
 * itself make) is never overwritten.
 */
final class OsmPlaceImporter
{
    public function __construct(private readonly AreaResolver $areas) {}

    /**
     * Split candidates by what import would do — shared by the preview (which
     * must not write) and the import itself, so the preview's numbers are the
     * import's plan rather than an estimate.
     *
     * Candidates are first deduplicated by external_id (an object can match
     * through two groups) and dropped when their category has no active row
     * (an operator may have deactivated one).
     *
     * @param  list<array<string, mixed>>  $candidates
     * @return array{
     *     new: list<array<string, mixed>>,
     *     refreshable: list<array<string, mixed>>,
     *     protected: int,
     *     deleted_protected: int,
     *     foreign_source: int,
     *     missing_category: int,
     *     category_ids: array<string, int>,
     * }
     */
    public function partition(array $candidates): array
    {
        $unique = [];

        foreach ($candidates as $candidate) {
            $unique[(string) $candidate['external_id']] ??= $candidate;
        }

        /** @var array<string, int> $categoryIds */
        $categoryIds = PlaceCategory::query()
            ->where('is_active', true)
            ->pluck('id', 'key')
            ->map(static fn ($id): int => (int) $id)
            ->all();

        $missingCategory = 0;
        $eligible = [];

        foreach ($unique as $candidate) {
            if (! isset($categoryIds[(string) $candidate['category_key']])) {
                $missingCategory++;

                continue;
            }

            $eligible[] = $candidate;
        }

        // Soft-deleted rows must be visible here: their external_id still
        // occupies the unique index, and their deletion is a human decision.
        $existing = Place::query()
            ->withTrashed()
            ->whereIn('external_id', array_column($eligible, 'external_id'))
            ->get()
            ->keyBy('external_id');

        $new = [];
        $refreshable = [];
        $protected = 0;
        $deletedProtected = 0;
        $foreignSource = 0;

        foreach ($eligible as $candidate) {
            $place = $existing->get((string) $candidate['external_id']);

            if ($place === null) {
                $new[] = $candidate;

                continue;
            }

            if ($place->trashed()) {
                $deletedProtected++;

                continue;
            }

            if ($place->source !== OsmPlaceMapper::SOURCE) {
                $foreignSource++;

                continue;
            }

            if ($this->isCuratorTouched($place)) {
                $protected++;

                continue;
            }

            $refreshable[] = $candidate;
        }

        return [
            'new' => $new,
            'refreshable' => $refreshable,
            'protected' => $protected,
            'deleted_protected' => $deletedProtected,
            'foreign_source' => $foreignSource,
            'missing_category' => $missingCategory,
            'category_ids' => $categoryIds,
        ];
    }

    /**
     * Import a bounded batch. The caller decides the bound; this method
     * writes in 100-row transactions so a failure loses one chunk, not the
     * afternoon, and an idempotent re-run picks up exactly where it stopped.
     *
     * @param  list<array<string, mixed>>  $candidates
     * @return array<string, int>
     */
    public function import(array $candidates, ?int $actingUserId): array
    {
        $plan = $this->partition($candidates);

        $summary = [
            'created' => 0,
            'refreshed' => 0,
            'unchanged' => 0,
            'protected' => $plan['protected'],
            'deleted_protected' => $plan['deleted_protected'],
            'foreign_source' => $plan['foreign_source'],
            'missing_category' => $plan['missing_category'],
            'area_assigned' => 0,
        ];

        foreach (array_chunk($plan['new'], 100) as $chunk) {
            DB::transaction(function () use ($chunk, $plan, $actingUserId, &$summary): void {
                foreach ($chunk as $candidate) {
                    $this->create($candidate, $plan['category_ids'], $actingUserId, $summary);
                }
            });
        }

        foreach (array_chunk($plan['refreshable'], 100) as $chunk) {
            DB::transaction(function () use ($chunk, $plan, &$summary): void {
                foreach ($chunk as $candidate) {
                    $this->refresh($candidate, $plan['category_ids'], $summary);
                }
            });
        }

        return $summary;
    }

    /**
     * The curator-touched test, stated once. Documented in the class
     * docblock; the preview shows its outcome as "protected".
     */
    public function isCuratorTouched(Place $place): bool
    {
        return $place->verification_status !== 'unverified'
            || $place->reviewed_by !== null
            || $place->verified_at !== null
            || $place->publication_status !== PublicationStatus::Draft;
    }

    /**
     * @param  array<string, mixed>  $candidate
     * @param  array<string, int>  $categoryIds
     * @param  array<string, int>  $summary
     */
    private function create(array $candidate, array $categoryIds, ?int $actingUserId, array &$summary): void
    {
        Place::withoutEvents(function () use ($candidate, $categoryIds, $actingUserId, &$summary): void {
            $place = new Place([
                'external_id' => (string) $candidate['external_id'],
                'place_category_id' => $categoryIds[(string) $candidate['category_key']],
                'subcategory' => $candidate['subcategory'],
                'name_ckb' => (string) $candidate['name_ckb'],
                'name_ar' => $candidate['name_ar'],
                'name_en' => $candidate['name_en'],
                'aliases' => $candidate['aliases'] === [] ? null : $candidate['aliases'],
                'website' => $candidate['website'],
                'operational_status' => 'operating',
                'is_public' => true,
                'tags' => $candidate['tags'] === [] ? null : $candidate['tags'],
                'source' => OsmPlaceMapper::SOURCE,
                'source_url' => (string) $candidate['source_url'],
                'confidence' => 'medium',
                'publication_status' => PublicationStatus::Draft,
                'is_duplicate_primary' => true,
                'created_by' => $actingUserId,
            ]);

            $point = Coordinates::tryMake((float) $candidate['lat'], (float) $candidate['lng']);
            $place->setCoordinates($point);
            $this->assignArea($place, $point, $summary);

            // withoutEvents() also silences the model's own saving hook, so
            // the search key the admin list depends on is synced by hand.
            $place->syncSearchKey();
            $place->save();

            // The slug needs the id for its collision suffix, exactly like
            // the backfill migration that introduced the column.
            $place->slug = $this->slugFor($place);
            $place->save();

            $summary['created']++;
        });
    }

    /**
     * Mirror the current OSM truth onto an untouched OSM row.
     *
     * @param  array<string, mixed>  $candidate
     * @param  array<string, int>  $categoryIds
     * @param  array<string, int>  $summary
     */
    private function refresh(array $candidate, array $categoryIds, array &$summary): void
    {
        Place::withoutEvents(function () use ($candidate, $categoryIds, &$summary): void {
            $place = Place::query()
                ->where('external_id', (string) $candidate['external_id'])
                ->first();

            if ($place === null) {
                return;
            }

            $place->fill([
                'place_category_id' => $categoryIds[(string) $candidate['category_key']],
                'subcategory' => $candidate['subcategory'],
                'name_ckb' => (string) $candidate['name_ckb'],
                'name_ar' => $candidate['name_ar'],
                'name_en' => $candidate['name_en'],
                'aliases' => $candidate['aliases'] === [] ? null : $candidate['aliases'],
                'website' => $candidate['website'],
                'tags' => $candidate['tags'] === [] ? null : $candidate['tags'],
                'source_url' => (string) $candidate['source_url'],
            ]);

            $point = Coordinates::tryMake((float) $candidate['lat'], (float) $candidate['lng']);
            $place->setCoordinates($point);

            /*
             * Re-resolve the area only when the current link is the
             * importer's own (or absent). area_is_manual, and any link whose
             * match type is not 'boundary' — including ones typed by a person
             * before provenance existed — belong to a human.
             */
            if (! $place->area_is_manual
                && ($place->area_id === null || $place->area_match_type === 'boundary')) {
                $this->assignArea($place, $point, $summary);
            }

            if (! $place->isDirty()) {
                $summary['unchanged']++;

                return;
            }

            $place->syncSearchKey();
            $place->save();

            $summary['refreshed']++;
        });
    }

    /**
     * Same field discipline as ProjectGeometryObserver::assignArea — the
     * provenance fields describe one fact and move together.
     *
     * @param  array<string, int>  $summary
     */
    private function assignArea(Place $place, ?Coordinates $point, array &$summary): void
    {
        $area = $point === null ? null : $this->areas->resolve($point);

        $place->area_id = $area?->id;
        $place->area_is_manual = false;
        $place->area_assigned_at = $area === null ? null : now();
        $place->area_match_type = $area === null ? 'none' : 'boundary';

        if ($area !== null) {
            $summary['area_assigned']++;
        }
    }

    /**
     * The slug recipe the backfill migration established: English first (the
     * only script Str::slug transliterates usefully), then ckb/ar, then the
     * honest id form; collisions resolved by the id suffix.
     */
    private function slugFor(Place $place): string
    {
        $base = Str::slug((string) ($place->name_en ?? ''));

        if ($base === '') {
            $base = Str::slug((string) ($place->name_ckb ?? ''));
        }

        if ($base === '') {
            $base = Str::slug((string) ($place->name_ar ?? ''));
        }

        if ($base === '') {
            return 'place-'.$place->id;
        }

        $base = Str::limit($base, 140, '');

        $taken = Place::query()
            ->withTrashed()
            ->where('slug', $base)
            ->whereKeyNot($place->id)
            ->exists();

        return $taken ? $base.'-'.$place->id : $base;
    }
}
