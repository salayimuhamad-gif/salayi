<?php

declare(strict_types=1);

use App\Modules\Localization\Support\SoraniText;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Backfill `offers.search_key` from the trilingual titles.
 *
 * A GUARANTEED PRODUCTION WRITE DEFECT, live since the marketplace shipped.
 *
 * The column was created with the marketplace tables and BOTH offer
 * searches filter on it — the admin moderation list
 * (`Admin\OfferController::index`) and the public /offers browse
 * (`Public\OfferBrowseController::index`) — but no code path ever wrote
 * it: `Offer` never adopted `HasTrilingualNames` and defined no derivation
 * of its own, so every offer's key stayed NULL and every text query, in
 * every locale, on both surfaces, matched nothing. The model now derives
 * the key from `title_ckb`/`title_ar`/`title_en`; this migration repairs
 * every row that predates it, with the exact same derivation, so existing
 * offers become findable immediately rather than only after someone
 * happens to re-save them.
 *
 * Mirrors the two shipped forward repairs for this defect family —
 * 2026_07_26_000100_search_key_for_branches_and_indices.php and
 * 2026_08_17_000100_backfill_knowledge_event_search_keys.php. As there,
 * this is DATA-ONLY (the column exists), the predicate covers '' as well
 * as NULL defensively, only empty keys are touched (a key someone already
 * repaired by re-saving is never overwritten), soft-deleted rows are
 * included (the query builder applies no Eloquent scopes — a restored
 * offer must come back findable), the pass is idempotent, and the
 * derivation runs in PHP so sqlite and mariadb behave identically.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('offers') || ! Schema::hasColumn('offers', 'search_key')) {
            return;
        }

        $rows = DB::table('offers')
            ->where(function ($query): void {
                $query->whereNull('search_key')->orWhere('search_key', '');
            })
            ->select(['id', 'title_ckb', 'title_ar', 'title_en'])
            ->cursor();

        foreach ($rows as $row) {
            DB::table('offers')->where('id', $row->id)->update([
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
