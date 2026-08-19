<?php

declare(strict_types=1);

use App\Modules\Core\Support\MigrationIndexes;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Durable purge state, and an outbox for files with no row (spec 12.1, 26.1).
 *
 * TWO GAPS, both of which lose track of bytes.
 *
 * 1. PURGE STATE. `purgeDraft()` staged the media set under a lock, committed,
 *    and then released it before removing bytes and deleting the draft. A
 *    concurrent upload landing in that window attached a NEW row, which the
 *    draft's cascade then deleted — while its file stayed on disk with nothing
 *    naming it. An explicit state closes the window: a draft marked `purging`
 *    refuses every mutation, so nothing new can arrive.
 *
 * 2. CLEANUP OUTBOX. Several paths write bytes before the row exists — draft
 *    uploads, final project uploads, promotion. When the database step fails
 *    the file is deleted in compensation, and when THAT fails there was
 *    nothing left but a log line. A log line is not a work queue: nobody is
 *    watching it, and nothing retries it.
 *
 *    The outbox is deliberately independent of every other table. It has to
 *    survive precisely the situations where the related row does not exist.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('project_drafts', function (Blueprint $table): void {
            if (! Schema::hasColumn('project_drafts', 'purge_status')) {
                /*
                 * null   — ordinary, editable
                 * purging — staged for removal; every mutation is refused
                 *
                 * A column rather than a deleted_at, because the draft must
                 * remain visible to the sweep that finishes the job.
                 */
                $table->string('purge_status', 16)->nullable()->index();
            }

            if (! Schema::hasColumn('project_drafts', 'purging_at')) {
                // When the transition happened, so a purge interrupted by a
                // crash can be recognised as stale rather than in progress.
                $table->timestamp('purging_at')->nullable();
            }
        });

        if (Schema::hasTable('orphaned_files')) {
            return;
        }

        Schema::create('orphaned_files', function (Blueprint $table): void {
            $table->id();

            $table->string('disk', 32);
            $table->string('path', 512);

            /*
             * Why this row exists: 'upload_compensation_failed',
             * 'promotion_rollback', 'draft_purge'. Written for a person
             * reading a backlog, not for a machine to branch on.
             */
            $table->string('reason', 64)->index();

            // Best-effort context. Nullable because the whole point is that
            // the related rows may already be gone.
            $table->unsignedBigInteger('project_draft_id')->nullable();
            $table->unsignedBigInteger('project_id')->nullable();
            $table->unsignedBigInteger('user_id')->nullable();

            $table->unsignedSmallInteger('attempts')->default(0);
            $table->string('last_error', 255)->nullable();
            $table->timestamp('last_attempted_at')->nullable();

            // Set when the file is confirmed gone; the row is kept briefly as
            // evidence rather than deleted immediately.
            $table->timestamp('resolved_at')->nullable()->index();

            $table->timestamps();

            // One outstanding record per file. A repeated failure increments
            // attempts rather than adding another row to the backlog.
            $table->unique(['disk', 'path']);
        });
    }

    public function down(): void
    {
        // MIGRATION-GUARD: intentional-drop — reversing this migration's own
        // table. Any unresolved entries are lost, so drain the backlog first:
        // `php artisan mulkihawler:sweep-orphaned-files --dry-run`.
        Schema::dropIfExists('orphaned_files');

        MigrationIndexes::dropIndexesOn('project_drafts', ['purge_status', 'purging_at']);

        Schema::table('project_drafts', function (Blueprint $table): void {
            foreach (['purge_status', 'purging_at'] as $column) {
                if (Schema::hasColumn('project_drafts', $column)) {
                    // MIGRATION-GUARD: intentional-drop — reversing this
                    // migration's own additive column.
                    $table->dropColumn($column);
                }
            }
        });
    }
};
