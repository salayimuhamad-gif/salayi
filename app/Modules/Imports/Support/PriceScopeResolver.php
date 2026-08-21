<?php

declare(strict_types=1);

namespace App\Modules\Imports\Support;

use App\Modules\Geography\Models\Area;
use App\Modules\Market\Enums\ScopeType;
use App\Modules\Projects\Models\Project;

/**
 * Resolves an import row's (scope_type, scope_external_id) to the canonical
 * internal scope_id (spec 14.2, Appendix B).
 *
 * Every scoped market consumer — IndexBuilder, the location resolver, the
 * portfolio valuer, the advisor matchers — filters price records by
 * `scope_id`, never by external id. A record whose scope type is backed by an
 * internal entity but whose `scope_id` is NULL is therefore invisible to
 * every scoped calculation: present on the review screen, absent from the
 * index it was imported to feed. The importer must conform to that internal
 * identity; the CSV contract stays external-id based.
 *
 * The mapping is the one the consumers already use, not a second identity
 * system:
 *
 *   - `area`    -> areas.id, addressed by areas.external_id (unique);
 *   - `project` -> projects.id, addressed by projects.external_id (unique);
 *   - `city`    -> no internal entity. The product is single-city, the
 *     validator makes the external id optional for city rows, and city-level
 *     indices declare scope_id NULL — IndexBuilder then matches on scope
 *     type alone;
 *   - `project_phase`, `unit_type` -> external-id-only. Neither
 *     project_phases nor project_unit_types carries an external_id column,
 *     and no consumer filters these scopes by scope_id, so their records
 *     keep scope_id NULL until the schema gives those entities an
 *     addressable identity.
 *
 * Exact match only, byte for byte, verified in PHP: MariaDB's
 * case-insensitive collation would otherwise accept 'ar-001' for 'AR-001' on
 * one engine while sqlite refused it on the other. No fuzzy matching, no
 * nearest match, no cross-type fallback — an unresolved scope is the
 * caller's to surface, never this class's to paper over.
 */
final class PriceScopeResolver
{
    /**
     * Whether records of this scope type must carry an internal scope_id.
     */
    public function requiresInternalId(ScopeType $type): bool
    {
        return match ($type) {
            ScopeType::Area, ScopeType::Project => true,
            ScopeType::City, ScopeType::ProjectPhase, ScopeType::UnitType => false,
        };
    }

    /**
     * The canonical internal id for this external id, or null.
     *
     * Soft-deleted scopes do not resolve — the models' default scope excludes
     * them — so a scope removed between preview and accept fails the row
     * instead of producing a record that points at a deleted entity.
     */
    public function resolve(ScopeType $type, ?string $externalId): ?int
    {
        if ($externalId === null || trim($externalId) === '') {
            return null;
        }

        /** @var list<array{id: int, external_id: string|null}> $candidates */
        $candidates = match ($type) {
            ScopeType::Area => Area::query()
                ->where('external_id', $externalId)
                ->get(['id', 'external_id'])
                ->map(static fn (Area $area): array => [
                    'id' => (int) $area->id, 'external_id' => $area->external_id,
                ])
                ->all(),
            ScopeType::Project => Project::query()
                ->where('external_id', $externalId)
                ->get(['id', 'external_id'])
                ->map(static fn (Project $project): array => [
                    'id' => (int) $project->id, 'external_id' => $project->external_id,
                ])
                ->all(),
            default => [],
        };

        $exact = array_values(array_filter(
            $candidates,
            static fn (array $candidate): bool => $candidate['external_id'] === $externalId,
        ));

        // external_id is unique on both tables, so two byte-exact matches
        // cannot exist; anything other than exactly one is unresolved.
        return count($exact) === 1 ? $exact[0]['id'] : null;
    }
}
