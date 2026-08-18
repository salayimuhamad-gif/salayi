<?php

declare(strict_types=1);

namespace App\Modules\Projects\Console;

use App\Modules\Projects\Support\CleanupJournal;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Throwable;

/**
 * Reverse the Wizard migrations, and only those (spec 26.1).
 *
 * `migrate:rollback --step=10` reverses the last ten migrations GLOBALLY, in
 * batch order. On a database where Leads or Operations migrations ran in the
 * same batch — which is the normal case, because a single deploy is one batch —
 * that command silently takes them with it. The documentation used to
 * recommend exactly that.
 *
 * This reverses a named list, newest first, and refuses to start if the list
 * does not match what is actually recorded as run.
 */
final class RollbackWizardSchema extends Command
{
    protected $signature = 'mulkihawler:rollback-wizard
                            {--dry-run : Show what would be reversed and stop}
                            {--force : Required outside dry-run, because this destroys data}';

    protected $description = 'Reverse only the Wizard-era migrations, newest first.';

    /** The authoritative count, so documentation cannot claim a different one. */
    public static function inventoryCount(): int
    {
        return count(self::MIGRATIONS);
    }

    /** @return list<string> */
    public static function inventory(): array
    {
        return self::MIGRATIONS;
    }

    /**
     * Newest first — the order they must be reversed in.
     *
     * Dependencies run downward: the lifecycle constraint must go before the
     * columns it checks, and the developer-association table before nothing
     * (it owns itself). Getting this order wrong leaves a CHECK referencing a
     * dropped column, which some engines accept and then fail on the next
     * write.
     *
     * @var list<string>
     */
    /** @var list<string> */
    public const MIGRATIONS = [
        '2026_07_25_002000_reconcile_cleanup_ledger_schema',
        '2026_07_25_001900_immutable_cleanup_incidents',
        '2026_07_25_001800_journal_entry_idempotency',
        '2026_07_25_001700_cleanup_job_identity',
        '2026_07_25_001600_media_handoff_linkage',
        // Marketplace joined the Wizard-era set; omitting it left cleanup and
        // moderation columns behind after a "complete" reversal.
        /*
         * v6 correction: these two share a timestamp, so Laravel orders
         * them lexically on APPLICATION — offer_media first, then
         * orphan_two_phase. A reversal must therefore undo
         * orphan_two_phase FIRST. The previous order reversed the pair in
         * application order, which is only harmless while the two touch
         * disjoint tables (offer_media, orphaned_files); any future column
         * dependency between them would have reversed backwards.
         */
        '2026_07_25_001500_orphan_two_phase_state',
        '2026_07_25_001500_offer_media_cleanup_and_moderation',
        '2026_07_25_001400_orphan_source_provenance',
        '2026_07_25_001300_purge_state_and_orphan_outbox',
        '2026_07_25_001200_developer_association_lifecycle',
        '2026_07_25_001100_create_company_developer_associations',
        '2026_07_25_001000_association_lifecycle_constraint',
        '2026_07_25_000900_creation_permission_evidence',
        '2026_07_25_000800_association_provenance',
        '2026_07_25_000700_media_cleanup_and_association_provenance',
        '2026_07_25_000600_membership_project_rights',
        '2026_07_25_000500_create_project_draft_media',
        '2026_07_25_000400_complete_project_wizard',
        '2026_07_25_000300_create_project_drafts',
    ];

