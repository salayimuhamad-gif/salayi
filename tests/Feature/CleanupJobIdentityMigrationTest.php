<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\Projects\Models\OrphanedFile;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Tests\Concerns\InspectsSchema;
use Tests\Concerns\RunnableMigration;
use Tests\TestCase;

/**
 * Migration 001700 — `job_key` cleanup identity, forwards and backwards.
 *
 * These execute the real migration against a real database. The previous
 * rollback was a guaranteed ArgumentCountError (a one-argument helper called
 * with three arguments at four sites) sitting on top of an ordering bug that
 * dropped the new contract before checking whether the old one could be
 * restored. Neither was caught, because nothing ever ran `down()`.
 */
final class CleanupJobIdentityMigrationTest extends TestCase
{
    use InspectsSchema;
    use RefreshDatabase;

    private const PATH = 'app/Modules/Projects/Database/Migrations/2026_07_25_001700_cleanup_job_identity.php';

    /** A fresh instance each time: `down()` must not depend on `up()` state in memory. */
    /**
     * The base Migration class declares neither up() nor down() — the
     * migrator calls them dynamically — so the intersection describes the
     * object honestly for static analysis without asserting it at runtime,
     * where the anonymous class does not implement the interface.
     *
     * @return Migration&RunnableMigration
     */
    private function migration(): Migration
    {
        /** @var Migration&RunnableMigration $migration */
        $migration = require base_path(self::PATH);

        return $migration;
    }

    /**
     * Engine-neutral introspection.
     *
     * These asked SQLite directly through PRAGMA, so the suite could not run
     * against MySQL at all — the engine this platform deploys to, and the one
     * that turned out to be rejecting two of its own identifiers.
     *
     * @return list<list<string>> every unique index on orphaned_files, as column lists
     */
    private function uniqueSets(): array
    {
        return array_values($this->uniqueIndexesFor('orphaned_files'));
    }

    /** @return list<string> */
    private function indexNames(): array
    {
        return array_keys($this->uniqueIndexesFor('orphaned_files'));
    }

