<?php

declare(strict_types=1);

use App\Modules\Core\Support\MigrationIndexes;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Failure-safe final media deletion, and association provenance (spec 11.2).
 *
 * 1. `project_media` gains the same cleanup columns the draft table already
 *    has. The FINAL deletion path had none: ProjectMediaController::destroy()
 *    removed the row whether or not the file went with it, so a storage error
 *    orphaned the bytes permanently. The row is the only reference; it must
 *    outlive a failed delete.
 *
 * 2. `company_project_associations` gains an explicit management status and
 *    provenance. `is_approved` alone cannot distinguish "pending review" from
 *    "rejected" from "revoked after approval" — all three are is_approved =
 *    false, and only the first should still be editable by the company that
 *    created it through the Wizard.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('project_media', function (Blueprint $table): void {
            foreach (['cleanup_pending' => 'boolean', 'cleanup_attempts' => 'smallint'] as $column => $type) {
                if (Schema::hasColumn('project_media', $column)) {
                    continue;
                }

                $type === 'boolean'
                    ? $table->boolean($column)->default(false)->index()
                    : $table->unsignedSmallInteger($column)->default(0);
            }

            if (! Schema::hasColumn('project_media', 'cleanup_last_error')) {
                $table->string('cleanup_last_error', 255)->nullable();
            }
        });

        Schema::table('company_project_associations', function (Blueprint $table): void {
            if (! Schema::hasColumn('company_project_associations', 'management_status')) {
                /*
                 * pending   — created and awaiting review; editable by its creator
                 * approved  — reviewed and active
                 * rejected  — reviewed and refused
                 * revoked   — was approved, withdrawn since
                 *
                 * Defaults to `pending`, which is what an existing unapproved
                 * row already means.
                 */
                $table->string('management_status', 16)->default('pending')->index();
            }

            if (! Schema::hasColumn('company_project_associations', 'created_by')) {
                // Who asserted this relationship. "The company that created it
                // may still edit it" is unanswerable without this.
                $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            }

            if (! Schema::hasColumn('company_project_associations', 'status_changed_at')) {
                $table->timestamp('status_changed_at')->nullable();
            }
        });
    }

    public function down(): void
    {
        MigrationIndexes::dropIndexesOn('company_project_associations', ['management_status', 'status_changed_at']);

        Schema::table('company_project_associations', function (Blueprint $table): void {
            if (Schema::hasColumn('company_project_associations', 'created_by')) {
                $table->dropConstrainedForeignId('created_by');
            }

            foreach (['management_status', 'status_changed_at'] as $column) {
                if (Schema::hasColumn('company_project_associations', $column)) {
                    // MIGRATION-GUARD: intentional-drop — reversing this
                    // migration's own additive column. `is_approved` predates
                    // it and is untouched, so no approval state is lost.
                    $table->dropColumn($column);
                }
            }
        });

        MigrationIndexes::dropIndexesOn('project_media', ['cleanup_pending', 'cleanup_attempts', 'cleanup_last_error']);

        Schema::table('project_media', function (Blueprint $table): void {
            foreach (['cleanup_pending', 'cleanup_attempts', 'cleanup_last_error'] as $column) {
                if (Schema::hasColumn('project_media', $column)) {
                    // MIGRATION-GUARD: intentional-drop — reversing this
                    // migration's own additive column.
                    $table->dropColumn($column);
                }
            }
        });
    }
};
