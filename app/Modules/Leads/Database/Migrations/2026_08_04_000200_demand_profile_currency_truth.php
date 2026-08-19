<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Corrections v4, BLOCKER 3 — a lead's currency stops being a fiction.
 *
 * `demand_profiles.currency` was `char(3) NOT NULL DEFAULT 'USD'`, and the
 * advisor recorder wrote the literal string 'USD' on every row. A person
 * who said "٢٠٠ ملیۆن دینار" was presented to the sales team as a
 * 200,000,000 USD buyer. That is not a rounding error; it is a different
 * person.
 *
 * Two changes, both non-destructive:
 *
 *   1. `currency` becomes NULLABLE with no default. NULL now means "the
 *      person did not state a currency we recognise", which is a thing the
 *      system genuinely does not know and must be allowed to say. Widening
 *      a NOT NULL column to NULL cannot fail on existing data and cannot
 *      change an existing value — every historical row keeps whatever it
 *      already held.
 *
 *   2. `currency_source` records HOW the value got there:
 *        'stated'   — the person named it and the parser recognised it
 *        'confirmed'— the person answered the clarification question
 *        'legacy'   — written before this release, when 'USD' was assumed
 *        NULL       — unknown
 *      Existing rows are backfilled to 'legacy' in the same migration, so
 *      no screen has to guess whether an old 'USD' was real. This is the
 *      only write to existing data, it touches one new column, and it is
 *      idempotent (it only fills rows where the new column is still NULL).
 *
 * Populated-database safety: an `ALTER TABLE ... MODIFY` that only relaxes
 * nullability and an `ADD COLUMN` of a nullable string are both online
 * (ALGORITHM=INPLACE) operations on InnoDB/MariaDB 10.11, and neither can
 * reject an existing row. The backfill is a single bounded UPDATE.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('demand_profiles', function (Blueprint $table): void {
            $table->string('currency_source', 16)->nullable()->after('currency');
        });

        DB::table('demand_profiles')
            ->whereNull('currency_source')
            ->update(['currency_source' => 'legacy']);

        Schema::table('demand_profiles', function (Blueprint $table): void {
            $table->string('currency', 3)->nullable()->default(null)->change();
        });
    }

    public function down(): void
    {
        /*
         * Down restores NOT NULL, which requires every row to hold a value.
         * Rows written since the upgrade may legitimately be NULL — the
         * whole point of the change — so they are given the pre-release
         * assumption back rather than blocking the rollback. This is a
         * lossy direction by nature; `currency_source` is dropped last so
         * the reason survives right up to the end.
         */
        DB::table('demand_profiles')->whereNull('currency')->update(['currency' => 'USD']);

        Schema::table('demand_profiles', function (Blueprint $table): void {
            $table->string('currency', 3)->default('USD')->nullable(false)->change();
        });

        Schema::table('demand_profiles', function (Blueprint $table): void {
            $table->dropColumn('currency_source');
        });
    }
};
