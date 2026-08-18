<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Corrections v4, BLOCKERS 8 and 9 — media rows learn what state they are
 * in, and failed deletions learn how to give up gracefully.
 *
 * BLOCKER 8 (long lock): the upload now processes bytes OUTSIDE the
 * database lock, into a temporary path, then takes a short transaction to
 * claim a slot, then finalises the file. That two-phase shape needs a
 * state on the row, because between "claimed" and "finalised" the row
 * exists and its bytes do not:
 *
 *   status = 'staging' — the row holds a slot; its bytes are not final.
 *                        Never served, never exported, never counted as
 *                        deliverable, but DOES count against the 12-file
 *                        cap so concurrent uploads cannot overshoot.
 *   status = 'ready'   — the bytes are at `path` and may be served.
 *   status = 'orphaned'— finalisation failed; the sweeper owns it.
 *
 * Existing rows are backfilled to 'ready', which is what they are.
 *
 * BLOCKER 9 (unbounded pending deletions): a row whose file would not
 * delete is kept and retried, so it needs retry bookkeeping — how many
 * attempts, when to try next, what went wrong (a class name, never a path
 * or a filename), and whether a human now has to look at it.
 *
 * All additive. `deletion_pending_at` from the v3 migration is untouched
 * and keeps its meaning; these columns describe what happens next.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('portfolio_media', function (Blueprint $table): void {
            $table->string('status', 16)->nullable()->after('size_bytes');
            $table->string('staged_path', 255)->nullable()->after('status');
            $table->unsignedSmallInteger('deletion_attempts')->default(0)->after('deletion_pending_at');
            $table->timestamp('deletion_last_attempt_at')->nullable()->after('deletion_attempts');
            $table->timestamp('deletion_next_attempt_at')->nullable()->after('deletion_last_attempt_at');
            $table->string('deletion_last_error', 191)->nullable()->after('deletion_next_attempt_at');
            $table->timestamp('deletion_escalated_at')->nullable()->after('deletion_last_error');
        });

        /*
         * Every row that exists today is a finished upload. Saying so
         * explicitly is what lets the serving path require 'ready' rather
         * than treat NULL as an ambiguous maybe.
         */
        DB::table('portfolio_media')->whereNull('status')->update(['status' => 'ready']);

        Schema::table('portfolio_media', function (Blueprint $table): void {
            $table->index(['status', 'created_at'], 'portfolio_media_status_idx');
            $table->index('deletion_next_attempt_at', 'portfolio_media_deletion_next_idx');
        });
    }

    public function down(): void
    {
        Schema::table('portfolio_media', function (Blueprint $table): void {
            $table->dropIndex('portfolio_media_status_idx');
            $table->dropIndex('portfolio_media_deletion_next_idx');
        });

        Schema::table('portfolio_media', function (Blueprint $table): void {
            $table->dropColumn([
                'status',
                'staged_path',
                'deletion_attempts',
                'deletion_last_attempt_at',
                'deletion_next_attempt_at',
                'deletion_last_error',
                'deletion_escalated_at',
            ]);
        });
    }
};
