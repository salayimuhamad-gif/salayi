<?php

declare(strict_types=1);

use App\Modules\Core\Support\MigrationIndexes;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-membership project rights, and failure-safe media cleanup (spec 11.2).
 *
 * 1. `company_staff.may_manage_projects`.
 *
 *    Rights belong to the MEMBERSHIP, not to the person. Without this column,
 *    a user holding the global CompanyAccountManager role was treated as a
 *    project manager at every company they belonged to — so somebody who is a
 *    manager at company A and ordinary staff at company B could edit B's
 *    projects. A global role must never transfer authority between companies;
 *    the sibling columns `may_manage_offers` and `may_view_leads` already
 *    encode exactly that principle and this one was missing.
 *
 *    Defaults to FALSE. An existing membership gains no new authority from a
 *    deploy — rights are granted deliberately, one membership at a time.
 *
 * 2. `project_draft_media.cleanup_pending`.
 *
 *    When a physical file cannot be deleted, the row must survive: it is the
 *    only remaining reference to those bytes. Deleting it anyway produced an
 *    orphan nothing could ever find again. Flagged instead, and retried.
 *
 * Both changes are guarded so a partially-applied run can be repeated.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('company_staff', function (Blueprint $table): void {
            if (! Schema::hasColumn('company_staff', 'may_manage_projects')) {
                $table->boolean('may_manage_projects')->default(false)->after('may_manage_offers');
            }
        });

        Schema::table('project_draft_media', function (Blueprint $table): void {
            if (! Schema::hasColumn('project_draft_media', 'cleanup_pending')) {
                $table->boolean('cleanup_pending')->default(false)->after('expires_at')->index();
            }

            if (! Schema::hasColumn('project_draft_media', 'cleanup_attempts')) {
                // Bounded retry: a file that cannot be removed after several
                // attempts needs a human, not an infinite nightly loop.
                $table->unsignedSmallInteger('cleanup_attempts')->default(0)->after('cleanup_pending');
            }

            if (! Schema::hasColumn('project_draft_media', 'cleanup_last_error')) {
                $table->string('cleanup_last_error', 255)->nullable()->after('cleanup_attempts');
            }
        });
    }

    public function down(): void
    {
        MigrationIndexes::dropIndexesOn('project_draft_media', ['cleanup_pending', 'cleanup_attempts', 'cleanup_last_error']);

        Schema::table('project_draft_media', function (Blueprint $table): void {
            foreach (['cleanup_pending', 'cleanup_attempts', 'cleanup_last_error'] as $column) {
                if (Schema::hasColumn('project_draft_media', $column)) {
                    // MIGRATION-GUARD: intentional-drop — reversing this
                    // migration's own additive column.
                    $table->dropColumn($column);
                }
            }
        });

        MigrationIndexes::dropIndexesOn('company_staff', ['may_manage_projects']);

        Schema::table('company_staff', function (Blueprint $table): void {
            if (Schema::hasColumn('company_staff', 'may_manage_projects')) {
                // MIGRATION-GUARD: intentional-drop — reversing this
                // migration's own additive column. Removing it revokes
                // per-membership project rights, which is the correct
                // direction: the pre-migration state had no such grant.
                $table->dropColumn('may_manage_projects');
            }
        });
    }
};
