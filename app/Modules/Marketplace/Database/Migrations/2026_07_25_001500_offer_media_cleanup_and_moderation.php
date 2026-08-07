<?php

declare(strict_types=1);

use App\Modules\Core\Support\MigrationIndexes;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Cleanup and moderation detail for offer media (spec 19.1, 26.1).
 *
 * Offer media had none of the durability the project galleries gained: no
 * staged-delete flag, so a failed physical removal either lost the row or lost
 * the file; and no record of WHY an image was rejected, so a seller was told
 * "not approved" with nothing to act on.
 *
 * The cleanup columns match project_media and project_draft_media exactly, so
 * one mental model covers all three and the sweep behaves identically.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('offer_media', function (Blueprint $table): void {
            if (! Schema::hasColumn('offer_media', 'cleanup_pending')) {
                // Staged: the row survives a failed byte removal so the file
                // still has a reference, and the retry sweep can find it.
                $table->boolean('cleanup_pending')->default(false)->index();
            }

            if (! Schema::hasColumn('offer_media', 'cleanup_attempts')) {
                $table->unsignedSmallInteger('cleanup_attempts')->default(0);
            }

            if (! Schema::hasColumn('offer_media', 'cleanup_last_error')) {
                $table->string('cleanup_last_error', 255)->nullable();
            }

            if (! Schema::hasColumn('offer_media', 'moderation_reason')) {
                // A rejection without a reason is not a decision a seller can
                // respond to.
                $table->string('moderation_reason', 255)->nullable();
            }

            if (! Schema::hasColumn('offer_media', 'moderated_at')) {
                $table->timestamp('moderated_at')->nullable();
            }
        });
    }

    public function down(): void
    {
        MigrationIndexes::dropIndexesOn('offer_media', [
            'cleanup_pending', 'cleanup_attempts', 'cleanup_last_error',
            'moderation_reason', 'moderated_at',
        ]);

        Schema::table('offer_media', function (Blueprint $table): void {
            foreach ([
                'cleanup_pending', 'cleanup_attempts', 'cleanup_last_error',
                'moderation_reason', 'moderated_at',
            ] as $column) {
                if (Schema::hasColumn('offer_media', $column)) {
                    // MIGRATION-GUARD: intentional-drop — reversing this
                    // migration's own additive columns. Any pending cleanup
                    // state is lost, so drain it first with
                    // `mulkihawler:sweep-orphaned-files`.
                    $table->dropColumn($column);
                }
            }
        });
    }
};