    public function handle(): int
    {
        $recorded = DB::table('migrations')->pluck('batch', 'migration');

        $present = [];
        $absent = [];

        foreach (self::MIGRATIONS as $migration) {
            isset($recorded[$migration]) ? $present[] = $migration : $absent[] = $migration;
        }

        $this->line('Wizard migrations recorded as run: '.count($present).' of '.count(self::MIGRATIONS));

        foreach ($absent as $migration) {
            $this->line("  not run: {$migration}");
        }

        /*
         * Which unrelated migrations share these batches. Reported so the
         * operator can SEE what `--step` would have taken: this is the whole
         * reason the step approach is unsafe.
         */
        $batches = collect($present)->map(static fn (string $m) => $recorded[$m])->unique();

        $collateral = DB::table('migrations')
            ->whereIn('batch', $batches)
            ->whereNotIn('migration', self::MIGRATIONS)
            ->pluck('migration');

        if ($collateral->isNotEmpty()) {
            $this->warn(
                'These share a batch and would be reversed by --step, but are NOT touched here:'
            );

            foreach ($collateral as $migration) {
                $this->line("  preserved: {$migration}");
            }
        }

        if ($this->option('dry-run')) {
            return self::SUCCESS;
        }

        /*
         * REFUSE while cleanup work is outstanding.
         *
         * Migration 001300 owns `orphaned_files` and the purge state. Dropping
         * it with unresolved entries destroys the only record of files still
         * on disk, and a draft mid-purge reverts to editable with its media
         * half-removed. Neither is recoverable afterwards.
         */
        /*
         * PREFLIGHT ON THE SCHEMA THAT EXISTS, not on one migration happening
         * to be recorded. Gating these checks behind 001300 meant a database
         * where that row was missing — a manual repair, a partial rollback —
         * skipped every safety check and dropped cleanup columns while retry
         * work was still outstanding.
         */
        $blockers = [];

        foreach ([
            'project_media' => 'project media',
            'project_draft_media' => 'draft media',
            'offer_media' => 'offer media',
        ] as $table => $label) {
            if (! Schema::hasTable($table) || ! Schema::hasColumn($table, 'cleanup_pending')) {
                continue;
            }

            $pending = DB::table($table)->where('cleanup_pending', true)->count();

            if ($pending > 0) {
                $blockers[] = "{$pending} {$label} row(s) awaiting cleanup";
            }
        }

        if (Schema::hasTable('orphaned_files')) {
            // Unresolved OR partially finalised: a file removed whose source
            // row has not yet been deleted is still outstanding work.
            $unresolved = DB::table('orphaned_files')
                ->where(function ($query): void {
                    $query->whereNull('resolved_at')
                        ->orWhereNull('source_finalised_at');
                })
                ->count();

            if ($unresolved > 0) {
                $blockers[] = "{$unresolved} unresolved or partially finalised orphaned file(s)";
            }
        }

        if (Schema::hasTable('project_drafts') && Schema::hasColumn('project_drafts', 'purge_status')) {
            $purging = DB::table('project_drafts')->whereNotNull('purge_status')->count();

            if ($purging > 0) {
                $blockers[] = "{$purging} draft(s) mid-purge";
            }
        }

        /*
         * JOURNAL WORK BLOCKS TOO. Reversing the outbox or its ledger while
         * entries are still on disk destroys the only record of files nothing
         * references — and the journal exists precisely for the case where the
         * database could not be written, so it is the last place to look, not
         * the first to discard.
         */
        $journalPath = CleanupJournal::path();

        if (is_file($journalPath) && filesize($journalPath) > 0) {
            $blockers[] = 'the active cleanup journal is not empty';
        }

        $processing = CleanupJournal::pendingProcessingFiles();

        if ($processing !== []) {
            $blockers[] = count($processing).' rotated journal file(s) awaiting replay';
        }

        $claimedFiles = glob(storage_path('app/cleanup-journal.*.claimed.jsonl')) ?: [];

        if ($claimedFiles !== []) {
            $blockers[] = count($claimedFiles).' claimed journal file(s) still being processed';
        }

        $deadLetter = CleanupJournal::deadLetterPath();

        if (is_file($deadLetter) && filesize($deadLetter) > 0) {
            // Lines nobody has triaged: dropping the schema loses them.
            $blockers[] = 'the dead-letter journal holds unreviewed entries';
        }

        if ($blockers !== []) {
            $this->error('Refusing to reverse cleanup migrations: '.implode(', ', $blockers).'.');
            $this->line(
                'Drain them first: mulkihawler:replay-cleanup-journal, '
                .'mulkihawler:retry-media-cleanup-all, mulkihawler:sweep-orphaned-files'
            );

            return self::FAILURE;
        }

        if (! $this->option('force')) {
            $this->error('Refusing to reverse migrations without --force. Run with --dry-run first.');

            return self::FAILURE;
        }

        // One at a time, newest first, so a failure stops with a known state
        // rather than a half-reversed schema.
        foreach ($present as $migration) {
            $this->line("reversing {$migration}");

            /*
             * THE MIGRATION'S OWN down(), executed directly.
             *
             * `migrate:rollback --path` only reverses migrations in the LATEST
             * batch. A Wizard migration recorded in an older batch — the normal
             * case on any system that has deployed since — was silently a
             * no-op, and the command could only detect that after the fact.
             *
             * Loading the file and calling down() works regardless of batch.
             * The migrations row is removed only after the schema reversal is
             * verified below, so an interruption leaves a state that a retry
             * can resume from rather than a row claiming work that never
             * happened.
             */
            try {
                $instance = require $this->absolutePathFor($migration);

                if (! is_object($instance) || ! method_exists($instance, 'down')) {
                    $this->error("{$migration} does not return a migration object.");

                    return self::FAILURE;
                }

                $instance->down();
            } catch (Throwable $e) {
                $this->error("Failed reversing {$migration}: ".$e->getMessage());

                return self::FAILURE;
            }

            /*
             * PROVE IT.
             *
             * `migrate:rollback` exits 0 when it does nothing at all — a wrong
             * --path, a name that does not match, an already-reversed
             * migration. Reporting success from the exit code alone would tell
             * an operator the schema was reversed when it was untouched.
             */
            /*
             * VERIFY, THEN FORGET.
             *
             * The schema check comes first: down() can silently no-op on an
             * unsupported driver, and removing the migrations row before
             * confirming would tell the operator it reversed while the objects
             * were still there.
             */
            $residue = $this->residualSchemaFor($migration);

            if ($residue !== null) {
                $this->error("{$migration} left {$residue} behind. The schema is partially reversed.");

                return self::FAILURE;
            }

            // Only now is it safe to forget the migration. A crash before this
            // line leaves the row present and the reversal idempotent — down()
            // is written with hasTable/hasColumn guards, so a retry is safe.
            DB::table('migrations')->where('migration', $migration)->delete();

            $this->line('  reversed and verified');
        }

        $this->info('Wizard migrations reversed. Unrelated modules untouched.');

        return self::SUCCESS;
    }

