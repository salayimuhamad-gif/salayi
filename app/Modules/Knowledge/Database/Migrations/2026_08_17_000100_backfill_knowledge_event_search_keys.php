<?php

declare(strict_types=1);

use App\Modules\Localization\Support\SoraniText;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Backfill `knowledge_events.search_key` from the trilingual titles.
 *
 * A GUARANTEED PRODUCTION WRITE DEFECT, live since the table shipped.
 *
 * `HasTrilingualNames::syncSearchKey()` derives the key from
 * `name_ckb`/`name_ar`/`name_en` — columns `knowledge_events` has never
 * had (its family is `title_*`) — so every save through the model wrote an
 * EMPTY search key, and the admin knowledge text search, which filters on
 * `search_key LIKE`, could not match a single row in any language. The
 * model now overrides the derivation to read the titles; this migration
 * repairs every row that predates it, with the exact same derivation, so
 * existing events become searchable immediately rather than only after
 * someone happens to re-save them.
 *
 * Mirrors 2026_07_26_000100_search_key_for_branches_and_indices.php — the
 * shipped forward repair for this same defect family — with two deltas:
 * the column already exists here, so this is DATA-ONLY; and the predicate
 * must cover the empty string as well as NULL, because the broken model
 * path actively WROTE '' on every save while direct inserts left NULL.
 *
 * Idempotent; touches only empty keys, so a key someone already repaired
 * by re-saving is never overwritten; soft-deleted rows are included (the
 * query builder applies no Eloquent scopes), exactly as the precedent —
 * a restored event must come back searchable. Derivation runs in PHP, so
 * sqlite and mariadb behave identically.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('knowledge_events') || ! Schema::hasColumn('knowledge_events', 'search_key')) {
            return;
        }

        $rows = DB::table('knowledge_events')
            ->where(function ($query): void {
                $query->whereNull('search_key')->orWhere('search_key', '');
            })
            ->select(['id', 'title_ckb', 'title_ar', 'title_en'])
            ->cursor();

        foreach ($rows as $row) {
            DB::table('knowledge_events')->where('id', $row->id)->update([
                'search_key' => SoraniText::searchKey(implode(' ', array_filter([
                    (string) ($row->title_ckb ?? ''),
                    (string) ($row->title_ar ?? ''),
                    (string) ($row->title_en ?? ''),
                ]))),
            ]);
        }
    }

    public function down(): void
    {
        // Data-only repair of a DERIVED column: there is no schema change to
        // reverse, and blanking the keys on rollback would only re-break the
        // search this migration exists to fix. Rolling back the model change
        // simply resumes writing empty keys for future saves.
    }
};
