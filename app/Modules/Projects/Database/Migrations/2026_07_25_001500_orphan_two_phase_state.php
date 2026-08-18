<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Two-phase resolution for orphaned files (spec 26.1).
 *
 * Removing the bytes and finalising the media row that named them are separate
 * steps that can fail separately. A single `resolved_at` conflated them: the
 * sweep stamped it after deleting the file, so a failure to delete the row, to
 * reconcile the cover, or to complete a purge was hidden behind a record
 * nothing would look at again.
 *
 * `file_resolved_at` records the bytes going. `source_finalised_at` records
 * the row being cleaned up. `resolved_at` means both, and only both.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orphaned_files', function (Blueprint $table): void {
            if (! Schema::hasColumn('orphaned_files', 'file_resolved_at')) {
                $table->timestamp('file_resolved_at')->nullable();
            }

            if (! Schema::hasColumn('orphaned_files', 'source_finalised_at')) {
                $table->timestamp('source_finalised_at')->nullable();
            }

            if (! Schema::hasColumn('orphaned_files', 'handed_off_at')) {
                /*
                 * Set by whichever mechanism hands a media row to the outbox,
                 * so a second command run recognises the transition has
                 * already happened and does not record it again — repeated
                 * recording inflates the attempt count of a row nobody is
                 * retrying.
                 */
                $table->timestamp('handed_off_at')->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::table('orphaned_files', function (Blueprint $table): void {
            foreach (['file_resolved_at', 'source_finalised_at', 'handed_off_at'] as $column) {
                if (Schema::hasColumn('orphaned_files', $column)) {
                    // MIGRATION-GUARD: intentional-drop — reversing this
                    // migration's own additive columns.
                    $table->dropColumn($column);
                }
            }
        });
    }
};