    /**
     * What must be GONE after a given migration is reversed.
     *
     * Checking the migrations row alone proves Laravel forgot the migration,
     * not that `down()` actually did anything — a down() that silently no-ops
     * on an unsupported driver leaves the schema in place and the row removed,
     * which is the worst of both.
     *
     * Returns a description of what survived, or null when the reversal is
     * genuinely complete.
     */
    private function residualSchemaFor(string $migration): ?string
    {
        /*
         * EVERY migration, including the constraint-only ones.
         *
         * "Nothing structural to inspect" was wrong: a CHECK or a trigger is a
         * schema object, and a down() that silently no-ops on an unsupported
         * driver leaves it in place while the migrations row disappears —
         * which is the worst combination, because the operator is told it
         * reversed.
         */
        $expectations = [
            /*
             * NO RESIDUE OF ITS OWN, so a successful no-op returns null.
             *
             * This entry used to return a description string. The loop treats
             * any non-null value as leftover schema and stops — so the command
             * halted here and never reached 001900, 001800 or 001700. The
             * whole rollback chain was unreachable behind a comment.
             *
             * 002000 creates no objects it alone owns: it tightens constraints
             * on columns and a table that 001900 creates and drops. Its
             * reversal is genuinely a no-op, and the migration after it in the
             * chain is what removes the objects.
             */
            '2026_07_25_002000_reconcile_cleanup_ledger_schema' => fn (): ?string => null,
            '2026_07_25_001900_immutable_cleanup_incidents' => fn (): ?string => match (true) {
                Schema::hasTable('cleanup_journal_imports') => 'cleanup_journal_imports',
                Schema::hasColumn('orphaned_files', 'active_key') => 'orphaned_files.active_key',
                Schema::hasColumn('orphaned_files', 'incident_uuid') => 'orphaned_files.incident_uuid',
                default => null,
            },
            '2026_07_25_001800_journal_entry_idempotency' => fn (): ?string => Schema::hasColumn('orphaned_files', 'journal_entry_id')
                    ? 'orphaned_files.journal_entry_id'
                    : null,
            '2026_07_25_001700_cleanup_job_identity' => fn (): ?string => match (true) {
                Schema::hasColumn('orphaned_files', 'job_key') => 'orphaned_files.job_key',
                // INDEX state, not only column absence: a dropped column with
                // a surviving index would report clean and then fail on the
                // next insert.
                $this->indexExists('orphaned_files', 'orphaned_files_job_key_unique') => 'job_key unique index',
                /*
                 * The PREVIOUS contract must be back. Proving only that the new
                 * objects are gone would pass on a schema enforcing nothing.
                 */
                ! $this->indexExists('orphaned_files', 'orphaned_files_disk_path_unique') => 'missing restored disk/path unique index',
                default => null,
            },
            '2026_07_25_001600_media_handoff_linkage' => fn (): ?string => match (true) {
                Schema::hasColumn('project_media', 'cleanup_outbox_id') => 'project_media.cleanup_outbox_id',
                Schema::hasColumn('project_draft_media', 'cleanup_outbox_id') => 'project_draft_media.cleanup_outbox_id',
                Schema::hasColumn('offer_media', 'cleanup_outbox_id') => 'offer_media.cleanup_outbox_id',
                default => null,
            },
            '2026_07_25_001500_offer_media_cleanup_and_moderation' => fn (): ?string => match (true) {
                Schema::hasColumn('offer_media', 'cleanup_pending') => 'offer_media.cleanup_pending',
                Schema::hasColumn('offer_media', 'cleanup_attempts') => 'offer_media.cleanup_attempts',
                Schema::hasColumn('offer_media', 'cleanup_last_error') => 'offer_media.cleanup_last_error',
                Schema::hasColumn('offer_media', 'moderation_reason') => 'offer_media.moderation_reason',
                Schema::hasColumn('offer_media', 'moderated_at') => 'offer_media.moderated_at',
                default => null,
            },
            '2026_07_25_001500_orphan_two_phase_state' => fn (): ?string => match (true) {
                Schema::hasColumn('orphaned_files', 'file_resolved_at') => 'file_resolved_at',
                Schema::hasColumn('orphaned_files', 'source_finalised_at') => 'source_finalised_at',
                Schema::hasColumn('orphaned_files', 'handed_off_at') => 'handed_off_at',
                default => null,
            },
            '2026_07_25_001400_orphan_source_provenance' => fn (): ?string => match (true) {
                Schema::hasColumn('orphaned_files', 'source_type') => 'source_type',
                Schema::hasColumn('orphaned_files', 'source_id') => 'source_id',
                default => null,
            },
            '2026_07_25_001300_purge_state_and_orphan_outbox' => fn (): ?string => match (true) {
                Schema::hasTable('orphaned_files') => 'orphaned_files',
                Schema::hasColumn('project_drafts', 'purge_status') => 'purge_status',
                Schema::hasColumn('project_drafts', 'purging_at') => 'purging_at',
                default => null,
            },
            '2026_07_25_001200_developer_association_lifecycle' => fn (): ?string => match (true) {
                Schema::hasColumn('company_developer_associations', 'rejected_at') => 'rejected_at',
                Schema::hasColumn('company_developer_associations', 'revoked_by') => 'revoked_by',
                $this->constraintExists('company_developer_associations_lifecycle_check') => 'lifecycle constraint',
                $this->triggerExists('company_developer_associations_lifecycle_check_insert') => 'insert trigger',
                $this->triggerExists('company_developer_associations_lifecycle_check_update') => 'update trigger',
                default => null,
            },
            '2026_07_25_001100_create_company_developer_associations' => fn (): ?string => Schema::hasTable('company_developer_associations') ? 'company_developer_associations' : null,
            '2026_07_25_001000_association_lifecycle_constraint' => fn (): ?string => match (true) {
                $this->constraintExists('company_project_associations_lifecycle_check') => 'lifecycle constraint',
                $this->triggerExists('company_project_associations_lifecycle_check_insert') => 'insert trigger',
                $this->triggerExists('company_project_associations_lifecycle_check_update') => 'update trigger',
                default => null,
            },
            '2026_07_25_000900_creation_permission_evidence' => fn (): ?string => match (true) {
                Schema::hasColumn('company_project_associations', 'created_by_company_staff_id') => 'created_by_company_staff_id',
                Schema::hasColumn('company_project_associations', 'creator_membership_role') => 'creator_membership_role',
                Schema::hasColumn('company_project_associations', 'creator_membership_company_id') => 'creator_membership_company_id',
                Schema::hasColumn('company_project_associations', 'creator_manage_projects_confirmed_at') => 'creator_manage_projects_confirmed_at',
                default => null,
            },
            '2026_07_25_000800_association_provenance' => fn (): ?string => match (true) {
                Schema::hasColumn('company_project_associations', 'created_via_project_draft_id') => 'created_via_project_draft_id',
                Schema::hasColumn('company_project_associations', 'rejected_at') => 'rejected_at',
                Schema::hasColumn('company_project_associations', 'revoked_at') => 'revoked_at',
                default => null,
            },
            '2026_07_25_000700_media_cleanup_and_association_provenance' => fn (): ?string => match (true) {
                Schema::hasColumn('project_media', 'cleanup_pending') => 'project_media.cleanup_pending',
                Schema::hasColumn('company_project_associations', 'management_status') => 'management_status',
                Schema::hasColumn('company_project_associations', 'created_by') => 'created_by',
                default => null,
            },
            '2026_07_25_000600_membership_project_rights' => fn (): ?string => match (true) {
                Schema::hasColumn('company_staff', 'may_manage_projects') => 'may_manage_projects',
                Schema::hasColumn('project_draft_media', 'cleanup_pending') => 'draft cleanup columns',
                default => null,
            },
            '2026_07_25_000500_create_project_draft_media' => fn (): ?string => Schema::hasTable('project_draft_media') ? 'project_draft_media' : null,
            '2026_07_25_000400_complete_project_wizard' => fn (): ?string => match (true) {
                Schema::hasTable('project_prices') => 'project_prices',
                Schema::hasColumn('projects', 'area_unresolved') => 'projects.area_unresolved',
                Schema::hasColumn('project_drafts', 'submitted_at') => 'project_drafts.submitted_at',
                default => null,
            },
            '2026_07_25_000300_create_project_drafts' => fn (): ?string => Schema::hasTable('project_drafts') ? 'project_drafts' : null,
        ];

        $check = $expectations[$migration] ?? null;

        if ($check === null) {
            // Unknown migration: refuse to claim it verified.
            return 'no verification rule (refusing to report success)';
        }

        return $check();
    }

