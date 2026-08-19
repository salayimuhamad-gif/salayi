<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\Projects\Models\OrphanedFile;
use App\Modules\Projects\Support\SchemaContract;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Tests\TestCase;

/**
 * Migration 002000 must converge from every interrupted SQLite state.
 *
 * Three states were reproduced against the previous build, and all three came
 * from the migration deciding "no ledger exists" or "the ledger is fine"
 * without first looking at `_old` and `_rebuild`.
 *
 * These run the REAL migration through Laravel's migrator on the test
 * database, not a hand-written DDL probe: the defects were in what the
 * migration concluded, so a probe that reimplements its logic proves nothing.
 */
final class LedgerRecoveryTest extends TestCase
{
    private const MIGRATION = '2026_07_25_002000_reconcile_cleanup_ledger_schema';

    /**
     * SQLITE ONLY, AND EXPLICITLY SO.
     *
     * The interrupted states this class builds need SQLite's own DDL and
     * PRAGMA introspection. The class previously ran in the MySQL matrix job
     * too, where it hit `PRAGMA index_list` and failed before proving
     * anything about the migration.
     *
     * The MySQL equivalents live in LedgerRecoveryMysqlTest, so neither driver
     * is silently skipped — the same defects are covered on both, with the
     * DDL each one actually understands.
     */
    protected function setUp(): void
    {
        parent::setUp();

        if (DB::connection()->getDriverName() !== 'sqlite') {
            $this->markTestSkipped(
                'Covered for MySQL by LedgerRecoveryMysqlTest; this class builds '
                .'SQLite-specific interrupted states.'
            );
        }

        /*
         * A CLEAN SCHEMA PER TEST. Without RefreshDatabase nothing creates the
         * tables, and the previous version depended on whatever an earlier
         * test happened to leave behind — so the FK assertion passed only when
         * it ran before the tests that drop the ledger.
         */
        $this->artisan('migrate:fresh', ['--force' => true])->assertSuccessful();
    }

    protected function tearDown(): void
    {
        foreach ([
            'cleanup_journal_imports',
            'cleanup_journal_imports_old',
            'cleanup_journal_imports_rebuild',
        ] as $table) {
            Schema::dropIfExists($table);
        }

        /*
         * RESTORE THE CANONICAL SCHEMA.
         *
         * This class deliberately dismantles the ledger to build
         * interrupted states, and the suite runs against a FILE-BACKED
         * SQLite database (the concurrency proofs need real shared state,
         * which ":memory:" cannot give two processes). A dropped table
         * therefore outlives this class: the next class using
         * RefreshDatabase reuses the already-migrated file, finds the
         * ledger missing, and its journal replay fails for a reason that
         * has nothing to do with the code under test.
         *
         * Leaving the database as production would have it is this
         * class's responsibility, since it is the one that took it apart.
         */
        if (DB::connection()->getDriverName() === 'sqlite') {
            $this->artisan('migrate:fresh', ['--force' => true])->assertSuccessful();
        }

        parent::tearDown();
    }

    /** Reproduction A: `_old` holds the rows, the live table is empty. */
    public function test_it_recovers_rows_stranded_in_an_old_table(): void
    {
        $job = $this->job();

        Schema::dropIfExists('cleanup_journal_imports');

        // The pre-rebuild original, carrying the FINAL index names.
        $this->createLedgerTable('cleanup_journal_imports_old', 'cleanup_journal_imports');

        DB::table('cleanup_journal_imports_old')->insert($this->row($job->id, 'entry-a'));

        // An empty live table created by the interrupted run.
        $this->createLedgerTable('cleanup_journal_imports', 'cleanup_journal_imports_live_tmp');

        $this->rerunMigration();

        // The row is live, and the remnant is gone.
        $this->assertSame(1, DB::table('cleanup_journal_imports')->count());
        $this->assertSame('entry-a', DB::table('cleanup_journal_imports')->value('entry_id'));
        $this->assertFalse(Schema::hasTable('cleanup_journal_imports_old'));

        // And the live table really owns its unique constraint now: the
        // previous build left it owned by `_old`, so duplicates were accepted.
        $this->expectException(UniqueConstraintViolationException::class);

        DB::table('cleanup_journal_imports')->insert($this->row($job->id, 'entry-a'));
    }

