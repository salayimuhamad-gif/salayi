<?php

declare(strict_types=1);

use App\Modules\Core\Support\MigrationIndexes;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Daily digest batching (spec 22.2).
 *
 * Spec 22.2 offers a daily digest as an alternative to immediate delivery, and
 * without it every matched saved search sends its own message. Ten matches in
 * an afternoon is ten Telegram notifications, and the reliable outcome of that
 * is a muted bot — after which the rejection notice this module exists to
 * deliver arrives somewhere nobody looks.
 *
 * Two additions, both reversible:
 *
 *   1. `notification_preferences` — one row per user, holding the frequency
 *      choice. A table rather than a column on `users` because the frequency
 *      is a notification concern and `users` is already carrying phone, MFA and
 *      Telegram material that spec 32.2 keeps tightly held.
 *
 *   2. `digest_state` / `digest_sent_at` on `notifications` — the queue itself.
 *      A separate queue table was the obvious alternative and was rejected: the
 *      row already exists, already belongs to the recipient, and duplicating
 *      its subject into a second table creates two versions of one message that
 *      can disagree.
 */
return new class extends Migration
{
    public function up(): void
    {
        /*
         * STAGED RECONCILIATION, NOT ONE BROAD GUARD.
         *
         * Two guards have been wrong here in turn. The first tested
         * `notifications.user_id` — a column created by 000200 — so it fired on
         * every install and this migration never ran at all. Replacing it with
         * a test on `digest_state` fixed that but kept the same shape, and one
         * component still stood proxy for the whole contract: a database where
         * the columns exist but `notification_preferences` was lost skipped the
         * table forever, and one where the table exists but the columns do not
         * hit `Schema::create()` on an existing table and threw.
         *
         * Each component is now reconciled independently and then verified, so
         * every partial state converges on the same final schema.
         */
        if (! Schema::hasTable('notifications')) {
            throw new RuntimeException(
                'The notifications table is missing; 000200 must run before the digest migration.'
            );
        }

        if (! Schema::hasTable('users')) {
            throw new RuntimeException(
                'The users table is missing; notification preferences reference it.'
            );
        }

        // ---- 1. Preferences table.
        if (! Schema::hasTable('notification_preferences')) {
            Schema::create('notification_preferences', function (Blueprint $table): void {
                $table->id();
                // Unique: the frequency is a single choice, and two rows would
                // make "which one wins" a question the code has to answer
                // arbitrarily.
                $table->foreignId('user_id')->unique()->constrained('users')->cascadeOnDelete();

                // 'immediate' | 'daily'. A string rather than an enum column so
                // a third option does not need a schema change on shared
                // hosting, where an ALTER on a large table is a risk with no
                // rollback.
                $table->string('frequency', 16)->default('immediate');

                // Local hour the digest should be sent, 0–23. Erbil is a single
                // timezone, so one hour per user is enough and a per-user
                // timezone would be precision this product cannot use.
                $table->unsignedTinyInteger('digest_hour')->default(8);

                $table->timestamps();
            });
        }

        // ---- 2. Digest columns, each guarded on its own.
        if (! Schema::hasColumn('notifications', 'digest_state')) {
            Schema::table('notifications', function (Blueprint $table): void {
                // 'none'   — delivered immediately, nothing pending
                // 'pending'— in-app record written, external send deferred
                // 'sent'   — included in a digest that has gone out
                $table->string('digest_state', 16)->default('none');
            });
        }

        if (! Schema::hasColumn('notifications', 'digest_sent_at')) {
            Schema::table('notifications', function (Blueprint $table): void {
                $table->timestamp('digest_sent_at')->nullable();
            });
        }

        // ---- 3. Index, only once both of its columns exist.
        if (! $this->indexExists('notifications', 'notifications_digest_idx')
            && Schema::hasColumn('notifications', 'user_id')
            && Schema::hasColumn('notifications', 'digest_state')) {
            Schema::table('notifications', function (Blueprint $table): void {
                // The digest command's only query: pending rows for one user,
                // oldest first. Without this it is a full scan every run.
                $table->index(['user_id', 'digest_state'], 'notifications_digest_idx');
            });
        }

        // ---- 4. Verify the whole contract, not one component of it.
        $missing = [];

        if (! Schema::hasTable('notification_preferences')) {
            $missing[] = 'notification_preferences table';
        }

        foreach (['digest_state', 'digest_sent_at'] as $column) {
            if (! Schema::hasColumn('notifications', $column)) {
                $missing[] = 'notifications.'.$column;
            }
        }

        if (! $this->indexExists('notifications', 'notifications_digest_idx')) {
            $missing[] = 'notifications_digest_idx';
        }

        if ($missing !== []) {
            throw new RuntimeException(
                'The digest schema could not be reconciled; still missing: '.implode(', ', $missing).'.'
            );
        }
    }

    /**
     * Whether a named index exists, per driver.
     *
     * Inspection failure is not success: an engine this migration cannot read
     * must say so rather than let a partial schema be reported as complete.
     */
    private function indexExists(string $table, string $indexName): bool
    {
        $connection = DB::connection();
        $driver = $connection->getDriverName();

        return match ($driver) {
            'sqlite' => DB::table('sqlite_master')
                ->where('type', 'index')
                ->where('tbl_name', $table)
                ->where('name', $indexName)
                ->exists(),

            'mysql', 'mariadb' => DB::table('information_schema.STATISTICS')
                ->where('TABLE_SCHEMA', $connection->getDatabaseName())
                ->where('TABLE_NAME', $table)
                ->where('INDEX_NAME', $indexName)
                ->exists(),

            'pgsql' => DB::table('pg_indexes')
                ->where('tablename', $table)
                ->where('indexname', $indexName)
                ->exists(),

            default => throw new RuntimeException(
                "Cannot verify index state on driver [{$driver}]. Supported: sqlite, mysql, mariadb, pgsql."
            ),
        };
    }

    public function down(): void
    {
        /*
         * GUARDED BY INSPECTION, and the index goes before its columns.
         *
         * This dropped an index and two columns unconditionally, so rolling
         * back a database where `up()` had short-circuited threw
         * "no such index: notifications_digest_idx" and aborted the whole
         * rollback batch.
         */
        MigrationIndexes::dropIndexesOn('notifications', ['digest_state', 'digest_sent_at']);

        Schema::table('notifications', function (Blueprint $table): void {
            foreach (['digest_state', 'digest_sent_at'] as $column) {
                if (Schema::hasColumn('notifications', $column)) {
                    // MIGRATION-GUARD: intentional-drop — rolling back the
                    // digest feature removes the queue state it introduced. No
                    // message is lost: the notification rows themselves are
                    // untouched and remain readable in the notification centre;
                    // only the "was this batched" bookkeeping goes, and it is
                    // meaningless without the feature.
                    $table->dropColumn($column);
                }
            }
        });

        // MIGRATION-GUARD: intentional-drop — the preferences table holds only
        // the frequency choice, which has no meaning once the digest feature is
        // rolled back. Dropping it returns every user to immediate delivery,
        // which is the pre-migration behaviour and the safe direction: a user
        // whose preference is lost receives more messages, never fewer.
        Schema::dropIfExists('notification_preferences');
    }
};
