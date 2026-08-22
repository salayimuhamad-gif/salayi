<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Family uniqueness for valuation rule set versions (Wave 6 hardening).
 *
 * The 000100 unique index (scope_transaction, project_id, version) cannot
 * police the GLOBAL scope family: NULL project_id rows never collide in a
 * unique index on either engine, so two writers computing MAX+1 concurrently
 * could both land the same version number. Locking alone would leave that
 * invariant advisory; this makes the database itself refuse.
 *
 * project_family is a VIRTUAL generated column — coalesce(project_id, 0) —
 * folding the NULL family into an indexable 0, a value no real project ever
 * has (auto-increment ids start at 1). VIRTUAL rather than STORED because
 * SQLite can ALTER TABLE ... ADD only virtual generated columns, and both CI
 * engines (MariaDB 10.11, SQLite) index virtual columns; the dual-engine
 * matrix runs this up() and down() as the executable proof. The column is
 * implementation detail: not fillable, never written by code, derived by the
 * engine itself.
 *
 * Additive only. No existing row changes, the 000100 index stays (it still
 * serves project-scoped lookups), and down() removes exactly what up() adds.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('valuation_rule_sets', function (Blueprint $table): void {
            /*
             * nullable() sidesteps the ALTER-with-NOT-NULL restriction on
             * SQLite; the expression itself never yields NULL, so the
             * unique index below binds every row regardless.
             */
            $table->unsignedBigInteger('project_family')
                ->nullable()
                ->virtualAs('coalesce(project_id, 0)');
        });

        Schema::table('valuation_rule_sets', function (Blueprint $table): void {
            // Named explicitly: the auto-derived name would exceed the
            // 64-byte identifier limit on MariaDB.
            $table->unique(
                ['scope_transaction', 'project_family', 'version'],
                'vrs_family_version_unique',
            );
        });
    }

    public function down(): void
    {
        // Index before column: SQLite refuses to drop a column an index
        // still references.
        Schema::table('valuation_rule_sets', function (Blueprint $table): void {
            // MIGRATION-GUARD: intentional-drop — reversing this migration's
            // own additive unique index only.
            $table->dropUnique('vrs_family_version_unique');
        });

        Schema::table('valuation_rule_sets', function (Blueprint $table): void {
            // MIGRATION-GUARD: intentional-drop — reversing this migration's
            // own additive generated column only.
            $table->dropColumn('project_family');
        });
    }
};