    /** Reproduction B: only `_rebuild` survives, the original was dropped. */
    public function test_it_recovers_rows_stranded_in_a_rebuild_table(): void
    {
        $job = $this->job();

        Schema::dropIfExists('cleanup_journal_imports');

        $this->createLedgerTable('cleanup_journal_imports_rebuild', 'cleanup_journal_imports_rebuild');

        DB::table('cleanup_journal_imports_rebuild')->insert($this->row($job->id, 'entry-b'));

        $this->rerunMigration();

        // NOT a fresh empty ledger over the top of the copied rows.
        $this->assertSame(1, DB::table('cleanup_journal_imports')->count());
        $this->assertSame('entry-b', DB::table('cleanup_journal_imports')->value('entry_id'));
        $this->assertFalse(Schema::hasTable('cleanup_journal_imports_rebuild'));
    }

    /** Reproduction C: the swap happened, the final indexes did not. */
    public function test_it_restores_both_final_indexes(): void
    {
        $job = $this->job();

        Schema::dropIfExists('cleanup_journal_imports');
        $this->createLedgerTable('cleanup_journal_imports', 'cleanup_journal_imports');

        DB::table('cleanup_journal_imports')->insert($this->row($job->id, 'entry-c'));

        // Remove both, leaving a valid FK — the state that returned success.
        DB::statement('DROP INDEX IF EXISTS cleanup_journal_imports_entry_id_unique');
        DB::statement('DROP INDEX IF EXISTS cleanup_journal_imports_orphaned_file_id_index');

        $this->rerunMigration();

        $indexes = collect(DB::select("PRAGMA index_list('cleanup_journal_imports')"))
            ->pluck('name');

        $this->assertContains('cleanup_journal_imports_entry_id_unique', $indexes);
        $this->assertContains('cleanup_journal_imports_orphaned_file_id_index', $indexes);

        // Data untouched.
        $this->assertSame(1, DB::table('cleanup_journal_imports')->count());
    }

    /** The foreign key must be orphaned_files(id) ON DELETE RESTRICT. */
    public function test_the_final_ledger_carries_the_restrict_foreign_key(): void
    {
        $this->job();

        /*
         * Rebuild the exact state under test rather than inheriting it. This
         * assertion previously read whatever the previous test had left, so it
         * was order-dependent and could pass without the migration running.
         */
        Schema::dropIfExists('cleanup_journal_imports');

        $this->rerunMigration();

        $key = collect(DB::select("PRAGMA foreign_key_list('cleanup_journal_imports')"))
            ->first(static fn ($row): bool => $row->from === 'orphaned_file_id');

        $this->assertNotNull($key, 'The ledger has no foreign key on orphaned_file_id.');
        $this->assertSame('orphaned_files', $key->table);
        $this->assertSame('id', $key->to);
        $this->assertSame('RESTRICT', strtoupper((string) $key->on_delete));
    }

    /** Rerunning after recovery must change nothing. */
    public function test_recovery_is_idempotent(): void
    {
        $job = $this->job();

        Schema::dropIfExists('cleanup_journal_imports');
        $this->createLedgerTable('cleanup_journal_imports_old', 'cleanup_journal_imports');

        DB::table('cleanup_journal_imports_old')->insert($this->row($job->id, 'entry-idem'));

        $this->rerunMigration();

        $first = DB::table('cleanup_journal_imports')->get()->toArray();

        $this->rerunMigration();

        $second = DB::table('cleanup_journal_imports')->get()->toArray();

        // Same rows, same ids, same hashes, and no remnants either time.
        $this->assertEquals($first, $second);
        $this->assertFalse(Schema::hasTable('cleanup_journal_imports_old'));
        $this->assertFalse(Schema::hasTable('cleanup_journal_imports_rebuild'));
    }