    private function seedJob(string $disk, string $path, string $jobKey): int
    {
        return (int) DB::table('orphaned_files')->insertGetId([
            // The strict `001900` contract makes incident identity a schema
            // invariant, so even a LEGACY-shaped fixture must carry one. It
            // is minted through the production path — never invented here —
            // so this simulates old DATA, not an old identity contract.
            'incident_uuid' => OrphanedFile::mintIncidentUuid(),
            'disk' => $disk,
            'path' => $path,
            'reason' => 'test',
            'attempts' => 1,
            'job_key' => $jobKey,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /* ------------------------------------------------------------------ up */

    public function test_up_leaves_the_job_key_contract_in_place(): void
    {
        // The suite's migrations have already applied 001700.
        $this->assertTrue(Schema::hasColumn('orphaned_files', 'job_key'));
        /*
         * v6 merge: 001700 introduces the identity and its unique index,
         * but the strict 001900 that follows MOVES live-identity
         * uniqueness to `active_key`, so a resolved incident keeps its
         * `job_key` as history. After the full chain the old index is
         * legitimately absent; what must remain is the column plus exactly
         * one live-identity guarantee — and never a disk/path unique,
         * which is the constraint 001700 exists to remove.
         */
        $unique = $this->uniqueSets();
        $this->assertTrue(
            in_array(['job_key'], $unique, true) || in_array(['active_key'], $unique, true),
            'no live-identity unique contract is in force after the chain',
        );

        $this->assertNotContains(['disk', 'path'], $this->uniqueSets());
    }

    public function test_up_is_idempotent(): void
    {
        $this->migration()->up();

        $this->assertContains(['job_key'], $this->uniqueSets());
        $this->assertNotContains(['disk', 'path'], $this->uniqueSets());
    }

    /* ---------------------------------------------------------------- down */

    public function test_down_restores_the_previous_contract_exactly(): void
    {
        $this->migration()->down();

        $this->assertFalse(Schema::hasColumn('orphaned_files', 'job_key'));
        $this->assertNotContains('orphaned_files_job_key_unique', $this->indexNames());
        $this->assertContains('orphaned_files_disk_path_unique', $this->indexNames());
        $this->assertContains(['disk', 'path'], $this->uniqueSets());
        $this->assertNotContains(['job_key'], $this->uniqueSets());
    }

    public function test_up_down_up_returns_to_the_new_contract(): void
    {
        $this->migration()->down();
        $this->assertFalse(Schema::hasColumn('orphaned_files', 'job_key'));

        $this->migration()->up();

        $this->assertTrue(Schema::hasColumn('orphaned_files', 'job_key'));
        $this->assertContains(['job_key'], $this->uniqueSets());
        $this->assertNotContains(['disk', 'path'], $this->uniqueSets());
    }

    public function test_down_backfills_nothing_it_cannot_and_preserves_rows(): void
    {
        $this->seedJob('public', 'a/one.jpg', 'src:offer_media:1');
        $this->seedJob('public', 'a/two.jpg', 'src:offer_media:2');

        $this->migration()->down();

        $this->assertSame(2, (int) DB::table('orphaned_files')->count());
    }

    /* ------------------------------------------- preflight before mutation */

    /**
     * THE ORDERING DEFECT.
     *
     * Duplicate (disk, path) pairs make the old contract impossible. The
     * rollback must discover that while the table is still fully protected,
     * not after it has already destroyed the new identity.
     */
    public function test_rollback_with_duplicate_disk_paths_fails_before_changing_anything(): void
    {
        $this->seedJob('public', 'offers/1/reused.jpg', 'src:offer_media:1');
        $this->seedJob('public', 'offers/1/reused.jpg', 'src:offer_media:2');

        // The exact shape to compare against after the refusal.
        $before = $this->uniqueSets();

        try {
            $this->migration()->down();
            $this->fail('Rollback should have refused while duplicate disk/path pairs exist.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('Refusing to reverse', $e->getMessage());
            /*
             * v6 merge: the strict branch's 001700 is authoritative for
             * cleanup/rollback code, and it phrases the same guarantee as
             * "No schema change has been made." Asserting the exact older
             * sentence would test wording, not behaviour, so the promise
             * itself is asserted below by inspecting the schema.
             */
            $this->assertStringContainsString('No schema change has been made', $e->getMessage());
            // Actionable: names the offending pair and how many jobs it carries.
            /*
             * v6 merge: the strict 001700 is authoritative for cleanup
             * rollback and states the refusal as a COUNT of offending
             * pairs rather than enumerating them. The substance — how many
             * pairs blocked the reversal, and that nothing was changed —
             * is asserted; the enumeration wording is not a contract.
             */
            $this->assertStringContainsString('1 disk/path pair(s)', $e->getMessage());
        }

        /*
         * NOTHING may have changed — asserted against the schema as it
         * stood BEFORE the refusal rather than against the pre-strict
         * index names, since after the strict chain live-identity
         * uniqueness lives on `active_key`.
         */
        $this->assertTrue(Schema::hasColumn('orphaned_files', 'job_key'));
        $this->assertSame($before, $this->uniqueSets(), 'the refusal changed the schema');
        $this->assertNotContains(['disk', 'path'], $this->uniqueSets());
    }

    /* --------------------------------------------------- interrupted states */

    public function test_down_converges_when_the_old_index_is_already_restored(): void
    {
        Schema::table('orphaned_files', function ($table): void {
            $table->unique(['disk', 'path'], 'orphaned_files_disk_path_unique');
        });

        $this->migration()->down();

        $this->assertFalse(Schema::hasColumn('orphaned_files', 'job_key'));
        $this->assertContains(['disk', 'path'], $this->uniqueSets());
    }

    public function test_down_converges_when_the_new_index_is_already_removed(): void
    {
        /*
         * v6 merge: after the strict chain the job_key unique index is
         * ALREADY absent — 001900 removes it so a resolved incident can
         * keep its key. Dropping it unconditionally therefore fails on
         * "no such index" before the scenario even begins. The state this
         * test needs is "the index is not there", which is asserted rather
         * than forced.
         */
        if (in_array(['job_key'], $this->uniqueSets(), true)) {
            Schema::table('orphaned_files', function ($table): void {
                $table->dropUnique('orphaned_files_job_key_unique');
            });
        }

        $this->assertNotContains(['job_key'], $this->uniqueSets());

        $this->migration()->down();

        $this->assertFalse(Schema::hasColumn('orphaned_files', 'job_key'));
        $this->assertContains(['disk', 'path'], $this->uniqueSets());
        $this->assertNotContains(['job_key'], $this->uniqueSets());
    }

    public function test_down_converges_when_the_column_is_already_removed(): void
    {
        /*
         * v6 merge: after the strict chain the job_key unique index is
         * ALREADY absent — 001900 removes it so a resolved incident can
         * keep its key. Dropping it unconditionally therefore fails on
         * "no such index" before the scenario even begins. The state this
         * test needs is "the index is not there", which is asserted rather
         * than forced.
         */
        if (in_array(['job_key'], $this->uniqueSets(), true)) {
            Schema::table('orphaned_files', function ($table): void {
                $table->dropUnique('orphaned_files_job_key_unique');
            });
        }

        $this->assertNotContains(['job_key'], $this->uniqueSets());
        Schema::table('orphaned_files', function ($table): void {
            $table->dropColumn('job_key');
        });

        $this->migration()->down();

        $this->assertFalse(Schema::hasColumn('orphaned_files', 'job_key'));
        $this->assertContains(['disk', 'path'], $this->uniqueSets());
    }

    public function test_down_run_twice_is_safe(): void
    {
        $this->migration()->down();
        $this->migration()->down();

        $this->assertFalse(Schema::hasColumn('orphaned_files', 'job_key'));
        $this->assertContains(['disk', 'path'], $this->uniqueSets());
    }

    /* -------------------------------------------------- exact index naming */

    public function test_the_restored_index_carries_its_explicit_name(): void
    {
        $this->migration()->down();

        $this->assertContains('orphaned_files_disk_path_unique', $this->indexNames());
    }
}
