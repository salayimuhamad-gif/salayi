<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\Core\Support\MigrationIndexes;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Concerns\InspectsSchema;
use Tests\Concerns\RunnableMigration;
use Tests\TestCase;

/**
 * The digest migration must converge from EVERY partial state.
 *
 * Two guards have been wrong here in turn: one tested a column another
 * migration creates, so this migration never ran anywhere; its replacement
 * still let a single component stand proxy for the whole contract, so a
 * database missing only the preferences table skipped it forever, and one
 * missing only the columns hit `Schema::create()` on an existing table.
 *
 * Each test below deliberately constructs a partial state and then reruns the
 * migration, asserting the same final contract every time.
 */
final class NotificationDigestMigrationTest extends TestCase
{
    use InspectsSchema;
    use RefreshDatabase;

    private const PATH = 'app/Modules/Notifications/Database/Migrations/2026_07_01_000300_add_notification_digest.php';

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

    private function indexExists(): bool
    {
        // Engine-neutral: this contract must hold on MySQL as well as SQLite.
        return $this->hasNamedIndex('notifications', 'notifications_digest_idx');
    }

    private function assertFinalContract(string $context): void
    {
        $this->assertTrue(Schema::hasTable('notification_preferences'), "{$context}: preferences table missing.");
        $this->assertTrue(Schema::hasColumn('notifications', 'digest_state'), "{$context}: digest_state missing.");
        $this->assertTrue(Schema::hasColumn('notifications', 'digest_sent_at'), "{$context}: digest_sent_at missing.");
        $this->assertTrue($this->indexExists(), "{$context}: digest index missing.");
    }

    /**
     * Remove the digest columns without touching anything else.
     *
     * @param  list<string>  $columns
     */
    private function dropDigestColumns(array $columns): void
    {
        MigrationIndexes::dropIndexesOn('notifications', $columns);

        Schema::table('notifications', function ($table) use ($columns): void {
            foreach ($columns as $column) {
                if (Schema::hasColumn('notifications', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }

    /* ------------------------------------------------------------ states */

    /** 1. Nothing but the prerequisites. */
    public function test_it_reconciles_from_prerequisites_only(): void
    {
        $this->migration()->down();
        $this->dropDigestColumns(['digest_state', 'digest_sent_at']);
        Schema::dropIfExists('notification_preferences');

        $this->migration()->up();

        $this->assertFinalContract('from prerequisites only');
    }

    /** 2. Preferences table exists; columns and index absent. */
    public function test_it_reconciles_when_only_the_preferences_table_exists(): void
    {
        $this->dropDigestColumns(['digest_state', 'digest_sent_at']);

        $this->assertTrue(Schema::hasTable('notification_preferences'));
        $this->assertFalse(Schema::hasColumn('notifications', 'digest_state'));

        $this->migration()->up();

        $this->assertFinalContract('with the preferences table already present');
    }

    /**
     * 3. Columns exist; preferences table absent.
     *
     * The state the previous guard could never repair: it saw `digest_state`
     * and returned, leaving the table missing forever.
     */
    public function test_it_reconciles_when_only_the_digest_columns_exist(): void
    {
        Schema::dropIfExists('notification_preferences');

        $this->assertTrue(Schema::hasColumn('notifications', 'digest_state'));
        $this->assertFalse(Schema::hasTable('notification_preferences'));

        $this->migration()->up();

        $this->assertFinalContract('with only the digest columns present');
    }

    /** 4. One column present, the other absent. */
    public function test_it_reconciles_when_one_digest_column_is_missing(): void
    {
        $this->dropDigestColumns(['digest_sent_at']);

        $this->assertTrue(Schema::hasColumn('notifications', 'digest_state'));
        $this->assertFalse(Schema::hasColumn('notifications', 'digest_sent_at'));

        $this->migration()->up();

        $this->assertFinalContract('with one column missing');
    }

    /** 5. Columns present, index absent. */
    public function test_it_reconciles_when_only_the_index_is_missing(): void
    {
        Schema::table('notifications', function ($table): void {
            $table->dropIndex('notifications_digest_idx');
        });

        $this->assertFalse($this->indexExists());

        $this->migration()->up();

        $this->assertFinalContract('with only the index missing');
    }

    /** 6. Already complete: a rerun must be a harmless no-op. */
    public function test_a_rerun_on_the_complete_schema_is_a_no_op(): void
    {
        $this->assertFinalContract('before the rerun');

        $this->migration()->up();
        $this->migration()->up();

        $this->assertFinalContract('after two reruns');
    }

    /** 7. Partial down() state, then up() again. */
    public function test_it_reconciles_after_a_partial_rollback(): void
    {
        // Only the preferences table was dropped before the rollback stopped.
        Schema::dropIfExists('notification_preferences');

        $this->migration()->up();

        $this->assertFinalContract('after a partial rollback');
    }

    /** 8. up -> down -> up converges. */
    public function test_up_down_up_converges(): void
    {
        $migration = $this->migration();

        $migration->down();
        $this->assertFalse(Schema::hasColumn('notifications', 'digest_state'));
        $this->assertFalse(Schema::hasTable('notification_preferences'));

        $migration->up();

        $this->assertFinalContract('after up -> down -> up');
    }

    /** down() must be safely repeatable across complete and partial states. */
    public function test_down_is_repeatable(): void
    {
        $migration = $this->migration();

        $migration->down();
        $migration->down();

        $this->assertFalse(Schema::hasColumn('notifications', 'digest_state'));
        $this->assertFalse(Schema::hasColumn('notifications', 'digest_sent_at'));
        $this->assertFalse(Schema::hasTable('notification_preferences'));
    }

    /** Existing notification rows must survive reconciliation. */
    public function test_reconciliation_preserves_existing_notification_rows(): void
    {
        $before = DB::table('notifications')->count();

        Schema::dropIfExists('notification_preferences');
        $this->migration()->up();

        $this->assertSame($before, DB::table('notifications')->count());
    }
}