    /* ---------------- divergent tables must not lose evidence */

    /**
     * Equal counts, different rows. The count comparison called these
     * equivalent and dropped the live row permanently.
     */
    public function test_it_refuses_to_reconcile_divergent_equal_count_tables(): void
    {
        $job = $this->job();

        Schema::dropIfExists('cleanup_journal_imports');
        $this->createLedgerTable('cleanup_journal_imports', 'cleanup_journal_imports');
        DB::table('cleanup_journal_imports')->insert($this->row($job->id, 'live-entry'));

        $this->createLedgerTable('cleanup_journal_imports_old', 'cleanup_journal_imports_old_ix');
        DB::table('cleanup_journal_imports_old')->insert($this->row($job->id, 'old-entry'));

        try {
            $this->rerunMigration();

            $this->fail('The migration must refuse to choose between divergent ledgers.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('Neither is a subset', $e->getMessage());
        }

        // NOTHING was destroyed: both rows survive for a person to merge.
        $this->assertSame('live-entry', DB::table('cleanup_journal_imports')->value('entry_id'));
        $this->assertSame('old-entry', DB::table('cleanup_journal_imports_old')->value('entry_id'));
    }

    /**
     * A larger live table does not make a distinct `_rebuild` row redundant.
     */
    public function test_it_does_not_drop_a_distinct_rebuild_row(): void
    {
        $job = $this->job();

        Schema::dropIfExists('cleanup_journal_imports');
        $this->createLedgerTable('cleanup_journal_imports', 'cleanup_journal_imports');
        DB::table('cleanup_journal_imports')->insert($this->row($job->id, 'live-a'));
        DB::table('cleanup_journal_imports')->insert($this->row($job->id, 'live-b'));

        $this->createLedgerTable('cleanup_journal_imports_rebuild', 'cleanup_journal_imports_rebuild');
        DB::table('cleanup_journal_imports_rebuild')->insert($this->row($job->id, 'rebuild-only'));

        try {
            $this->rerunMigration();

            $this->fail('A distinct rebuild row must not be discarded on a count comparison.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('Neither is a subset', $e->getMessage());
        }

        $this->assertSame(
            'rebuild-only',
            DB::table('cleanup_journal_imports_rebuild')->value('entry_id'),
        );
        $this->assertSame(2, DB::table('cleanup_journal_imports')->count());
    }

    /** Exactly identical tables may converge. */
    public function test_identical_tables_converge_safely(): void
    {
        $job = $this->job();

        Schema::dropIfExists('cleanup_journal_imports');
        $this->createLedgerTable('cleanup_journal_imports', 'cleanup_journal_imports');
        $this->createLedgerTable('cleanup_journal_imports_old', 'cleanup_journal_imports_old_ix');

        $row = array_merge($this->row($job->id, 'same-entry'), ['id' => 1]);

        DB::table('cleanup_journal_imports')->insert($row);
        DB::table('cleanup_journal_imports_old')->insert($row);

        $this->rerunMigration();

        $this->assertFalse(Schema::hasTable('cleanup_journal_imports_old'));
        $this->assertSame(1, DB::table('cleanup_journal_imports')->count());
        $this->assertSame('same-entry', DB::table('cleanup_journal_imports')->value('entry_id'));
    }

    /** A proven subset may be removed. */
    public function test_a_subset_rebuild_is_removed_after_row_proof(): void
    {
        $job = $this->job();

        Schema::dropIfExists('cleanup_journal_imports');
        $this->createLedgerTable('cleanup_journal_imports', 'cleanup_journal_imports');
        $this->createLedgerTable('cleanup_journal_imports_rebuild', 'cleanup_journal_imports_rebuild');

        $shared = array_merge($this->row($job->id, 'shared'), ['id' => 1]);

        DB::table('cleanup_journal_imports')->insert($shared);
        DB::table('cleanup_journal_imports')->insert(array_merge($this->row($job->id, 'live-extra'), ['id' => 2]));
        DB::table('cleanup_journal_imports_rebuild')->insert($shared);

        $this->rerunMigration();

        $this->assertFalse(Schema::hasTable('cleanup_journal_imports_rebuild'));
        $this->assertSame(2, DB::table('cleanup_journal_imports')->count());
    }

    /** Ids, entry ids, targets and hashes survive recovery untouched. */
    public function test_recovery_preserves_exact_row_content(): void
    {
        $job = $this->job();

        Schema::dropIfExists('cleanup_journal_imports');
        $this->createLedgerTable('cleanup_journal_imports_old', 'cleanup_journal_imports');

        $original = array_merge($this->row($job->id, 'exact-entry'), ['id' => 7]);

        DB::table('cleanup_journal_imports_old')->insert($original);

        $this->rerunMigration();

        $stored = DB::table('cleanup_journal_imports')->first();

        $this->assertSame(7, (int) $stored->id);
        $this->assertSame('exact-entry', $stored->entry_id);
        $this->assertSame($job->id, (int) $stored->orphaned_file_id);
        $this->assertSame($original['payload_hash'], $stored->payload_hash);
    }

    /* ---------------- duplicate entry ids must fail closed */

    /**
     * The exact reproduction: a remnant holding two rows with one entry id.
     *
     * Keying by entry_id collapsed them, the remnant then looked like a subset
     * of live, and `id=1/hash-a` was dropped permanently.
     */
    public function test_duplicate_entry_ids_in_the_old_table_fail_closed(): void
    {
        $job = $this->job();

        Schema::dropIfExists('cleanup_journal_imports');
        $this->createLedgerTable('cleanup_journal_imports', 'cleanup_journal_imports');
        DB::table('cleanup_journal_imports')->insert(
            array_merge($this->row($job->id, 'dup-entry'), ['id' => 2, 'payload_hash' => 'hash-b']),
        );

        // No unique index here, so duplicates are physically possible.
        $this->createLedgerTable('cleanup_journal_imports_old', 'cleanup_old_ix', false);
        DB::table('cleanup_journal_imports_old')->insert(
            array_merge($this->row($job->id, 'dup-entry'), ['id' => 1, 'payload_hash' => 'hash-a']),
        );
        DB::table('cleanup_journal_imports_old')->insert(
            array_merge($this->row($job->id, 'dup-entry'), ['id' => 2, 'payload_hash' => 'hash-b']),
        );

        try {
            $this->rerunMigration();

            $this->fail('Duplicate entry ids must stop the reconciliation.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('distinct entry_id', $e->getMessage());
        }

        // NOTHING dropped, and hash-a survives.
        $this->assertTrue(Schema::hasTable('cleanup_journal_imports_old'));
        $this->assertSame(2, DB::table('cleanup_journal_imports_old')->count());
        $this->assertSame(
            ['hash-a', 'hash-b'],
            DB::table('cleanup_journal_imports_old')->orderBy('id')->pluck('payload_hash')->all(),
        );
        $this->assertSame(1, DB::table('cleanup_journal_imports')->count());
    }

    /** A duplicate in the LIVE table stops it too. */
    public function test_duplicate_entry_ids_in_the_live_table_fail_closed(): void
    {
        $job = $this->job();

        Schema::dropIfExists('cleanup_journal_imports');
        $this->createLedgerTable('cleanup_journal_imports', 'cleanup_journal_imports', false);

        DB::table('cleanup_journal_imports')->insert(
            array_merge($this->row($job->id, 'live-dup'), ['id' => 1, 'payload_hash' => 'hash-x']),
        );
        DB::table('cleanup_journal_imports')->insert(
            array_merge($this->row($job->id, 'live-dup'), ['id' => 2, 'payload_hash' => 'hash-y']),
        );

        $this->createLedgerTable('cleanup_journal_imports_old', 'cleanup_old_ix');
        DB::table('cleanup_journal_imports_old')->insert(
            array_merge($this->row($job->id, 'old-only'), ['id' => 3]),
        );

        try {
            $this->rerunMigration();

            $this->fail('A duplicate in the live table must stop the reconciliation.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('distinct entry_id', $e->getMessage());
        }

        $this->assertSame(2, DB::table('cleanup_journal_imports')->count());
        $this->assertTrue(Schema::hasTable('cleanup_journal_imports_old'));
    }

    /** And in `_rebuild`. */
    public function test_duplicate_entry_ids_in_the_rebuild_table_fail_closed(): void
    {
        $job = $this->job();

        Schema::dropIfExists('cleanup_journal_imports');
        $this->createLedgerTable('cleanup_journal_imports', 'cleanup_journal_imports');
        DB::table('cleanup_journal_imports')->insert(array_merge($this->row($job->id, 'live-one'), ['id' => 1]));

        $this->createLedgerTable('cleanup_journal_imports_rebuild', 'cleanup_rebuild_ix', false);
        DB::table('cleanup_journal_imports_rebuild')->insert(
            array_merge($this->row($job->id, 'rb-dup'), ['id' => 1, 'payload_hash' => 'h1']),
        );
        DB::table('cleanup_journal_imports_rebuild')->insert(
            array_merge($this->row($job->id, 'rb-dup'), ['id' => 2, 'payload_hash' => 'h2']),
        );

        try {
            $this->rerunMigration();

            $this->fail('A duplicate in the rebuild table must stop the reconciliation.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('distinct entry_id', $e->getMessage());
        }

        $this->assertSame(2, DB::table('cleanup_journal_imports_rebuild')->count());
    }

    /* ---------------- lossy canonicalisation must not drop rows */

    /** NULL and empty-string payload_hash are different persisted values. */
    public function test_null_versus_empty_payload_hash_is_not_a_match(): void
    {
        $job = $this->job();

        Schema::dropIfExists('cleanup_journal_imports');
        $this->createRelaxedLedgerTable('cleanup_journal_imports', 'cleanup_journal_imports');
        DB::table('cleanup_journal_imports')->insert(
            array_merge($this->row($job->id, 'hash-case'), ['id' => 1, 'payload_hash' => '']),
        );

        $this->createRelaxedLedgerTable('cleanup_journal_imports_old', 'cleanup_old_ix');
        DB::table('cleanup_journal_imports_old')->insert(
            array_merge($this->row($job->id, 'hash-case'), ['id' => 1, 'payload_hash' => null]),
        );

        /*
         * THE FIXTURE IS ASSERTED BEFORE THE MIGRATION RUNS.
         *
         * These tests previously built their state with `+`, which in PHP
         * keeps the LEFT operand — so the override never applied and the
         * corrupt value was never created. The test passed while proving
         * nothing.
         */
        /*
         * STRICT comparison, deliberately. `assertNotEquals` compares
         * loosely, and in PHP `'' == null` — so the very divergence this
         * test exists to create (empty string versus NULL) read as
         * "equal" and the fixture guard fired against a correct fixture.
         * The persisted values are asserted individually.
         */
        $this->assertSame('', DB::table('cleanup_journal_imports')->value('payload_hash'));
        $this->assertNull(DB::table('cleanup_journal_imports_old')->value('payload_hash'));

        try {
            $this->rerunMigration();

            $this->fail('NULL and empty payload_hash must not compare equal.');
        } catch (RuntimeException $e) {
            $this->assertNotSame('', $e->getMessage());
        }

        // Neither table touched.
        $this->assertTrue(Schema::hasTable('cleanup_journal_imports_old'));
        $this->assertNull(DB::table('cleanup_journal_imports_old')->value('payload_hash'));
    }

    /** NULL and zero orphaned_file_id are different too. */
    public function test_null_versus_zero_orphaned_file_id_is_not_a_match(): void
    {
        $job = $this->job();

        Schema::dropIfExists('cleanup_journal_imports');
        $this->createRelaxedLedgerTable('cleanup_journal_imports', 'cleanup_journal_imports');
        DB::table('cleanup_journal_imports')->insert(
            array_merge($this->row($job->id, 'target-case'), ['id' => 1, 'orphaned_file_id' => 0]),
        );

        $this->createRelaxedLedgerTable('cleanup_journal_imports_old', 'cleanup_old_ix');
        DB::table('cleanup_journal_imports_old')->insert(
            array_merge($this->row($job->id, 'target-case'), ['id' => 1, 'orphaned_file_id' => null]),
        );

        /*
         * THE FIXTURE IS ASSERTED BEFORE THE MIGRATION RUNS.
         *
         * These tests previously built their state with `+`, which in PHP
         * keeps the LEFT operand — so the override never applied and the
         * corrupt value was never created. The test passed while proving
         * nothing.
         */
        // Strict again: `0 == null` loosely, which is exactly the pair
        // under test.
        $this->assertSame(0, (int) DB::table('cleanup_journal_imports')->value('orphaned_file_id'));
        $this->assertNull(DB::table('cleanup_journal_imports_old')->value('orphaned_file_id'));

        try {
            $this->rerunMigration();

            $this->fail('NULL and zero orphaned_file_id must not compare equal.');
        } catch (RuntimeException $e) {
            $this->assertNotSame('', $e->getMessage());
        }

        $this->assertTrue(Schema::hasTable('cleanup_journal_imports_old'));
    }

    /** Rows differing only by timestamp are different rows. */
    public function test_differing_timestamps_block_the_drop(): void
    {
        $job = $this->job();

        Schema::dropIfExists('cleanup_journal_imports');
        $this->createRelaxedLedgerTable('cleanup_journal_imports', 'cleanup_journal_imports');
        DB::table('cleanup_journal_imports')->insert(
            array_merge($this->row($job->id, 'time-case'), ['id' => 1, 'imported_at' => '2026-01-01 00:00:00']),
        );

        $this->createRelaxedLedgerTable('cleanup_journal_imports_old', 'cleanup_old_ix');
        DB::table('cleanup_journal_imports_old')->insert(
            array_merge($this->row($job->id, 'time-case'), ['id' => 1, 'imported_at' => '2026-06-01 00:00:00']),
        );

        /*
         * THE FIXTURE IS ASSERTED BEFORE THE MIGRATION RUNS.
         *
         * These tests previously built their state with `+`, which in PHP
         * keeps the LEFT operand — so the override never applied and the
         * corrupt value was never created. The test passed while proving
         * nothing.
         */
        $this->assertNotEquals(
            DB::table('cleanup_journal_imports')->value('imported_at'),
            DB::table('cleanup_journal_imports_old')->value('imported_at'),
            'The fixture did not create the divergent imported_at this test needs.',
        );

        try {
            $this->rerunMigration();

            $this->fail('Rows differing by imported_at must not compare equal.');
        } catch (RuntimeException $e) {
            $this->assertNotSame('', $e->getMessage());
        }

        $this->assertTrue(Schema::hasTable('cleanup_journal_imports_old'));
        $this->assertSame(
            '2026-06-01 00:00:00',
            DB::table('cleanup_journal_imports_old')->value('imported_at'),
        );
    }

    /** A hash that is not a SHA-256 digest is refused. */
    public function test_a_malformed_payload_hash_is_refused(): void
    {
        $job = $this->job();

        // Relaxed, so a non-hex hash can physically exist.
        $this->createRelaxedLedgerTable('cleanup_journal_imports', 'cleanup_journal_imports');

        DB::table('cleanup_journal_imports')->insert(
            array_merge($this->row($job->id, 'bad-hash'), ['id' => 1, 'payload_hash' => 'not-a-hash']),
        );

        // The fixture really holds the malformed value.
        $this->assertSame('not-a-hash', DB::table('cleanup_journal_imports')->value('payload_hash'));

        try {
            $this->rerunMigration();

            $this->fail('A non-SHA-256 payload_hash must be refused.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('SHA-256', $e->getMessage());
        }

        // Untouched, so a person can correct it.
        $this->assertSame('not-a-hash', DB::table('cleanup_journal_imports')->value('payload_hash'));
    }

    /* ---------------- a same-named index elsewhere is not the contract */

    /**
     * The decoy reproduction: an index of the right NAME on the wrong table.
     *
     * A name-only check accepted it, so the real unique index was never
     * created and the old identity constraint was dropped anyway.
     */
    public function test_a_same_named_index_on_another_table_does_not_satisfy_the_contract(): void
    {
        DB::statement('CREATE TABLE decoy (id integer primary key, job_key varchar)');
        DB::statement('CREATE UNIQUE INDEX orphaned_files_job_key_unique_decoy ON decoy (job_key)');

        $this->assertFalse(
            SchemaContract::indexContractHolds(
                'orphaned_files', 'orphaned_files_job_key_unique_decoy', ['job_key'], true,
            ),
            'An index owned by another table must not satisfy the contract.',
        );

        DB::statement('DROP TABLE decoy');
    }

    /** Wrong column, wrong order and wrong uniqueness all fail the contract. */
    public function test_the_contract_rejects_wrong_columns_order_and_uniqueness(): void
    {
        DB::statement('CREATE TABLE shape (id integer primary key, a varchar, b varchar)');
        DB::statement('CREATE UNIQUE INDEX shape_ab ON shape (a, b)');
        DB::statement('CREATE INDEX shape_a ON shape (a)');

        $contract = SchemaContract::class;

        // Right name and table, wrong column set.
        $this->assertFalse($contract::indexContractHolds('shape', 'shape_ab', ['a'], true));

        // Right columns, wrong ORDER.
        $this->assertFalse($contract::indexContractHolds('shape', 'shape_ab', ['b', 'a'], true));

        // Right columns, wrong uniqueness.
        $this->assertFalse($contract::indexContractHolds('shape', 'shape_a', ['a'], true));

        // Exactly right.
        $this->assertTrue($contract::indexContractHolds('shape', 'shape_ab', ['a', 'b'], true));

        DB::statement('DROP TABLE shape');
    }

    /* ---------------- a partial index is not a full contract */

    /**
     * `CREATE UNIQUE INDEX ... WHERE job_key <> 'bypass'` enforces uniqueness
     * for every value EXCEPT the excluded one. Accepting it let a migration
     * drop the old identity index and then take two 'bypass' rows.
     */
    public function test_a_partial_unique_index_does_not_satisfy_the_contract(): void
    {
        DB::statement('DROP INDEX IF EXISTS orphaned_files_job_key_unique');
        DB::statement(
            'CREATE UNIQUE INDEX orphaned_files_job_key_unique ON orphaned_files (job_key) '
            ."WHERE job_key <> 'bypass'"
        );

        $this->assertFalse(
            SchemaContract::indexContractHolds(
                'orphaned_files', 'orphaned_files_job_key_unique', ['job_key'], true,
            ),
            'A partial index must not satisfy a full identity contract.',
        );

        // And SQLite really does report it as partial, so the check is real.
        $index = collect(DB::select("PRAGMA index_list('orphaned_files')"))
            ->first(static fn ($row): bool => $row->name === 'orphaned_files_job_key_unique');

        $this->assertSame(1, (int) $index->partial);
    }

    /** The same rule for active_key and for the ledger's entry_id. */
    public function test_partial_indexes_are_rejected_for_every_identity_column(): void
    {
        $contract = SchemaContract::class;

        DB::statement('DROP INDEX IF EXISTS orphaned_files_active_key_unique');
        DB::statement(
            'CREATE UNIQUE INDEX orphaned_files_active_key_unique ON orphaned_files (active_key) '
            .'WHERE active_key IS NOT NULL'
        );

        $this->assertFalse($contract::indexContractHolds(
            'orphaned_files', 'orphaned_files_active_key_unique', ['active_key'], true,
        ));

        DB::statement('DROP INDEX IF EXISTS cleanup_journal_imports_entry_id_unique');
        DB::statement(
            'CREATE UNIQUE INDEX cleanup_journal_imports_entry_id_unique '
            ."ON cleanup_journal_imports (entry_id) WHERE entry_id <> 'skip'"
        );

        $this->assertFalse($contract::indexContractHolds(
            'cleanup_journal_imports', 'cleanup_journal_imports_entry_id_unique', ['entry_id'], true,
        ));
    }

    /** A full index still satisfies the contract. */
    public function test_a_full_unique_index_satisfies_the_contract(): void
    {
        $this->assertTrue(
            SchemaContract::indexContractHolds(
                'cleanup_journal_imports',
                'cleanup_journal_imports_entry_id_unique',
                ['entry_id'],
                true,
            ),
        );
    }

    /* ------------------------------------------------------------ helpers */

    /** Run the real migration's up() through a fresh instance. */
    private function rerunMigration(): void
    {
        $path = base_path('app/Modules/Projects/Database/Migrations/'.self::MIGRATION.'.php');

        $this->assertFileExists($path);

        $migration = require $path;

        $migration->up();
    }

    private function job(): OrphanedFile
    {
        return OrphanedFile::record('public', 'projects/1/recovery.jpg', 'promotion_rollback');
    }

    /** @return array<string, mixed> */
    private function row(int $jobId, string $entryId): array
    {
        return [
            'entry_id' => $entryId,
            'orphaned_file_id' => $jobId,
            'payload_hash' => hash('sha256', $entryId),
            'imported_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ];
    }

    /**
     * A deliberately RELAXED ledger table, for building corrupt states.
     *
     * The strict fixture declares payload_hash and orphaned_file_id NOT NULL
     * and carries the foreign key, so the null/zero states these tests exist
     * to exercise failed during setup — before the migration ever ran. The
     * corruption being tested is precisely a table that never had those
     * constraints.
     */
    private function createRelaxedLedgerTable(string $table, string $indexPrefix, bool $unique = true): void
    {
        Schema::dropIfExists($table);

        DB::statement(
            "CREATE TABLE {$table} ("
            .'id integer primary key autoincrement not null, '
            .'entry_id varchar not null, '
            // Nullable, and no foreign key: exactly the shape a partially
            // migrated or hand-repaired database can be left in.
            .'orphaned_file_id integer null, '
            .'payload_hash varchar null, '
            .'imported_at datetime null, '
            .'created_at datetime null, '
            .'updated_at datetime null)'
        );

        if ($unique) {
            DB::statement("CREATE UNIQUE INDEX {$indexPrefix}_entry_id_unique ON {$table} (entry_id)");
        }

        DB::statement("CREATE INDEX {$indexPrefix}_orphaned_file_id_index ON {$table} (orphaned_file_id)");
    }

    /** Build a ledger table with a chosen index-name prefix. */
    private function createLedgerTable(string $table, string $indexPrefix, bool $unique = true): void
    {
        Schema::dropIfExists($table);

        DB::statement(
            "CREATE TABLE {$table} ("
            .'id integer primary key autoincrement not null, '
            .'entry_id varchar not null, '
            .'orphaned_file_id integer not null, '
            .'payload_hash varchar not null, '
            .'imported_at datetime not null, '
            .'created_at datetime null, '
            .'updated_at datetime null, '
            .'foreign key(orphaned_file_id) references orphaned_files(id) on delete restrict)'
        );

        if ($unique) {
            DB::statement("CREATE UNIQUE INDEX {$indexPrefix}_entry_id_unique ON {$table} (entry_id)");
        }
        DB::statement("CREATE INDEX {$indexPrefix}_orphaned_file_id_index ON {$table} (orphaned_file_id)");
    }
}