    /** Whether a named index still exists, per driver. */
    private function indexExists(string $table, string $index): bool
    {
        $driver = DB::connection()->getDriverName();

        try {
            return match ($driver) {
                'sqlite' => collect(DB::select("PRAGMA index_list('{$table}')"))
                    ->contains(fn ($row): bool => $row->name === $index),
                'mysql', 'mariadb' => collect(DB::select("SHOW INDEX FROM `{$table}`"))
                    ->contains(fn ($row): bool => $row->Key_name === $index),
                'pgsql' => DB::table('pg_indexes')->where('indexname', $index)->exists(),
                default => false,
            };
        } catch (Throwable) {
            // An uninspectable or absent table cannot hold a surviving index.
            return false;
        }
    }

    /** Whether a named CHECK constraint still exists, per driver. */
    private function constraintExists(string $name): bool
    {
        $driver = DB::connection()->getDriverName();

        try {
            return match ($driver) {
                'mysql' => DB::table('information_schema.TABLE_CONSTRAINTS')
                    ->where('CONSTRAINT_SCHEMA', DB::connection()->getDatabaseName())
                    ->where('CONSTRAINT_NAME', $name)
                    ->exists(),
                'pgsql' => DB::table('pg_constraint')->where('conname', $name)->exists(),
                // SQLite expresses this rule as triggers; see triggerExists().
                default => false,
            };
        } catch (Throwable) {
            // An engine that cannot be inspected must not be reported clean.
            return true;
        }
    }

