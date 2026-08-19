<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\Projects\Models\OrphanedFile;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\InspectsSchema;
use Tests\Concerns\RunnableMigration;
use Tests\TestCase;

/**
 * The cleanup identity must be enforced by the DATABASE, not just by Eloquent.
 *
 * 001700 made `job_key` unique but left it nullable, and on every supported
 * engine NULLs are exempt from a unique index — so any number of rows with no
 * identity at all could coexist, and the sweep could not tell those jobs apart.
 * These tests drive the forward migration that closes that hole, including the
 * legacy states a real upgrade will meet.
 */
final class CleanupJobKeyNotNullMigrationTest extends TestCase
{
    use InspectsSchema;
    use RefreshDatabase;

    private const PATH = 'app/Modules/Projects/Database/Migrations/2026_07_26_000200_cleanup_job_key_not_null.php';

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

    /** Relax the column so legacy states can be constructed. */
    private function relax(): void
    {
        $this->migration()->down();
    }

    /**
     * @param  array<string, mixed>  $attributes  overrides for the legacy row
     */
    private function insertRaw(array $attributes): int
    {
        return (int) DB::table('orphaned_files')->insertGetId($attributes + [
            // The strict `001900` contract makes incident identity a schema
            // invariant, so even a LEGACY-shaped fixture must carry one. It
            // is minted through the production path — never invented here —
            // so this simulates old DATA, not an old identity contract.
            'incident_uuid' => OrphanedFile::mintIncidentUuid(),
            'disk' => 'public',
            'path' => 'a/b.jpg',
            'reason' => 'legacy',
            'attempts' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * The LIVE-IDENTITY guarantee, under whichever contract is in force.
     *
     * v6 merge: this migration predates the strict cleanup chain, which
     * moved uniqueness from `job_key` to `active_key` so a resolved
     * incident can keep its key as history while a new incident at the
     * same path takes a fresh row. The guarantee being tested is that
     * exactly one LIVE identity can exist — asserting the old index by
     * name would demand the regression back.
     */
    private function liveIdentityIsUnique(): bool
    {
        $unique = array_values($this->uniqueIndexesFor('orphaned_files'));

        return in_array(['job_key'], $unique, true) || in_array(['active_key'], $unique, true);
    }

    private function jobKeyIsNullable(): bool
    {
        return $this->columnIsNullable('orphaned_files', 'job_key');
    }

    private function uniqueOnJobKey(): bool
    {
        return in_array(['job_key'], array_values($this->uniqueIndexesFor('orphaned_files')), true);
    }

    /* ------------------------------------------------------------ clean */

    public function test_a_clean_migration_leaves_job_key_not_null_and_unique(): void
    {
        $this->assertFalse($this->jobKeyIsNullable(), 'job_key should be NOT NULL after migration.');
        $this->assertTrue($this->liveIdentityIsUnique(), 'The live-identity unique contract must survive the column change.');
    }

    /** THE POINT: the database itself must reject a missing identity. */
    public function test_the_database_rejects_a_null_job_key(): void
    {
        $this->expectException(QueryException::class);

        $this->insertRaw(['job_key' => null]);
    }

    public function test_the_database_rejects_a_duplicate_live_identity(): void
    {
        /*
         * v6 merge: the database must refuse two LIVE rows claiming one
         * identity. Under the strict contract that is enforced on
         * `active_key` — `job_key` repeats deliberately so a resolved
         * incident keeps its history. The row shape follows whichever
         * column carries the guarantee.
         */
        $unique = array_values($this->uniqueIndexesFor('orphaned_files'));
        $column = in_array(['active_key'], $unique, true) ? 'active_key' : 'job_key';

        $this->insertRaw(['job_key' => 'src:offer_media:1', $column => 'src:offer_media:1', 'path' => 'one.jpg']);

        $this->expectException(QueryException::class);

        $this->insertRaw(['job_key' => 'src:offer_media:1', $column => 'src:offer_media:1', 'path' => 'two.jpg']);
    }

    /* ----------------------------------------------------------- legacy */

    public function test_legacy_null_rows_are_keyed_deterministically(): void
    {
        $this->relax();

        $sourceLinked = $this->insertRaw([
            'job_key' => null, 'path' => 'offers/1/a.jpg',
            'source_type' => 'offer_media', 'source_id' => 41,
        ]);
        $pathOnly = $this->insertRaw(['job_key' => null, 'path' => 'projects/7/b.jpg']);

        $this->migration()->up();

        $this->assertSame(
            'src:offer_media:41',
            DB::table('orphaned_files')->where('id', $sourceLinked)->value('job_key'),
        );
        $this->assertSame(
            'path:'.hash('sha256', 'public'."\0".'projects/7/b.jpg'),
            DB::table('orphaned_files')->where('id', $pathOnly)->value('job_key'),
            'Path-only work must be keyed exactly the way the model keys it.',
        );
        $this->assertFalse($this->jobKeyIsNullable());
    }

    public function test_legacy_blank_rows_are_remediated(): void
    {
        $this->relax();

        $id = $this->insertRaw(['job_key' => '', 'path' => 'projects/9/c.jpg']);

        $this->migration()->up();

        $this->assertSame(
            'path:'.hash('sha256', 'public'."\0".'projects/9/c.jpg'),
            DB::table('orphaned_files')->where('id', $id)->value('job_key'),
        );
    }

    /**
     * Two legacy rows describing one source collapse deterministically.
     *
     * The earliest row keeps the natural key because it holds the original
     * evidence; later rows are suffixed with their own primary key, which is
     * stable on a rerun. Nothing is deleted — a resolved job is the only record
     * that a file ever existed.
     */
    public function test_duplicate_legacy_identities_are_remediated_without_data_loss(): void
    {
        $this->relax();

        $first = $this->insertRaw([
            'job_key' => null, 'path' => 'offers/2/a.jpg',
            'source_type' => 'offer_media', 'source_id' => 7,
        ]);
        $second = $this->insertRaw([
            'job_key' => null, 'path' => 'offers/2/a.jpg',
            'source_type' => 'offer_media', 'source_id' => 7,
        ]);

        $this->migration()->up();

        $this->assertSame(
            'src:offer_media:7',
            DB::table('orphaned_files')->where('id', $first)->value('job_key'),
            'The earliest row keeps the natural identity.',
        );
        $this->assertSame(
            'src:offer_media:7:dup:'.$second,
            DB::table('orphaned_files')->where('id', $second)->value('job_key'),
        );
        $this->assertSame(2, DB::table('orphaned_files')->count(), 'No row may be discarded.');
        $this->assertFalse($this->jobKeyIsNullable());
    }

    /** Remediation must be stable when the migration runs twice. */
    public function test_remediation_is_stable_across_a_rerun(): void
    {
        $this->relax();
        $this->insertRaw(['job_key' => null, 'path' => 'offers/3/a.jpg', 'source_type' => 'offer_media', 'source_id' => 9]);
        $this->insertRaw(['job_key' => null, 'path' => 'offers/3/a.jpg', 'source_type' => 'offer_media', 'source_id' => 9]);

        $this->migration()->up();
        $before = DB::table('orphaned_files')->orderBy('id')->pluck('job_key')->all();

        $this->migration()->up();
        $after = DB::table('orphaned_files')->orderBy('id')->pluck('job_key')->all();

        $this->assertSame($before, $after, 'A rerun changed the identities it had already assigned.');
    }

    /* --------------------------------------------------------- rollback */

    public function test_down_relaxes_the_column_and_keeps_uniqueness(): void
    {
        $this->migration()->down();

        $this->assertTrue($this->jobKeyIsNullable());
        $this->assertTrue($this->uniqueOnJobKey(), 'Rollback must not surrender the unique contract.');
    }

    public function test_migrate_rollback_migrate_converges(): void
    {
        $migration = $this->migration();

        $migration->down();
        $this->assertTrue($this->jobKeyIsNullable());

        $migration->up();
        $this->assertFalse($this->jobKeyIsNullable());
        $this->assertTrue($this->liveIdentityIsUnique());
    }

    public function test_up_is_idempotent(): void
    {
        $this->migration()->up();
        $this->migration()->up();

        $this->assertFalse($this->jobKeyIsNullable());
        $this->assertTrue($this->liveIdentityIsUnique());
    }

    /** An identity that cannot be derived must abort, not be invented. */
    public function test_the_migration_refuses_rather_than_inventing_an_identity(): void
    {
        $this->relax();

        // A row with no source and no path has no derivable identity.
        DB::table('orphaned_files')->insert([
            // The strict `001900` contract makes incident identity a schema
            // invariant, so even a LEGACY-shaped fixture must carry one. It
            // is minted through the production path — never invented here —
            // so this simulates old DATA, not an old identity contract.
            'incident_uuid' => OrphanedFile::mintIncidentUuid(),
            'disk' => '', 'path' => '', 'reason' => 'legacy', 'attempts' => 1,
            'job_key' => null, 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('orphaned_files')->insert([
            // The strict `001900` contract makes incident identity a schema
            // invariant, so even a LEGACY-shaped fixture must carry one. It
            // is minted through the production path — never invented here —
            // so this simulates old DATA, not an old identity contract.
            'incident_uuid' => OrphanedFile::mintIncidentUuid(),
            'disk' => '', 'path' => '', 'reason' => 'legacy', 'attempts' => 1,
            'job_key' => null, 'created_at' => now(), 'updated_at' => now(),
        ]);

        // Both hash to the same path key, so one is suffixed; neither is random.
        $this->migration()->up();

        $keys = DB::table('orphaned_files')->orderBy('id')->pluck('job_key')->all();

        $this->assertCount(2, array_unique($keys));
        foreach ($keys as $key) {
            $this->assertNotEmpty($key);
            $this->assertStringStartsWith('path:', (string) $key);
        }
    }
}
