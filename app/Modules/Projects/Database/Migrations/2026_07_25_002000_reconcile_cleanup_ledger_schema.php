<?php

declare(strict_types=1);

use App\Modules\Projects\Support\SchemaContract;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Reconcile the cleanup ledger on databases that already ran 001900.
 *
 * WHY A NEW FILE RATHER THAN AN EDIT.
 *
 * 001900 originally created `payload_hash` nullable. That file was later
 * corrected — but Laravel records a migration by name and never reruns it, so
 * every database that had already migrated kept the nullable column and the
 * edit reached only fresh installations. Worse, 001900 returns as soon as
 * `cleanup_journal_imports` exists, so it inspects nothing and repairs
 * nothing on exactly the deployments that need it.
 *
 * This migration therefore reads the DEPLOYED schema rather than assuming what
 * an earlier one did, and brings any state up to the intended contract.
 *
 * IT DOES NOT FABRICATE DATA. A null `payload_hash` is a row whose integrity
 * evidence was never recorded; inventing one would manufacture proof that the
 * bytes matched, which is the opposite of what the column is for. Those rows
 * need a person, so the migration stops and says so.
 *
 * Every step is guarded by inspection, so a partially upgraded database
 * converges on a rerun instead of failing on work it already did.
 */
return new class extends Migration
{
    public function up(): void
    {
        /*
         * FAIL CLOSED. Returning here recorded this reconciliation as complete
         * on a database where the table it reconciles does not exist — and
         * every later run then sees a finished migration and moves on, so the
         * gap survives forever.
         *
         * `orphaned_files` is created by 001300 and must exist before this
         * runs. Its absence means the chain is broken, not that there is
         * nothing to do.
         */
        if (! Schema::hasTable('orphaned_files')) {
            throw new RuntimeException(
                'orphaned_files does not exist. This reconciliation depends on it and cannot '
                .'be recorded as complete without it — run the earlier Wizard migrations first.'
            );
        }

        $this->reconcileIncidentUuid();

        /*
         * REMNANTS ARE INSPECTED BEFORE ANYTHING IS CREATED OR ACCEPTED.
         *
         * Three executed reproductions all came from deciding "no ledger
         * exists" or "the ledger is fine" without looking at `_old` and
         * `_rebuild` first:
         *
         *   A. `_old` held the real rows and an empty live table had a valid
         *      FK — so the migration returned success and left the data
         *      stranded, with the final unique index still owned by `_old`.
         *   B. Only `_rebuild` existed — so a NEW EMPTY live ledger was
         *      created over the top of the copied rows.
         *   C. The live table was correct but missing its indexes — so the
         *      migration returned as soon as the FK held.
         *
         * Recovery therefore runs first and always, and only then is the
         * ledger reconciled.
         */
        $this->recoverLedgerRemnants();

        if (! Schema::hasTable('cleanup_journal_imports')) {
            $this->createLedger();
        }

        if (! Schema::hasTable('cleanup_journal_imports')) {
            throw new RuntimeException(
                'cleanup_journal_imports is still absent after creation was attempted. '
                .'Refusing to record this migration as complete.'
            );
        }

        $this->reconcileLedger();
    }

    /**
     * Restore a single authoritative live ledger from any interrupted state.
     *
     * ROW COUNTS ARE NOT EVIDENCE OF EQUIVALENCE.
     *
     * The previous version compared counts, which loses data in two ways that
     * were both reproduced:
     *
     *   A. A live table and an `_old` table each holding ONE row — but
     *      different rows — compared equal, so the live row was dropped.
     *   B. A live table with two rows and a `_rebuild` with one DISTINCT row
     *      satisfied `live >= rebuild`, so the distinct row was dropped.
     *
     * Every removal is now justified by row-by-row comparison of the canonical
     * evidence: id, entry_id, orphaned_file_id, payload_hash. A remnant is
     * dropped only when it is a proven SUBSET of what survives. Divergence
     * fails closed, because choosing between two sets of real evidence is not
     * a decision a migration can make.
     */
    private function recoverLedgerRemnants(): void
    {
        $live = 'cleanup_journal_imports';
        $old = 'cleanup_journal_imports_old';
        $rebuild = 'cleanup_journal_imports_rebuild';

        /*
         * `_old` first. It is the pre-rebuild original, so where it and the
         * live table disagree it is the one with the longer history.
         */
        if (Schema::hasTable($old)) {
            $this->reconcileRemnant($old, $live);
        }

        /*
         * State is re-read rather than carried forward: the block above may
         * have renamed, dropped or created tables, and continuing with stale
         * counts is how the original defect survived a second look.
         */
        if (Schema::hasTable($rebuild)) {
            $this->reconcileRemnant($rebuild, $live);
        }
    }

    /**
     * Fold one remnant table into the live ledger, or fail closed.
     *
     * @param  string  $remnant  the interrupted table
     * @param  string  $live  the live ledger, which may not exist
     */
    private function reconcileRemnant(string $remnant, string $live): void
    {
        /*
         * BOTH TABLES ARE READ FIRST, so a duplicate in EITHER stops the
         * reconciliation before anything is dropped or renamed. Reading the
         * remnant alone would let a duplicate in the live table survive
         * undetected until after the remnant had already gone.
         */
        $remnantRows = $this->canonicalRows($remnant);
        $liveRows = $this->canonicalRows($live);

        if ($remnantRows === []) {
            // MIGRATION-GUARD: intentional-drop — an EMPTY remnant, proven so
            // by reading its rows rather than by counting them.
            Schema::drop($remnant);

            return;
        }

        // No live table at all: the remnant simply becomes it.
        if (! Schema::hasTable($live)) {
            Schema::rename($remnant, $live);

            $this->assertFinalIndexNames($live, $remnant);

            return;
        }

        // The remnant is entirely contained in the live table: safe to remove.
        if ($this->isSubset($remnantRows, $liveRows)) {
            // MIGRATION-GUARD: intentional-drop — every row proven present in
            // the live ledger by exact content comparison immediately above.
            Schema::drop($remnant);

            return;
        }

        // The live table is entirely contained in the remnant: the remnant is
        // the fuller record, so it takes over.
        if ($this->isSubset($liveRows, $remnantRows)) {
            // MIGRATION-GUARD: intentional-drop — every live row proven
            // present in the remnant, which replaces it below.
            Schema::drop($live);

            Schema::rename($remnant, $live);

            $this->assertFinalIndexNames($live, $remnant);

            return;
        }

        /*
         * DIVERGENT. Each table holds evidence the other lacks. Picking one
         * destroys real imports, and merging could resurrect an entry the
         * ledger deliberately consumed — so this stops and says exactly what
         * it found.
         */
        $onlyRemnant = count($this->difference($remnantRows, $liveRows));
        $onlyLive = count($this->difference($liveRows, $remnantRows));

        throw new RuntimeException(
            "Refusing to reconcile {$remnant} into {$live}: {$onlyRemnant} row(s) exist only in "
            ."{$remnant} and {$onlyLive} only in {$live}. Neither is a subset of the other, so "
            .'neither can be discarded safely. Merge them manually — matching on entry_id — and '
            .'rerun. No table has been modified.'
        );
    }

    /**
     * The exact persisted evidence of one ledger table, keyed by entry id.
     *
     * NOTHING IS CAST. The previous version coerced on the way in —
     * `(string) $row->payload_hash` made NULL and '' identical, `(int)` made
     * NULL and 0 identical — and excluded the timestamps. Three reproductions
     * showed a remnant holding genuinely different rows dropped as a "subset".
     *
     * Timestamps are included: a rebuild copies them verbatim, so identical
     * rows still match, while a row differing only in `imported_at` is a
     * different persisted fact and must block the drop.
     *
     * @return array<string, array<string, mixed>>
     */
    private function canonicalRows(string $table): array
    {
        if (! Schema::hasTable($table)) {
            return [];
        }

        /*
         * DUPLICATE entry_id VALUES MAKE THIS MAP LOSSY, so the physical count
         * is checked against the distinct count below.
         */
        $physical = DB::table($table)->count();

        $rows = [];

        foreach (
            DB::table($table)
                ->select(
                    'id', 'entry_id', 'orphaned_file_id', 'payload_hash',
                    'imported_at', 'created_at', 'updated_at',
                )
                ->orderBy('id')
                ->cursor() as $row
        ) {
            $rows[(string) $row->entry_id] = [
                'id' => $row->id,
                'entry_id' => $row->entry_id,
                // Exactly as stored: NULL stays NULL, 0 stays 0.
                'orphaned_file_id' => $row->orphaned_file_id,
                'payload_hash' => $row->payload_hash,
                'imported_at' => $row->imported_at,
                'created_at' => $row->created_at,
                'updated_at' => $row->updated_at,
            ];
        }

        if (count($rows) !== $physical) {
            $duplicates = DB::table($table)
                ->select('entry_id')
                ->groupBy('entry_id')
                ->havingRaw('COUNT(*) > 1')
                ->pluck('entry_id')
                ->take(5)
                ->implode(', ');

            throw new RuntimeException(
                "Refusing to reconcile: {$table} holds {$physical} row(s) but only "
                .count($rows)." distinct entry_id value(s). Duplicate entry ids: {$duplicates}. "
                .'Comparing tables by entry id would discard the physical rows that share one, '
                .'so no table has been modified. Deduplicate and rerun.'
            );
        }

        return $rows;
    }

    /**
     * @param  array<string, array<string, mixed>>  $candidate
     * @param  array<string, array<string, mixed>>  $container
     */
    private function isSubset(array $candidate, array $container): bool
    {
        foreach ($candidate as $entryId => $row) {
            // Present AND identical: a matching entry_id carrying a different
            // hash is a conflict, not a match.
            /*
             * `!==` compares keys, values AND types, so NULL never equals ''
             * and NULL never equals 0. Coercing first is what made different
             * rows look identical.
             */
            if (! array_key_exists($entryId, $container) || $container[$entryId] !== $row) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param  array<string, array<string, mixed>>  $left
     * @param  array<string, array<string, mixed>>  $right
     * @return array<string, array<string, mixed>>
     */
    private function difference(array $left, array $right): array
    {
        return array_filter(
            $left,
            static fn (array $row, string $entryId): bool => ! array_key_exists($entryId, $right)
                || $right[$entryId] !== $row,
            ARRAY_FILTER_USE_BOTH,
        );
    }

    /**
     * Ensure the live table carries the FINAL index names.
     *
     * A table promoted straight out of `_rebuild` still wears the temporary
     * names, which travelled with it across the rename.
     */
    private function assertFinalIndexNames(string $table, string $temporaryPrefix): void
    {
        if (SchemaContract::indexContractHolds($table, $temporaryPrefix.'_entry_id_unique', ['entry_id'], true)) {
            Schema::table($table, function (Blueprint $blueprint) use ($temporaryPrefix): void {
                // MIGRATION-GUARD: intentional-drop — temporary names created
                // by this migration's own rebuild path.
                $blueprint->dropUnique($temporaryPrefix.'_entry_id_unique');
            });
        }

        if (SchemaContract::indexContractHolds($table, $temporaryPrefix.'_orphaned_file_id_index', ['orphaned_file_id'], false)) {
            Schema::table($table, function (Blueprint $blueprint) use ($temporaryPrefix): void {
                // MIGRATION-GUARD: intentional-drop — as above.
                $blueprint->dropIndex($temporaryPrefix.'_orphaned_file_id_index');
            });
        }
    }

    /**
     * Create the ledger exactly as 001900 defines it.
     *
     * The table and index names are parameterised because a SQLite rebuild
     * must build the replacement alongside the original. SQLite keeps a
     * table's named indexes attached to it across a RENAME, so recreating
     * them under their final names while the old table still holds them fails
     * with "index ... already exists" — the executed probe hit exactly that.
     */
    private function createLedger(string $table = 'cleanup_journal_imports', string $indexPrefix = 'cleanup_journal_imports'): void
    {
        Schema::create($table, function (Blueprint $blueprint) use ($indexPrefix): void {
            $blueprint->id();

            // THE idempotency key, enforced by the database.
            $blueprint->string('entry_id', 64)->unique($indexPrefix.'_entry_id_unique');

            // Several entries may legitimately feed one cleanup job.
            $blueprint->unsignedBigInteger('orphaned_file_id')
                ->index($indexPrefix.'_orphaned_file_id_index');

            // NOT NULL: a row without integrity evidence is one the replay
            // must treat as a conflict, not as trustworthy.
            $blueprint->string('payload_hash', 64);

            $blueprint->timestamp('imported_at');
            $blueprint->timestamps();

            /*
             * RESTRICT, declared at CREATE time so SQLite gets it too — that
             * driver cannot add a foreign key to an existing table, which is
             * why the rebuild below exists at all.
             */
            $blueprint->foreign('orphaned_file_id', $indexPrefix.'_orphaned_file_id_foreign')
                ->references('id')
                ->on('orphaned_files')
                ->restrictOnDelete();
        });
    }

    public function down(): void
    {
        /*
         * A VERIFIED NO-OP, not a refusal.
         *
         * This throwing was a blocker, not a safeguard. `RollbackWizardSchema`
         * invokes each migration's `down()` directly and lists this one first,
         * so a database that had run 002000 could not reach the reversal of
         * 001900, 001800 or 001700 at all — the entire rollback chain was
         * unreachable behind an exception.
         *
         * Nothing here needs undoing separately. This migration only tightens
         * constraints on objects that 001900 CREATED: `incident_uuid`,
         * `payload_hash`, the ledger's indexes and its foreign key. Reversing
         * 001900 drops the columns and the table outright, taking every
         * constraint with them.
         *
         * So the correct reversal is to do nothing — and to say why, rather
         * than leaving an empty method that reads like an oversight.
         */
        if (! Schema::hasTable('orphaned_files')) {
            return;
        }

        /*
         * One check worth keeping: if 001900 has already been reversed, its
         * objects are gone and there is genuinely nothing left to describe. If
         * it has NOT, they are about to be, by the migration after this one in
         * the chain. Either way this method has no work.
         */
        Log::info(
            'Reconciliation migration 002000 reversed as a no-op; its constraints belong to '
            .'2026_07_25_001900_immutable_cleanup_incidents, which drops them with their columns.'
        );
    }

    /** `incident_uuid` must exist, be populated, be NOT NULL and be unique. */
    private function reconcileIncidentUuid(): void
    {
        if (! Schema::hasColumn('orphaned_files', 'incident_uuid')) {
            Schema::table('orphaned_files', function (Blueprint $table): void {
                $table->uuid('incident_uuid')->nullable();
            });
        }

        // Fill only what is missing: an existing uuid is an identity that must
        // never change, because anything referring to it would stop matching.
        foreach (
            DB::table('orphaned_files')
                ->select('id')
                ->whereNull('incident_uuid')
                ->cursor() as $row
        ) {
            DB::table('orphaned_files')
                ->where('id', $row->id)
                ->update(['incident_uuid' => (string) Str::uuid()]);
        }

        $stillNull = DB::table('orphaned_files')->whereNull('incident_uuid')->count();

        if ($stillNull > 0) {
            throw new RuntimeException(
                "Backfill incomplete: {$stillNull} orphaned_files row(s) still have no "
                .'incident_uuid. Refusing to enforce a constraint the data violates.'
            );
        }

        /*
         * Duplicates would make the unique index impossible. They can only
         * arise from a manual insert or an interrupted earlier backfill, and
         * merging two incidents is a judgement this migration must not make.
         */
        $duplicateUuids = DB::table('orphaned_files')
            ->select('incident_uuid')
            ->groupBy('incident_uuid')
            ->havingRaw('COUNT(*) > 1')
            ->get()
            ->count();

        if ($duplicateUuids > 0) {
            throw new RuntimeException(
                "Cannot enforce incident_uuid uniqueness: {$duplicateUuids} value(s) appear "
                .'more than once. These are distinct incidents sharing an identity and need '
                .'operator reconciliation.'
            );
        }

        if ($this->columnIsNullable('orphaned_files', 'incident_uuid')) {
            Schema::table('orphaned_files', function (Blueprint $table): void {
                $table->uuid('incident_uuid')->nullable(false)->change();
            });
        }

        if ($this->columnIsNullable('orphaned_files', 'incident_uuid')) {
            throw new RuntimeException('incident_uuid is still nullable after the change.');
        }

        if (! SchemaContract::indexContractHolds('orphaned_files', 'orphaned_files_incident_uuid_unique', ['incident_uuid'], true)) {
            Schema::table('orphaned_files', function (Blueprint $table): void {
                $table->unique('incident_uuid', 'orphaned_files_incident_uuid_unique');
            });
        }

        if (! SchemaContract::indexContractHolds('orphaned_files', 'orphaned_files_incident_uuid_unique', ['incident_uuid'], true)) {
            throw new RuntimeException('The incident_uuid unique index was not created.');
        }
    }

    /** The ledger's columns, constraints and nullability must match the contract. */
    private function reconcileLedger(): void
    {
        /*
         * NULL HASHES ARE NOT REPAIRABLE HERE.
         *
         * A null means integrity evidence was never recorded for that import.
         * The correct hash is `sha256` of the original journal line, which no
         * longer exists — the line was consumed. Writing anything else would
         * fabricate proof, so these rows are reported and the migration stops.
         */
        if (Schema::hasColumn('cleanup_journal_imports', 'payload_hash')) {
            /*
             * A HASH MUST LOOK LIKE ONE. Rejecting only NULL let empty, short
             * and non-hex values through — they are not SHA-256 integrity
             * evidence, so the replay's conflict check would compare against
             * something meaningless and report a match.
             */
            $malformed = DB::table('cleanup_journal_imports')
                ->select('entry_id', 'payload_hash')
                ->get()
                ->filter(static fn ($row): bool => $row->payload_hash !== null
                    && preg_match('/^[0-9a-f]{64}$/', (string) $row->payload_hash) !== 1);

            if ($malformed->isNotEmpty()) {
                $sample = $malformed->take(5)
                    ->map(static fn ($row): string => $row->entry_id.'='.var_export($row->payload_hash, true))
                    ->implode(', ');

                throw new RuntimeException(
                    'Cannot enforce payload_hash integrity: '.$malformed->count()
                    .' row(s) hold a value that is not a 64-character lowercase SHA-256 hex '
                    ."digest. Sample: {$sample}. These carry no usable integrity evidence; "
                    .'correct or delete them, then rerun. No change has been made.'
                );
            }

            /*
             * `orphaned_file_id` must name a real job. Null or zero is a
             * ledger row pointing at nothing.
             */
            $danglingTargets = DB::table('cleanup_journal_imports as i')
                ->leftJoin('orphaned_files as o', 'o.id', '=', 'i.orphaned_file_id')
                ->where(function ($query): void {
                    $query->whereNull('i.orphaned_file_id')
                        ->orWhere('i.orphaned_file_id', '<=', 0)
                        ->orWhereNull('o.id');
                })
                ->count();

            if ($danglingTargets > 0) {
                throw new RuntimeException(
                    "Cannot enforce the ledger contract: {$danglingTargets} row(s) have a null, "
                    .'non-positive or unresolvable orphaned_file_id. No change has been made.'
                );
            }

            $nullHashes = DB::table('cleanup_journal_imports')->whereNull('payload_hash')->count();

            if ($nullHashes > 0) {
                $sample = DB::table('cleanup_journal_imports')
                    ->whereNull('payload_hash')
                    ->limit(5)
                    ->pluck('entry_id')
                    ->implode(', ');

                throw new RuntimeException(
                    "Cannot enforce payload_hash NOT NULL: {$nullHashes} import(s) have no "
                    ."integrity hash. Sample entry ids: {$sample}. These were recorded before "
                    .'the hash was required and cannot be reconstructed — the journal lines '
                    .'they describe have been consumed. Review and either delete them (their '
                    .'cleanup jobs remain intact) or record a hash deliberately, then rerun.'
                );
            }
        } else {
            // Absent entirely on a very old deployment: add it nullable first
            // so the emptiness check above governs the tightening below.
            Schema::table('cleanup_journal_imports', function (Blueprint $table): void {
                $table->string('payload_hash', 64)->nullable();
            });
        }

        if ($this->columnIsNullable('cleanup_journal_imports', 'payload_hash')) {
            Schema::table('cleanup_journal_imports', function (Blueprint $table): void {
                $table->string('payload_hash', 64)->nullable(false)->change();
            });
        }

        if ($this->columnIsNullable('cleanup_journal_imports', 'payload_hash')) {
            throw new RuntimeException('payload_hash is still nullable after the change.');
        }

        /* ------------------------------------------------------ entry_id */

        $entryLength = $this->columnLength('cleanup_journal_imports', 'entry_id');

        /*
         * 64 is the contract, and the legacy key is exactly 64 characters. A
         * shorter column would silently truncate two different journal lines
         * to one ledger entry, which is the collision the key exists to avoid.
         */
        if ($entryLength !== null && $entryLength < 64) {
            $tooLong = DB::table('cleanup_journal_imports')
                ->whereRaw('LENGTH(entry_id) > ?', [$entryLength])
                ->count();

            if ($tooLong > 0) {
                throw new RuntimeException(
                    "Cannot widen entry_id: {$tooLong} row(s) already exceed the current "
                    .'column length and may have been truncated. Operator reconciliation '
                    .'is required before the constraint can be trusted.'
                );
            }

            Schema::table('cleanup_journal_imports', function (Blueprint $table): void {
                $table->string('entry_id', 64)->change();
            });
        }

        $duplicateEntries = DB::table('cleanup_journal_imports')
            ->select('entry_id')
            ->groupBy('entry_id')
            ->havingRaw('COUNT(*) > 1')
            ->get()
            ->count();

        if ($duplicateEntries > 0) {
            throw new RuntimeException(
                "Cannot enforce entry_id uniqueness: {$duplicateEntries} value(s) appear more "
                .'than once, which means an entry was imported twice. Deduplicate before '
                .'rerunning; the surviving row must be the one whose payload_hash matches.'
            );
        }

        if (! SchemaContract::indexContractHolds('cleanup_journal_imports', 'cleanup_journal_imports_entry_id_unique', ['entry_id'], true)) {
            Schema::table('cleanup_journal_imports', function (Blueprint $table): void {
                $table->unique('entry_id', 'cleanup_journal_imports_entry_id_unique');
            });
        }

        if (! SchemaContract::indexContractHolds('cleanup_journal_imports', 'cleanup_journal_imports_entry_id_unique', ['entry_id'], true)) {
            throw new RuntimeException('The entry_id unique index was not created.');
        }

        /* -------------------------------------------------- foreign key */

        /*
         * RESTRICT, not cascade. The ledger is the evidence that a journal
         * entry was consumed; deleting a cleanup job must not erase the proof
         * that its work already happened, or a replay imports it again.
         *
         * SQLite cannot add a foreign key to an existing table. An earlier
         * version logged a notice here and claimed the rule was enforced in
         * the model layer — it was not, anywhere in the codebase. The table is
         * rebuilt with the key declared at CREATE time instead.
         */
        /*
         * BOTH FINAL INDEXES, VERIFIED BEFORE THE FK SHORT-CIRCUIT.
         *
         * Reproduction C: a live table with a valid FK but no indexes returned
         * success here, so the normal `orphaned_file_id` index stayed missing
         * — the migration stopped at the first thing that happened to be
         * right.
         */
        $this->assertFinalIndexNames('cleanup_journal_imports', 'cleanup_journal_imports_rebuild');

        if (! SchemaContract::indexContractHolds('cleanup_journal_imports', 'cleanup_journal_imports_entry_id_unique', ['entry_id'], true)) {
            Schema::table('cleanup_journal_imports', function (Blueprint $table): void {
                $table->unique('entry_id', 'cleanup_journal_imports_entry_id_unique');
            });
        }

        if (! SchemaContract::indexContractHolds('cleanup_journal_imports', 'cleanup_journal_imports_entry_id_unique', ['entry_id'], true)) {
            throw new RuntimeException(
                'The entry_id unique index is not present on the live ledger over exactly '
                .'[entry_id]. Duplicate imports would be accepted.'
            );
        }

        if (! SchemaContract::indexContractHolds('cleanup_journal_imports', 'cleanup_journal_imports_orphaned_file_id_index', ['orphaned_file_id'], false)) {
            Schema::table('cleanup_journal_imports', function (Blueprint $table): void {
                $table->index('orphaned_file_id', 'cleanup_journal_imports_orphaned_file_id_index');
            });
        }

        if (! SchemaContract::indexContractHolds('cleanup_journal_imports', 'cleanup_journal_imports_orphaned_file_id_index', ['orphaned_file_id'], false)) {
            throw new RuntimeException(
                'The orphaned_file_id index is not present on the live ledger over exactly '
                .'[orphaned_file_id].'
            );
        }

        if ($this->foreignKeyContractHolds('cleanup_journal_imports', 'orphaned_file_id')) {
            return;   // FK correct, and both indexes now verified above
        }

        /*
         * Rows pointing at a job that no longer exists would fail the
         * constraint. They are a symptom of the missing RESTRICT, so they are
         * reported rather than deleted — each is proof of an import whose job
         * was removed.
         */
        $dangling = DB::table('cleanup_journal_imports as i')
            ->leftJoin('orphaned_files as o', 'o.id', '=', 'i.orphaned_file_id')
            ->whereNull('o.id')
            ->count();

        if ($dangling > 0) {
            throw new RuntimeException(
                "Cannot enforce the orphaned_file_id foreign key: {$dangling} ledger row(s) "
                .'reference a cleanup job that no longer exists. Reconcile them first.'
            );
        }

        if (DB::connection()->getDriverName() === 'sqlite') {
            /*
             * SQLite cannot ALTER a table to add a foreign key. The previous
             * version logged a notice and carried on, claiming model-layer
             * enforcement that exists nowhere in the codebase — a comment is
             * not a constraint. The table is rebuilt instead, with the key
             * declared at CREATE time.
             */
            $this->rebuildLedgerWithForeignKey();
        } else {
            Schema::table('cleanup_journal_imports', function (Blueprint $table): void {
                $table->foreign('orphaned_file_id', 'cleanup_journal_imports_orphaned_file_id_foreign')
                    ->references('id')
                    ->on('orphaned_files')
                    ->restrictOnDelete();
            });
        }

        if (! $this->foreignKeyContractHolds('cleanup_journal_imports', 'orphaned_file_id')) {
            throw new RuntimeException(
                'The orphaned_file_id foreign key does not satisfy the full contract '
                .'(orphaned_files.id, ON DELETE RESTRICT) after the change.'
            );
        }
    }

    /**
     * Rebuild the ledger with the real foreign key (SQLite only).
     *
     * TEMPORARY NAMES THROUGHOUT. SQLite carries a table's named indexes with
     * it across a RENAME, so the first version — rename to `_old`, then
     * recreate under the final names — failed with
     * "index cleanup_journal_imports_entry_id_unique already exists".
     *
     * The replacement is therefore built beside the original under temporary
     * table AND index names, verified, and only then swapped in. Nothing is
     * dropped until the copy is proven complete, so an interruption at any
     * point leaves either the original intact or a resumable remnant.
     */
    private function rebuildLedgerWithForeignKey(): void
    {
        $temporaryTable = 'cleanup_journal_imports_rebuild';

        /*
         * A remnant from an interrupted earlier attempt. It is incomplete by
         * definition — the swap never happened — so it is discarded rather
         * than trusted.
         */
        if (Schema::hasTable($temporaryTable)) {
            Schema::drop($temporaryTable);
        }

        /*
         * An `_old` table means a previous run renamed the original and then
         * failed. The live table may or may not exist; the `_old` copy is the
         * authoritative data in either case.
         */
        if (Schema::hasTable('cleanup_journal_imports_old')) {
            if (Schema::hasTable('cleanup_journal_imports')) {
                // Both present: the live one is a partial rebuild, the old one
                // is the real data.
                Schema::drop('cleanup_journal_imports');
            }

            Schema::rename('cleanup_journal_imports_old', 'cleanup_journal_imports');
        }

        $original = DB::table('cleanup_journal_imports')->count();

        $this->createLedger($temporaryTable, $temporaryTable);

        DB::statement(
            "INSERT INTO {$temporaryTable} "
            .'(id, entry_id, orphaned_file_id, payload_hash, imported_at, created_at, updated_at) '
            .'SELECT id, entry_id, orphaned_file_id, payload_hash, imported_at, created_at, updated_at '
            .'FROM cleanup_journal_imports'
        );

        $copied = DB::table($temporaryTable)->count();

        if ($copied !== $original) {
            // Nothing has been destroyed: the original is still the live table.
            Schema::drop($temporaryTable);

            throw new RuntimeException(
                "Ledger rebuild copied {$copied} of {$original} rows. No change has been made; "
                .'reconcile the data before rerunning.'
            );
        }

        // VERIFIED BEFORE THE SWAP: the point of the rebuild is the foreign
        // key, so a replacement without it must not replace anything.
        if (! $this->foreignKeyContractHolds($temporaryTable, 'orphaned_file_id')) {
            Schema::drop($temporaryTable);

            throw new RuntimeException(
                'The rebuilt ledger does not carry the required foreign key. '
                .'No change has been made.'
            );
        }

        Schema::drop('cleanup_journal_imports');
        Schema::rename($temporaryTable, 'cleanup_journal_imports');

        /*
         * The indexes still carry temporary names, which travelled with the
         * table across the rename. They are recreated under their final names
         * now that nothing else holds those names.
         */
        if (Schema::hasColumn('cleanup_journal_imports', 'entry_id')) {
            Schema::table('cleanup_journal_imports', function (Blueprint $table) use ($temporaryTable): void {
                // MIGRATION-GUARD: intentional-drop — these are the temporary
                // index names this method created moments ago; they travelled
                // with the table across the rename and must give up the final
                // names.
                $table->dropUnique($temporaryTable.'_entry_id_unique');
                $table->dropIndex($temporaryTable.'_orphaned_file_id_index');
            });

            Schema::table('cleanup_journal_imports', function (Blueprint $table): void {
                $table->unique('entry_id', 'cleanup_journal_imports_entry_id_unique');
                $table->index('orphaned_file_id', 'cleanup_journal_imports_orphaned_file_id_index');
            });
        }
    }

    /**
     * Whether a column permits null, per driver.
     *
     * @throws RuntimeException when the answer cannot be determined
     */
    private function columnIsNullable(string $table, string $column): bool
    {
        $connection = DB::connection();
        $driver = $connection->getDriverName();

        try {
            return match ($driver) {
                'sqlite' => (bool) collect(DB::select("PRAGMA table_info('{$table}')"))
                    ->first(static fn ($info): bool => $info->name === $column)?->notnull === false,
                'mysql', 'mariadb', 'pgsql' => DB::table('information_schema.COLUMNS')
                    ->where('TABLE_SCHEMA', $connection->getDatabaseName())
                    ->where('TABLE_NAME', $table)
                    ->where('COLUMN_NAME', $column)
                    ->value('IS_NULLABLE') === 'YES',
                default => throw new RuntimeException("Cannot inspect nullability on [{$driver}]."),
            };
        } catch (Throwable $e) {
            // Fail closed: an unverifiable answer must not be read as "already
            // correct", which would skip the change and then claim success.
            throw new RuntimeException(
                "Could not determine nullability of {$table}.{$column}: ".$e->getMessage(),
                previous: $e,
            );
        }
    }

    /** Declared length of a string column, or null when not applicable. */
    private function columnLength(string $table, string $column): ?int
    {
        $connection = DB::connection();

        try {
            return match ($connection->getDriverName()) {
                'sqlite' => (function () use ($table, $column): ?int {
                    $type = collect(DB::select("PRAGMA table_info('{$table}')"))
                        ->first(static fn ($info): bool => $info->name === $column)?->type;

                    return preg_match('/\((\d+)\)/', (string) $type, $matches)
                        ? (int) $matches[1]
                        : null;
                })(),
                'mysql', 'mariadb', 'pgsql' => ($length = DB::table('information_schema.COLUMNS')
                    ->where('TABLE_SCHEMA', $connection->getDatabaseName())
                    ->where('TABLE_NAME', $table)
                    ->where('COLUMN_NAME', $column)
                    ->value('CHARACTER_MAXIMUM_LENGTH')) === null ? null : (int) $length,
                default => null,
            };
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Whether the COMPLETE foreign-key contract is in force.
     *
     * Not "some foreign key on this column". A CASCADE would silently delete
     * the evidence that a journal entry was consumed — after which a replay
     * re-imports it — and a key pointing at the wrong table constrains nothing
     * relevant. All three parts are checked:
     *
     *   - referenced table  = orphaned_files
     *   - referenced column = id
     *   - delete action     = RESTRICT
     *
     * Fails closed: an uninspectable driver throws rather than reporting the
     * contract satisfied.
     */
    private function foreignKeyContractHolds(string $table, string $column): bool
    {
        $connection = DB::connection();

        try {
            return match ($connection->getDriverName()) {
                'sqlite' => collect(DB::select("PRAGMA foreign_key_list('{$table}')"))
                    ->contains(static fn ($key): bool => $key->from === $column
                        && $key->table === 'orphaned_files'
                        && $key->to === 'id'
                        && strtoupper((string) $key->on_delete) === 'RESTRICT'),
                'mysql', 'mariadb' => DB::table('information_schema.KEY_COLUMN_USAGE as k')
                    ->join('information_schema.REFERENTIAL_CONSTRAINTS as r', function ($join): void {
                        $join->on('r.CONSTRAINT_NAME', '=', 'k.CONSTRAINT_NAME')
                            ->on('r.CONSTRAINT_SCHEMA', '=', 'k.TABLE_SCHEMA');
                    })
                    ->where('k.TABLE_SCHEMA', $connection->getDatabaseName())
                    ->where('k.TABLE_NAME', $table)
                    ->where('k.COLUMN_NAME', $column)
                    ->where('k.REFERENCED_TABLE_NAME', 'orphaned_files')
                    ->where('k.REFERENCED_COLUMN_NAME', 'id')
                    ->where('r.DELETE_RULE', 'RESTRICT')
                    ->exists(),
                'pgsql' => DB::table('information_schema.table_constraints as tc')
                    ->join('information_schema.key_column_usage as kcu', 'kcu.constraint_name', '=', 'tc.constraint_name')
                    ->join('information_schema.constraint_column_usage as ccu', 'ccu.constraint_name', '=', 'tc.constraint_name')
                    ->join('information_schema.referential_constraints as rc', 'rc.constraint_name', '=', 'tc.constraint_name')
                    ->where('tc.table_name', $table)
                    ->where('tc.constraint_type', 'FOREIGN KEY')
                    ->where('kcu.column_name', $column)
                    ->where('ccu.table_name', 'orphaned_files')
                    ->where('ccu.column_name', 'id')
                    ->where('rc.delete_rule', 'RESTRICT')
                    ->exists(),
                default => throw new RuntimeException('Cannot inspect foreign keys on this driver.'),
            };
        } catch (Throwable $e) {
            throw new RuntimeException(
                "Could not verify the foreign-key contract on {$table}.{$column}: ".$e->getMessage(),
                previous: $e,
            );
        }
    }
};
