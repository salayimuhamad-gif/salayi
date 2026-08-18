<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Missing status indexes (File two §21).
 *
 * Found by `scripts/security-audit.php`, which re-derives the §20/§21 checklist
 * from source on every run rather than from a document somebody ticks.
 *
 * `translations.status` is the one that matters. The translation workflow
 * filters by exactly this column — "show me everything still missing", "show me
 * what AI suggested and nobody has reviewed" — and a trilingual platform
 * accumulates thousands of rows per locale. Without an index the Translation
 * Centre scans the table on every filter change, and it degrades precisely as
 * the site grows.
 *
 * `release_records.status` and `backup_records.status` are small operational tables
 * where a scan is currently harmless. They are indexed anyway because the cost
 * is negligible and the alternative is an audit exception that has to be
 * re-justified by whoever reads the report next year.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('translations', function (Blueprint $table): void {
            $table->index('status', 'translations_status_index');
        });

        Schema::table('release_records', function (Blueprint $table): void {
            $table->index('status', 'release_records_status_index');
        });

        Schema::table('backup_records', function (Blueprint $table): void {
            $table->index('status', 'backup_records_status_index');
        });
    }

    public function down(): void
    {
        // MIGRATION-GUARD: intentional-drop — reversing this migration's own
        // indexes. Dropping an index destroys no data.
        Schema::table('translations', function (Blueprint $table): void {
            $table->dropIndex('translations_status_index');
        });

        Schema::table('release_records', function (Blueprint $table): void {
            $table->dropIndex('release_records_status_index');
        });

        Schema::table('backup_records', function (Blueprint $table): void {
            $table->dropIndex('backup_records_status_index');
        });
    }
};