    /** Whether a named SQLite trigger still exists. */
    private function triggerExists(string $name): bool
    {
        if (DB::connection()->getDriverName() !== 'sqlite') {
            return false;
        }

        try {
            return DB::table('sqlite_master')
                ->where('type', 'trigger')
                ->where('name', $name)
                ->exists();
        } catch (Throwable) {
            return true;
        }
    }

    /** The migration file on disk, for direct execution. */
    private function absolutePathFor(string $migration): string
    {
        $path = base_path($this->pathFor($migration));

        if (! is_file($path)) {
            throw new RuntimeException("Migration file not found: {$path}");
        }

        return $path;
    }

    private function pathFor(string $migration): string
    {
        // 001100 and 001200 live in Companies; everything else in Projects.
        // Explicit module mapping: three modules now own Wizard-era
        // migrations, and inferring the directory from the timestamp broke the
        // moment two modules shared one.
        if (str_contains($migration, 'offer_media')) {
            return 'app/Modules/Marketplace/Database/Migrations/'.$migration.'.php';
        }

        if (str_starts_with($migration, '2026_07_25_0011')
            || str_starts_with($migration, '2026_07_25_0012')) {
            return 'app/Modules/Companies/Database/Migrations/'.$migration.'.php';
        }

        return 'app/Modules/Projects/Database/Migrations/'.$migration.'.php';
    }
}
