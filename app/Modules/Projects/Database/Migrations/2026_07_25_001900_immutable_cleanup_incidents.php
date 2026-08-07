<?php

declare(strict_types=1);

use App\Modules\Projects\Support\CleanupJournal;
use App\Modules\Projects\Support\SchemaContract;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Immutable cleanup incidents, and a real import ledger (spec 26.1).
 *
 * TWO DEFECTS, both from making one row do two jobs.
 *
 * 1. IMMUTABILITY. `job_key` was unique across all rows, so a later incident
 *    at the same path had nowhere to go but the existing row — which was
 *    therefore reopened and reset. That destroys history: the resolved job's
 *    attempt count, reason and timestamps described a real past incident, and
 *    overwriting them means nobody can ever answer "what happened to that
 *    file in March".
 *
 *    `active_key` replaces it. It carries the identity ONLY while the job is
 *    outstanding, and is set to null on resolution — at which point the row
 *    becomes permanent evidence and the key is free for the next incident.
 *    A nullable unique index permits many nulls on every supported engine,
 *    which is exactly the semantics wanted.
 *
 * 2. IMPORT IDEMPOTENCY. A single nullable `journal_entry_id` on the job
 *    cannot represent several journal entries importing into one job, and was
 *    overwritten whenever a second entry arrived. `cleanup_journal_imports` is
 *    the ledger: entry ids are unique there, many may point at one job, and
 *    the uniqueness is enforced by the database rather than by a pre-check
 *    that races.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('orphaned_files')) {
            return;
        }

        Schema::table('orphaned_files', function (Blueprint $table): void {
            if (! Schema::hasColumn('orphaned_files', 'active_key')) {
                // Null once resolved: the row becomes immutable evidence and
                // the identity is released for the next incident.
                $table->string('active_key', 255)->nullable();
            }

            if (! Schema::hasColumn('orphaned_files', 'incident_uuid')) {
                // Stable for the life of one incident, and never reused.
                $table->uuid('incident_uuid')->nullable()->index();
            }
        });

        /*
         * Backfill ONLY where null. Rewriting an existing `incident_uuid` on a
         * re-run would give a row a new identity — and the uuid is the one
         * thing about an incident that must never change, since anything
         * recorded elsewhere referring to it would silently stop matching.
         */
        foreach (
            DB::table('orphaned_files')
                ->select('id', 'job_key', 'resolved_at', 'active_key', 'incident_uuid')
                ->cursor() as $row
        ) {
            $changes = [];

            if ($row->incident_uuid === null) {
                $changes['incident_uuid'] = (string) Str::uuid();
            }

            if ($row->active_key === null && $row->resolved_at === null) {
                // Resolved rows keep a null key deliberately: they have
                // released their identity and hold history only.
                $changes['active_key'] = $row->job_key;
            }

            if ($changes !== []) {
                DB::table('orphaned_files')->where('id', $row->id)->update($changes);
            }
        }

        if (! SchemaContract::indexContractHolds('orphaned_files', 'orphaned_files_active_key_unique', ['active_key'], true)
            && Schema::hasColumn('orphaned_files', 'active_key')) {
            Schema::table('orphaned_files', function (Blueprint $table): void {
                $table->unique('active_key', 'orphaned_files_active_key_unique');
            });
        }

        /*
         * The incident uuid is unique by contract, so enforce it. Without the
         * constraint the guarantee lived only in the code that generates it,
         * and a bad backfill or manual insert could quietly duplicate one.
         */
        /*
         * NOT NULL after the backfill. The column is added nullable so
         * existing rows can be filled, but leaving it nullable afterwards
         * would let a future insert create an incident with no identity —
         * which is the one thing about an incident that must always exist.
         */
        $missingUuid = DB::table('orphaned_files')->whereNull('incident_uuid')->count();

        if ($missingUuid > 0) {
            throw new RuntimeException(
                "Backfill incomplete: {$missingUuid} row(s) still have no incident_uuid. "
                .'Refusing to continue rather than enforcing a constraint the data violates.'
            );
        }

        if (Schema::hasColumn('orphaned_files', 'incident_uuid')) {
            Schema::table('orphaned_files', function (Blueprint $table): void {
                // Guarded like every other change here, so a partially applied
                // run resumes rather than failing on work it already did.
                $table->uuid('incident_uuid')->nullable(false)->change();
            });
        }

        if (! SchemaContract::indexContractHolds('orphaned_files', 'orphaned_files_incident_uuid_unique', ['incident_uuid'], true)
            && Schema::hasColumn('orphaned_files', 'incident_uuid')) {
            Schema::table('orphaned_files', function (Blueprint $table): void {
                $table->unique('incident_uuid', 'orphaned_files_incident_uuid_unique');
            });
        }

        if (! SchemaContract::indexContractHolds('orphaned_files', 'orphaned_files_active_key_unique', ['active_key'], true)) {
            throw new RuntimeException(
                'The active_key unique index was not created. Refusing to continue: '
                .'duplicate outstanding cleanup jobs would be possible.'
            );
        }

        /*
         * The old job_key unique goes: it is what forced a later incident to
         * reuse a resolved row. The COLUMN stays as historical provenance.
         */
        if (SchemaContract::indexContractHolds('orphaned_files', 'orphaned_files_job_key_unique', ['job_key'], true)
            && Schema::hasColumn('orphaned_files', 'active_key')) {
            Schema::table('orphaned_files', function (Blueprint $table): void {
                // MIGRATION-GUARD: intentional-drop — this index is the reason
                // resolved incidents were being overwritten. Guarded on the
                // replacement column so a partial run resumes safely.
                $table->dropUnique('orphaned_files_job_key_unique');
            });
        }

        if (SchemaContract::indexContractHolds('orphaned_files', 'orphaned_files_job_key_unique', ['job_key'], true)) {
            throw new RuntimeException(
                'The job_key unique index could not be removed. Refusing to continue: '
                .'later incidents would still overwrite resolved history.'
            );
        }

        if (Schema::hasTable('cleanup_journal_imports')) {
            return;
        }

        Schema::create('cleanup_journal_imports', function (Blueprint $table): void {
            $table->id();

            /*
             * THE idempotency key, enforced by the database. The previous
             * design read `alreadyImported()` and then recorded — a
             * time-of-check/time-of-use gap two workers could both pass.
             */
            $table->string('entry_id', 64)->unique();

            /*
             * Which job this entry fed. Several entries may name one job.
             *
             * RESTRICT, not cascade: the ledger is the evidence that a journal
             * entry was consumed, and deleting a cleanup job must not erase
             * the proof that its work already happened — that is precisely how
             * a replay would import the same entry a second time.
             */
            $table->unsignedBigInteger('orphaned_file_id')->index();

            $table->foreign('orphaned_file_id')
                ->references('id')
                ->on('orphaned_files')
                ->restrictOnDelete();

            /*
             * NOT NULL. A nullable hash meant an entry could be recorded with
             * no integrity evidence at all, and the conflict check skipped it
             * — so exactly the rows that could not be verified were the ones
             * treated as trustworthy.
             */
            $table->string('payload_hash', 64);

            $table->timestamp('imported_at');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('orphaned_files')) {
            return;
        }

        /*
         * EVERY PREFLIGHT BEFORE ANY DESTRUCTION.
         *
         * The previous order dropped `cleanup_journal_imports` and only then
         * checked whether the old unique could be restored — so a database
         * with several historical incidents per key lost its entire import
         * ledger and THEN threw. The failure left the schema in a state
         * neither migration describes, and the idempotency evidence was
         * unrecoverable.
         */
        $duplicates = DB::table('orphaned_files')
            ->select('job_key')
            ->groupBy('job_key')
            ->havingRaw('COUNT(*) > 1')
            ->get()
            ->count();

        if ($duplicates > 0) {
            throw new RuntimeException(
                "Refusing to reverse: {$duplicates} job key(s) carry several historical "
                .'incidents, which the previous unique index forbids. Archive them first. '
                .'No schema change has been made.'
            );
        }

        // Unreplayed journal work would lose its idempotency record.
        if (Schema::hasTable('cleanup_journal_imports')) {
            $journalPath = CleanupJournal::path();

            $pending = (is_file($journalPath) && filesize($journalPath) > 0)
                || CleanupJournal::pendingProcessingFiles() !== []
                || (glob(storage_path('app/cleanup-journal.*.claimed.jsonl')) ?: []) !== [];

            if ($pending) {
                throw new RuntimeException(
                    'Refusing to reverse: emergency journal work is outstanding and its '
                    .'import ledger would be destroyed. Run mulkihawler:replay-cleanup-journal '
                    .'first. No schema change has been made.'
                );
            }
        }

        /*
         * RESTORE FIRST, DESTROY SECOND.
         *
         * The previous order dropped `active_key` and its index and only then
         * recreated the `job_key` unique. If that final DDL failed — a MySQL
         * lock timeout, a permissions problem — the table was left with
         * NEITHER identity constraint, and nothing prevented duplicate
         * outstanding jobs from that moment on.
         *
         * The two constraints can coexist: the preflight above proved
         * `job_key` has no duplicates, so adding it while `active_key` still
         * exists is safe, and a failure here leaves the schema exactly as it
         * was.
         */
        if (! SchemaContract::indexContractHolds('orphaned_files', 'orphaned_files_job_key_unique', ['job_key'], true)
            && Schema::hasColumn('orphaned_files', 'job_key')) {
            Schema::table('orphaned_files', function (Blueprint $table): void {
                $table->unique('job_key', 'orphaned_files_job_key_unique');
            });
        }

        if (! SchemaContract::indexContractHolds('orphaned_files', 'orphaned_files_job_key_unique', ['job_key'], true)) {
            throw new RuntimeException(
                'The previous job_key unique index could not be restored. '
                .'No destructive change has been made.'
            );
        }

        // Preflight complete and the old contract is back. Destruction may begin.
        if (SchemaContract::indexContractHolds('orphaned_files', 'orphaned_files_active_key_unique', ['active_key'], true)
            && Schema::hasColumn('orphaned_files', 'active_key')) {
            Schema::table('orphaned_files', function (Blueprint $table): void {
                // MIGRATION-GUARD: intentional-drop — reversing this
                // migration's own index.
                $table->dropUnique('orphaned_files_active_key_unique');
            });
        }

        if (SchemaContract::indexContractHolds('orphaned_files', 'orphaned_files_incident_uuid_unique', ['incident_uuid'], true)
            && Schema::hasColumn('orphaned_files', 'incident_uuid')) {
            Schema::table('orphaned_files', function (Blueprint $table): void {
                // MIGRATION-GUARD: intentional-drop — reversing this
                // migration's own index.
                $table->dropUnique('orphaned_files_incident_uuid_unique');
            });
        }

        /*
         * The PLAIN index goes first, explicitly — up() created it with
         * `->index()` when the column was added. MariaDB would drop it with
         * the column; SQLite refuses to drop a column an index still covers
         * ("error in index orphaned_files_incident_uuid_index after drop
         * column"), which is exactly how the first real rollback run failed.
         */
        if (SchemaContract::indexContractHolds('orphaned_files', 'orphaned_files_incident_uuid_index', ['incident_uuid'], false)) {
            Schema::table('orphaned_files', function (Blueprint $table): void {
                // MIGRATION-GUARD: intentional-drop — reversing this
                // migration's own index.
                $table->dropIndex('orphaned_files_incident_uuid_index');
            });
        }

        Schema::table('orphaned_files', function (Blueprint $table): void {
            foreach (['active_key', 'incident_uuid'] as $column) {
                if (Schema::hasColumn('orphaned_files', $column)) {
                    // MIGRATION-GUARD: intentional-drop — reversing this
                    // migration's own additive columns.
                    $table->dropColumn($column);
                }
            }
        });

        // The job_key unique was restored before any destruction; confirm it
        // survived the drops above.
        if (! SchemaContract::indexContractHolds('orphaned_files', 'orphaned_files_job_key_unique', ['job_key'], true)) {
            throw new RuntimeException(
                'The job_key unique index disappeared during reversal. '
                .'The table now enforces no identity contract.'
            );
        }

        /*
         * THE LEDGER GOES LAST, and only once every other reversal has
         * succeeded.
         *
         * Dropping it first meant a later failure — a lock timeout on the
         * index drop, a permissions problem on the column drop — left the
         * schema half-reversed AND the import evidence already destroyed. A
         * subsequent replay would then re-import entries it had already
         * consumed, inflating attempt counts for work nobody redid.
         *
         * Reaching this line means every other statement committed, so the
         * ledger is the only thing left and losing it is the intended cost of
         * the reversal rather than collateral damage from a failure.
         */
        if (Schema::hasTable('cleanup_journal_imports')) {
            // MIGRATION-GUARD: intentional-drop — reversing this migration's
            // own table, last, after everything else has succeeded.
            Schema::dropIfExists('cleanup_journal_imports');
        }
    }
};
