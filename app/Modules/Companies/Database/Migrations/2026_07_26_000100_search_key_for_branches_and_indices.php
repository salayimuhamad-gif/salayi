<?php

declare(strict_types=1);

use App\Modules\Core\Support\MigrationIndexes;
use App\Modules\Localization\Support\SoraniText;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * `search_key` for company branches and market indices (spec 7.1 search).
 *
 * A GUARANTEED PRODUCTION WRITE FAILURE, not a test artefact.
 *
 * `HasTrilingualNames::syncSearchKey()` assigns `$this->search_key`, and both
 * `CompanyBranch::saving()` and `MarketIndex::saving()` call it on every save.
 * Six of the eight models using that trait sit on tables that declare the
 * column; these two never did. Every attempt to create or update a branch or a
 * market index therefore died with
 *
 *   table company_branches has no column named search_key
 *
 * on SQLite, and the MySQL equivalent in production. Sorani search for those
 * two entities could not work at all, because the key it searches was never
 * storable.
 *
 * A FORWARD REPAIR, deliberately. The original create-table migrations have
 * shipped, so they are left untouched (see the release model in
 * docs/ROADMAP_STATUS.md); a database that already ran them is repaired by
 * this migration rather than by rewriting history.
 *
 * The definition matches every other search_key column in the tree exactly —
 * `string(191) nullable index` — so the search behaviour is identical across
 * entities rather than subtly different for these two.
 */
return new class extends Migration
{
    /** @var array<string, list<string>> table => trilingual name columns to key from */
    private const TARGETS = [
        'company_branches' => ['name_ckb', 'name_ar', 'name_en'],
        'market_indices' => ['name_ckb', 'name_ar', 'name_en'],
    ];

    public function up(): void
    {
        foreach (self::TARGETS as $table => $nameColumns) {
            if (! Schema::hasTable($table)) {
                continue;
            }

            if (! Schema::hasColumn($table, 'search_key')) {
                Schema::table($table, function (Blueprint $blueprint): void {
                    $blueprint->string('search_key', 191)->nullable()->index();
                });
            }

            // Backfill with the same derivation the trait uses, so existing
            // rows are searchable immediately rather than only after someone
            // happens to re-save them.
            $this->backfill($table, $nameColumns);

            if (! Schema::hasColumn($table, 'search_key')) {
                throw new RuntimeException(
                    "The search_key column could not be added to {$table}; ".
                    'saving that model would continue to fail.'
                );
            }
        }
    }

    /** @param list<string> $nameColumns */
    private function backfill(string $table, array $nameColumns): void
    {
        $present = array_values(array_filter(
            $nameColumns,
            static fn (string $column): bool => Schema::hasColumn($table, $column),
        ));

        if ($present === []) {
            return;
        }

        $hasAliases = Schema::hasColumn($table, 'aliases');
        $columns = array_merge(['id'], $present, $hasAliases ? ['aliases'] : []);

        foreach (DB::table($table)->whereNull('search_key')->select($columns)->cursor() as $row) {
            $parts = [];

            foreach ($present as $column) {
                $parts[] = (string) ($row->{$column} ?? '');
            }

            if ($hasAliases && is_string($row->aliases ?? null)) {
                /** @var mixed $aliases */
                $aliases = json_decode((string) $row->aliases, true);

                if (is_array($aliases)) {
                    foreach ($aliases as $alias) {
                        $parts[] = (string) $alias;
                    }
                }
            }

            DB::table($table)->where('id', $row->id)->update([
                'search_key' => SoraniText::searchKey(
                    implode(' ', array_filter($parts)),
                ),
            ]);
        }
    }

    public function down(): void
    {
        foreach (array_keys(self::TARGETS) as $table) {
            if (! Schema::hasTable($table) || ! Schema::hasColumn($table, 'search_key')) {
                continue;
            }

            // The index goes before its column, or SQLite refuses the drop.
            MigrationIndexes::dropIndexesOn($table, ['search_key']);

            Schema::table($table, function (Blueprint $blueprint): void {
                // MIGRATION-GUARD: intentional-drop — reversing this
                // migration's own additive column.
                $blueprint->dropColumn('search_key');
            });
        }
    }
};
