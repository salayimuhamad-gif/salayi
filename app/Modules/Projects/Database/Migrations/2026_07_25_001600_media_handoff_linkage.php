<?php

declare(strict_types=1);

use App\Modules\Core\Support\MigrationIndexes;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Exact handoff linkage on each source media row (spec 26.1).
 *
 * Handoff to the orphan outbox was inferred by looking for an `orphaned_files`
 * row with the same disk and path. That is not the same question. A path can
 * be reused by a later upload, so a NEW media row could be mistaken for one
 * already handed off — and, worse, a handoff that failed at the ceiling left
 * no trace at all, so nothing ever retried it and the row and its file became
 * permanently ownerless.
 *
 * `cleanup_outbox_id` names the exact outbox row this exact source produced.
 * Null with an exhausted attempt count is now a detectable, retryable state
 * rather than an invisible one.
 *
 * No foreign key: the outbox is designed to outlive the rows it names, and a
 * cascade would delete the evidence at precisely the wrong moment.
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (['project_media', 'project_draft_media', 'offer_media'] as $table) {
            if (! Schema::hasTable($table)) {
                continue;
            }

            Schema::table($table, function (Blueprint $blueprint) use ($table): void {
                if (! Schema::hasColumn($table, 'cleanup_outbox_id')) {
                    // The exact orphaned_files row this source produced.
                    $blueprint->unsignedBigInteger('cleanup_outbox_id')->nullable()->index();
                }

                if (! Schema::hasColumn($table, 'cleanup_handed_off_at')) {
                    $blueprint->timestamp('cleanup_handed_off_at')->nullable();
                }
            });
        }
    }

    public function down(): void
    {
        foreach (['project_media', 'project_draft_media', 'offer_media'] as $table) {
            if (! Schema::hasTable($table)) {
                continue;
            }

            /*
             * THE INDEX GOES FIRST, IN ITS OWN STATEMENT.
             *
             * `cleanup_outbox_id` was created with a chained `->index()`, and
             * dropping an indexed column without dropping its index first is a
             * guaranteed rollback failure on SQLite:
             *
             *   error in index project_media_cleanup_outbox_id_index after
             *   drop column: no such column: cleanup_outbox_id
             *
             * It must also be a SEPARATE Schema::table() call. Blueprint only
             * queues statements, so an index drop and a column drop batched
             * into one closure can still be emitted in an order SQLite
             * rejects. MigrationIndexes reads the real index list, so an
             * interrupted rollback converges on a rerun.
             */
            MigrationIndexes::dropIndexesOn($table, ['cleanup_outbox_id', 'cleanup_handed_off_at']);

            Schema::table($table, function (Blueprint $blueprint) use ($table): void {
                foreach (['cleanup_outbox_id', 'cleanup_handed_off_at'] as $column) {
                    if (Schema::hasColumn($table, $column)) {
                        // MIGRATION-GUARD: intentional-drop — reversing this
                        // migration's own additive columns. Drain outstanding
                        // cleanup first: without this linkage the association
                        // between a source row and its outbox job is lost.
                        $blueprint->dropColumn($column);
                    }
                }
            });
        }
    }
};
