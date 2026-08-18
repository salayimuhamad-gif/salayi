<?php

declare(strict_types=1);

use App\Modules\Core\Support\MigrationIndexes;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Where an orphaned file came from (spec 26.1).
 *
 * A file handed to the outbox at the cleanup ceiling left its media row behind
 * permanently: the retry commands select only rows BELOW the ceiling, so
 * nothing looked at it again. The row then blocked `completePurge()` forever
 * and sat in the gallery as a dead reference.
 *
 * Recording the source lets the sweep finish the job — once the bytes are
 * confirmed absent, the originating row can be deleted through its own
 * service, with cover reconciliation and purge completion following.
 *
 * No foreign keys: the point of the outbox is to outlive the rows it names.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orphaned_files', function (Blueprint $table): void {
            if (! Schema::hasColumn('orphaned_files', 'source_type')) {
                // 'project_media' or 'project_draft_media'.
                $table->string('source_type', 32)->nullable()->index();
            }

            if (! Schema::hasColumn('orphaned_files', 'source_id')) {
                $table->unsignedBigInteger('source_id')->nullable();
            }
        });
    }

    public function down(): void
    {
        MigrationIndexes::dropIndexesOn('orphaned_files', ['source_type', 'source_id']);

        Schema::table('orphaned_files', function (Blueprint $table): void {
            foreach (['source_type', 'source_id'] as $column) {
                if (Schema::hasColumn('orphaned_files', $column)) {
                    // MIGRATION-GUARD: intentional-drop — reversing this
                    // migration's own additive columns.
                    $table->dropColumn($column);
                }
            }
        });
    }
};
