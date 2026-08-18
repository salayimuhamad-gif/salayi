<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\Projects\Models\OrphanedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Tests\TestCase;

/**
 * The same ledger-recovery defects, proved on MySQL 8.
 *
 * `LedgerRecoveryTest` builds its interrupted states with SQLite DDL and reads
 * them back with PRAGMA, so it cannot run here — and letting the MySQL matrix
 * job hit that SQL meant the migration was never actually proved on the engine
 * production uses.
 *
 * Skipping MySQL entirely would have been the easier fix and the wrong one:
 * the data-loss cases in issues 1 and 2 are engine-independent, so they are
 * covered on both, each with the DDL and introspection that engine
 * understands.
 */
final class LedgerRecoveryMysqlTest extends TestCase
{
    private const MIGRATION = '2026_07_25_002000_reconcile_cleanup_ledger_schema';

    protected function setUp(): void
    {
        parent::setUp();

        if (! in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true)) {
            $this->markTestSkipped(
                'Covered for SQLite by LedgerRecoveryTest; this class needs MySQL DDL.'
            );
        }

        $this->artisan('migrate:fresh', ['--force' => true])->assertSuccessful();
    }

    protected function tearDown(): void
    {
        foreach ([
            'cleanup_journal_imports_old',
            'cleanup_journal_imports_rebuild',
        ] as $table) {
            Schema::dropIfExists($table);
        }

        parent::tearDown();
    }

    /** Divergent tables must not be reconciled by guessing. */
    public function test_it_refuses_to_reconcile_divergent_tables(): void
    {
        $job = $this->job();

        DB::table('cleanup_journal_imports')->insert($this->row($job->id, 'live-entry', 2));

        $this->createLedgerTable('cleanup_journal_imports_old');
        DB::table('cleanup_journal_imports_old')->insert($this->row($job->id, 'old-entry', 1));

        try {
            $this->rerunMigration();

            $this->fail('Divergent ledgers must not be silently reconciled.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('Neither is a subset', $e->getMessage());
        }

        // Both survive for manual reconciliation.
        $this->assertSame('live-entry', DB::table('cleanup_journal_imports')->value('entry_id'));
        $this->assertSame('old-entry', DB::table('cleanup_journal_imports_old')->value('entry_id'));
    }

    /**
     * A remnant holding duplicate entry ids must stop the reconciliation.
     *
     * Keying by entry_id collapsed the duplicates, after which the remnant
     * looked like a subset and was dropped — taking a physical row with it.
     */
    public function test_duplicate_entry_ids_in_a_remnant_fail_closed(): void
    {
        $job = $this->job();

        DB::table('cleanup_journal_imports')->insert($this->row($job->id, 'dup-entry', 2, 'hash-b'));

        // No unique index, so duplicates are physically possible here.
        $this->createLedgerTable('cleanup_journal_imports_old', false);

        DB::table('cleanup_journal_imports_old')->insert($this->row($job->id, 'dup-entry', 1, 'hash-a'));
        DB::table('cleanup_journal_imports_old')->insert($this->row($job->id, 'dup-entry', 2, 'hash-b'));

        try {
            $this->rerunMigration();

            $this->fail('Duplicate entry ids must fail closed.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('distinct entry_id', $e->getMessage());
        }

        // NOTHING dropped: both physical rows still there, hash-a included.
        $this->assertSame(2, DB::table('cleanup_journal_imports_old')->count());
        $this->assertSame(
            ['hash-a', 'hash-b'],
            DB::table('cleanup_journal_imports_old')->orderBy('id')->pluck('payload_hash')->all(),
        );
    }

    /** The final ledger carries orphaned_files(id) ON DELETE RESTRICT. */
    public function test_the_final_ledger_carries_the_restrict_foreign_key(): void
    {
        $this->job();

        $rule = DB::table('information_schema.KEY_COLUMN_USAGE as k')
            ->join('information_schema.REFERENTIAL_CONSTRAINTS as r', function ($join): void {
                $join->on('r.CONSTRAINT_NAME', '=', 'k.CONSTRAINT_NAME')
                    ->on('r.CONSTRAINT_SCHEMA', '=', 'k.TABLE_SCHEMA');
            })
            ->where('k.TABLE_SCHEMA', DB::connection()->getDatabaseName())
            ->where('k.TABLE_NAME', 'cleanup_journal_imports')
            ->where('k.COLUMN_NAME', 'orphaned_file_id')
            ->select('k.REFERENCED_TABLE_NAME', 'k.REFERENCED_COLUMN_NAME', 'r.DELETE_RULE')
            ->first();

        $this->assertNotNull($rule, 'The ledger has no foreign key on orphaned_file_id.');
        $this->assertSame('orphaned_files', $rule->REFERENCED_TABLE_NAME);
        $this->assertSame('id', $rule->REFERENCED_COLUMN_NAME);
        $this->assertSame('RESTRICT', strtoupper((string) $rule->DELETE_RULE));
    }

    /** Both final indexes exist on the live table. */
    public function test_both_final_indexes_exist(): void
    {
        $this->job();

        $indexes = DB::table('information_schema.STATISTICS')
            ->where('TABLE_SCHEMA', DB::connection()->getDatabaseName())
            ->where('TABLE_NAME', 'cleanup_journal_imports')
            ->pluck('INDEX_NAME');

        $this->assertContains('cleanup_journal_imports_entry_id_unique', $indexes);
        $this->assertContains('cleanup_journal_imports_orphaned_file_id_index', $indexes);
    }

    /** Rerunning changes nothing. */
    public function test_the_migration_is_idempotent(): void
    {
        $job = $this->job();

        DB::table('cleanup_journal_imports')->insert($this->row($job->id, 'idem', 1));

        $this->rerunMigration();
        $before = DB::table('cleanup_journal_imports')->get()->toArray();

        $this->rerunMigration();

        $this->assertEquals($before, DB::table('cleanup_journal_imports')->get()->toArray());
        $this->assertFalse(Schema::hasTable('cleanup_journal_imports_rebuild'));
    }

    /** NULL and empty payload_hash are different persisted values. */
    public function test_null_versus_empty_payload_hash_is_not_a_match(): void
    {
        $job = $this->job();

        $this->createRelaxedLedgerTable('cleanup_journal_imports_old');

        DB::table('cleanup_journal_imports')->insert(
            array_merge($this->row($job->id, 'hash-case', 1), ['payload_hash' => '']),
        );
        DB::table('cleanup_journal_imports_old')->insert(
            array_merge($this->row($job->id, 'hash-case', 1), ['payload_hash' => null]),
        );

        // The fixture really is divergent before the migration runs.
        $this->assertSame('', DB::table('cleanup_journal_imports')->value('payload_hash'));
        $this->assertNull(DB::table('cleanup_journal_imports_old')->value('payload_hash'));

        try {
            $this->rerunMigration();

            $this->fail('NULL and empty payload_hash must not compare equal.');
        } catch (RuntimeException $e) {
            $this->assertNotSame('', $e->getMessage());
        }

        $this->assertTrue(Schema::hasTable('cleanup_journal_imports_old'));
    }

    /** A hash that is not a SHA-256 digest is refused on MySQL too. */
    public function test_a_malformed_payload_hash_is_refused(): void
    {
        $job = $this->job();

        DB::table('cleanup_journal_imports')->insert(
            array_merge($this->row($job->id, 'bad-hash', 1), ['payload_hash' => 'not-a-hash']),
        );

        $this->assertSame('not-a-hash', DB::table('cleanup_journal_imports')->value('payload_hash'));

        try {
            $this->rerunMigration();

            $this->fail('A non-SHA-256 payload_hash must be refused.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('SHA-256', $e->getMessage());
        }
    }

    /**
     * A relaxed table, for states a strict fixture cannot hold.
     *
     * NOT NULL columns and a foreign key make the corrupt states these tests
     * exercise impossible to create — the setup fails before the migration
     * runs, which is not the same as the migration being safe.
     */
    private function createRelaxedLedgerTable(string $table): void
    {
        Schema::dropIfExists($table);

        DB::statement(
            "CREATE TABLE {$table} ("
            .'id bigint unsigned not null primary key, '
            .'entry_id varchar(64) not null, '
            .'orphaned_file_id bigint unsigned null, '
            .'payload_hash varchar(64) null, '
            .'imported_at timestamp null, '
            .'created_at timestamp null, '
            .'updated_at timestamp null'
            .') engine=InnoDB default charset=utf8mb4'
        );

        DB::statement("CREATE UNIQUE INDEX {$table}_entry_id_unique ON {$table} (entry_id)");
    }

    /* ------------------------------------------------------------ helpers */

    private function rerunMigration(): void
    {
        $path = base_path('app/Modules/Projects/Database/Migrations/'.self::MIGRATION.'.php');

        $this->assertFileExists($path);

        $migration = require $path;

        $migration->up();
    }

    private function job(): OrphanedFile
    {
        return OrphanedFile::record('public', 'projects/1/mysql-recovery.jpg', 'promotion_rollback');
    }

    /** @return array<string, mixed> */
    private function row(int $jobId, string $entryId, int $id, ?string $hash = null): array
    {
        return [
            'id' => $id,
            'entry_id' => $entryId,
            'orphaned_file_id' => $jobId,
            'payload_hash' => $hash ?? hash('sha256', $entryId),
            'imported_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ];
    }

    /** MySQL DDL, optionally without the unique index so duplicates can exist. */
    private function createLedgerTable(string $table, bool $unique = true): void
    {
        Schema::dropIfExists($table);

        DB::statement(
            "CREATE TABLE {$table} ("
            .'id bigint unsigned not null primary key, '
            .'entry_id varchar(64) not null, '
            .'orphaned_file_id bigint unsigned not null, '
            .'payload_hash varchar(64) not null, '
            .'imported_at timestamp not null, '
            .'created_at timestamp null, '
            .'updated_at timestamp null'
            .') engine=InnoDB default charset=utf8mb4'
        );

        if ($unique) {
            DB::statement("CREATE UNIQUE INDEX {$table}_entry_id_unique ON {$table} (entry_id)");
        }

        DB::statement("CREATE INDEX {$table}_orphaned_file_id_index ON {$table} (orphaned_file_id)");
    }
}
