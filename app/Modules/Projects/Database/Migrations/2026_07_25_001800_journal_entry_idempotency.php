<?php

declare(strict_types=1);

use App\Modules\Core\Support\MigrationIndexes;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Idempotency key for emergency-journal imports (spec 26.1).
 *
 * If a replay transferred entries successfully but then failed to rewrite the
 * claimed journal file, the next run imported those same entries again —
 * inflating each job's attempt count without any real cleanup attempt having
 * happened, which drives a job to its ceiling on paperwork alone.
 *
 * The journal writes a stable `entry_id` per line; this records which of those
 * have been consumed. Nullable, because most cleanup jobs never pass through
 * the journal at all.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('orphaned_files')) {
            return;
        }

        Schema::table('orphaned_files', function (Blueprint $table): void {
            if (! Schema::hasColumn('orphaned_files', 'journal_entry_id')) {
                // Indexed, not unique: several entries can legitimately name
                // one file over time, and a unique index would turn a repeat
                // import into a hard failure rather than a skip.
                $table->string('journal_entry_id', 64)->nullable()->index();
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('orphaned_files')) {
            return;
        }

        /*
         * The index goes FIRST, in its own statement — up() created it with
         * the chained `->index()`. MariaDB would drop it with the column;
         * SQLite refuses to drop a column an index still covers ("error in
         * index orphaned_files_journal_entry_id_index after drop column"),
         * which is exactly how the first real rollback run failed here.
         * MigrationIndexes reads the real index list, so an interrupted
         * rollback converges on a rerun.
         */
        MigrationIndexes::dropIndexesOn('orphaned_files', ['journal_entry_id']);

        Schema::table('orphaned_files', function (Blueprint $table): void {
            if (Schema::hasColumn('orphaned_files', 'journal_entry_id')) {
                // MIGRATION-GUARD: intentional-drop — reversing this
                // migration's own additive column. Any in-flight journal
                // entries lose their import record, so drain the journal with
                // `mulkihawler:replay-cleanup-journal` first.
                $table->dropColumn('journal_entry_id');
            }
        });
    }
};
