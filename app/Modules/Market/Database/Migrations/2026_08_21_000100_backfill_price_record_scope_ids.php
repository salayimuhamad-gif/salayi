<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Backfill `price_records.scope_id` from (scope_type, scope_external_id).
 *
 * The import accept path — the only writer of price records in the
 * application — stored `scope_type` and `scope_external_id` but never
 * resolved `scope_id`, while every scoped consumer (IndexBuilder, the
 * location resolver, the portfolio valuer, the advisor matchers) filters by
 * `scope_id`. Every area- or project-scoped record accepted before the
 * importer was fixed is therefore invisible to the scoped calculations it
 * was imported to feed, published or not. The importer now resolves at
 * accept time; this migration repairs the rows that predate it, exactly as
 * 2026_08_17_000100 repaired the knowledge search keys.
 *
 * The repair is deliberately narrow:
 *
 *   - only rows whose scope_id IS NULL are candidates — a non-null scope_id
 *     is never second-guessed, so already-correct rows are untouched;
 *   - only 'area' and 'project' rows, the two scope types backed by an
 *     internal entity. City is scope-id-less by design (city indices declare
 *     scope_id NULL and match on scope type alone); project_phase and
 *     unit_type have no external-id-bearing table to resolve against;
 *   - a row is repaired only when its external id resolves BYTE-EXACTLY to
 *     exactly one live canonical row. Resolution runs in PHP so MariaDB's
 *     case-insensitive collation cannot accept a match sqlite would refuse;
 *   - a row whose canonical period slot (scope_type, scope_id,
 *     property_type, unit_type, price_type, period) is already occupied by
 *     any other record is left untouched: writing it would violate
 *     `price_records_period_slot` or silently double a period an index
 *     already sees. Rows are visited in id order, so which of two
 *     conflicting candidates wins is deterministic;
 *   - nothing else about the row changes — price values, provenance,
 *     publication status, import batch linkage and timestamps all stay
 *     exactly as they were. `updated_at` is deliberately not bumped: the
 *     record's content did not change, only the internal key the importer
 *     should have written on day one.
 *
 * Rows this cannot repair — an external id that no longer resolves, or a
 * slot conflict — remain untouched and reportable:
 * `scope_id IS NULL AND scope_external_id IS NOT NULL`.
 *
 * Soft-deleted PRICE rows are included (the query builder applies no
 * Eloquent scopes, matching the search-key precedent — a restored record
 * must come back visible to its index, and the unique slot index spans
 * trashed rows anyway). Soft-deleted SCOPES do not resolve: a record must
 * not point at a deleted area or project.
 *
 * Idempotent: every repaired row leaves the candidate set, and a second run
 * resolves the remainder identically. Runs in PHP row by row — historical
 * imports are thousands of rows, not millions.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('price_records')) {
            return;
        }

        foreach (['area' => 'areas', 'project' => 'projects'] as $scopeType => $table) {
            if (Schema::hasTable($table)) {
                $this->backfill($scopeType, $table);
            }
        }
    }

    private function backfill(string $scopeType, string $table): void
    {
        $candidates = DB::table('price_records')
            ->where('scope_type', $scopeType)
            ->whereNull('scope_id')
            ->whereNotNull('scope_external_id')
            ->where('scope_external_id', '!=', '')
            ->orderBy('id')
            ->select(['id', 'scope_external_id', 'property_type', 'unit_type', 'price_type', 'period'])
            ->get();

        if ($candidates->isEmpty()) {
            return;
        }

        // One lookup for the whole candidate set. The collation may return
        // near-matches ('ar-001' for 'AR-001'); the byte-exact filter below
        // is what decides.
        $scopes = DB::table($table)
            ->whereIn('external_id', $candidates->pluck('scope_external_id')->unique()->values()->all())
            ->whereNull('deleted_at')
            ->select(['id', 'external_id'])
            ->get();

        foreach ($candidates as $candidate) {
            $exact = $scopes->filter(
                static fn (object $scope): bool => (string) $scope->external_id === (string) $candidate->scope_external_id,
            );

            // external_id is unique, so >1 byte-exact match cannot happen;
            // anything other than exactly one live match stays unrepaired.
            if ($exact->count() !== 1) {
                continue;
            }

            $scopeId = (int) $exact->first()->id;

            $slotOccupied = DB::table('price_records')
                ->where('scope_type', $scopeType)
                ->where('scope_id', $scopeId)
                ->where('property_type', (string) $candidate->property_type)
                ->where(function ($query) use ($candidate): void {
                    if ($candidate->unit_type === null) {
                        $query->whereNull('unit_type');
                    } else {
                        $query->where('unit_type', (string) $candidate->unit_type);
                    }
                })
                ->where('price_type', (string) $candidate->price_type)
                ->where('period', (string) $candidate->period)
                ->where('id', '!=', $candidate->id)
                ->exists();

            if ($slotOccupied) {
                continue;
            }

            // The whereNull guard keeps a concurrent or repeated run from
            // overwriting a scope_id something else has set meanwhile.
            DB::table('price_records')
                ->where('id', $candidate->id)
                ->whereNull('scope_id')
                ->update(['scope_id' => $scopeId]);
        }
    }

    public function down(): void
    {
        // Data-only repair of a key the importer should always have written:
        // there is no schema change to reverse, and nulling the ids back out
        // would only re-hide these records from every scoped consumer —
        // re-creating the defect this migration exists to remove. Rolling
        // back the code change simply resumes importing without scope_id.
    }
};
