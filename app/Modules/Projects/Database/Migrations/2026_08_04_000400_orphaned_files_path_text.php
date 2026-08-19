<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Correction v5 (found by the MariaDB acceptance run, not by SQLite).
 *
 * `orphaned_files.path` was VARCHAR(255). The table's OWN design says
 * otherwise: `OrphanedFile::jobKey()` exists precisely because real
 * storage paths exceed 255 characters — the key is hashed DOWN to a
 * storable 255 so that the path does not have to be — and the suite's
 * long-path test states the contract in words: "a key that cannot be
 * stored means a valid path could never be recorded — nor replayed from
 * the emergency journal." Under MariaDB strict mode (Hostinger's mode)
 * inserting such a path is error 1406, so the compensation journal
 * failed at exactly the moment it existed for: recording a cleanup the
 * happy path could not finish. SQLite ignores VARCHAR lengths, which is
 * the only reason this ever looked green.
 *
 * The column becomes TEXT. Purely widening — every stored value remains
 * valid, no index includes `path` (the unique key is the hashed
 * `job_key`), and populated tables are untouched in content. The raw
 * MODIFY is used because the sanctioned change-column path needs
 * doctrine/dbal-era tooling this stack does not carry; the statement is
 * MariaDB/MySQL-safe and guarded to run only where the column is still
 * narrow.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('orphaned_files') || ! Schema::hasColumn('orphaned_files', 'path')) {
            return;
        }

        if (DB::connection()->getDriverName() !== 'mysql') {
            // SQLite already stores unbounded text in the column; there is
            // nothing to widen and MODIFY is not its dialect.
            return;
        }

        $column = DB::selectOne(
            'SELECT DATA_TYPE AS type FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?',
            ['orphaned_files', 'path'],
        );

        if ($column !== null && strtolower((string) $column->type) === 'text') {
            return; // Idempotent: a re-run has nothing to do.
        }

        DB::statement('ALTER TABLE `orphaned_files` MODIFY `path` TEXT NOT NULL');
    }

    public function down(): void
    {
        /*
         * Deliberately empty. Narrowing TEXT back to VARCHAR(255) on a
         * populated journal could truncate exactly the long paths this
         * migration exists to keep, and the platform's rollback doctrine
         * is code-level rollback over destructive schema reversal.
         */
    }
};
