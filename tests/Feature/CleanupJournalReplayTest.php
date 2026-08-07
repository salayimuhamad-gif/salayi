<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\Projects\Models\OrphanedFile;
use App\Modules\Projects\Support\CleanupJournal;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * The emergency journal must survive the failure it exists for.
 *
 * THE POISON DEFECT. `recordSafely()` runs when the `orphaned_files` write
 * fails, and it journals the reason under the key `outbox_error`.
 * `ReplayCleanupJournal` hands that same context straight back to
 * `OrphanedFile::record()`, which merged caller context into its INSERT and
 * UPDATE column lists. `orphaned_files` has no `outbox_error` column, so the
 * only entry the fallback ever produces replayed into "no such column" — every
 * run, forever. The queue that exists to survive a database outage could not
 * survive its own metadata.
 *
 * These tests drive the REAL fallback path: the table is genuinely broken, the
 * production helper is called, and the real Artisan command replays it.
 */
final class CleanupJournalReplayTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        /*
         * EVERY journal artefact, not just the active file.
         *
         * The replay command also drains rotated `.claimed` and `.processing`
         * files, so a run left behind by another test made this one see four
         * rows where it had created one. Clearing only the active journal is
         * exactly the stale-state problem these tests exist to catch.
         */
        $this->clearJournalArtefacts();
    }

    protected function tearDown(): void
    {
        $this->clearJournalArtefacts();

        parent::tearDown();
    }

    /** Remove every journal artefact, active or rotated. */
    private function clearJournalArtefacts(): void
    {
        foreach (glob(dirname(CleanupJournal::path()).'/cleanup-journal*') ?: [] as $file) {
            @unlink($file);
        }
    }

    /** Break the outbox table so the production fallback genuinely triggers. */
    private function breakOutboxTable(): void
    {
        Schema::rename('orphaned_files', 'orphaned_files_parked');
    }

    private function restoreOutboxTable(): void
    {
        Schema::rename('orphaned_files_parked', 'orphaned_files');
    }

    /**
     * THE END-TO-END REGRESSION.
     *
     * Fail the write for real, journal it, restore the schema, replay through
     * the real command, and prove exactly one correct row appears.
     */
    public function test_a_genuinely_journalled_failure_replays_into_one_correct_row(): void
    {
        $this->breakOutboxTable();

        OrphanedFile::recordSafely('public', 'offers/1/lost.jpg', 'offer_media_cleanup_failed', [
            'source_type' => 'offer_media',
            'source_id' => 41,
            'last_error' => 'disk unreachable',
        ]);

        $this->restoreOutboxTable();

        // The fallback must have produced a durable line carrying the
        // diagnostic metadata that used to poison the replay.
        $entries = CleanupJournal::entries();
        $this->assertCount(1, $entries, 'The fallback did not journal the failed write.');

        $raw = (string) file_get_contents(CleanupJournal::path());
        /*
         * v6 merge: the strict cleanup branch is authoritative here and it
         * FIXED this vocabulary. `outbox_error` was never a column, so a
         * journal line carrying it died on replay with "no column named
         * outbox_error" and the line could never recover its cleanup job.
         * The diagnostic is now written under `last_error`, which is a real
         * column. Asserting the old key would re-assert the defect, so the
         * assertion follows the diagnostic to where it now lives.
         */
        $this->assertStringContainsString('last_error', $raw, 'The diagnostic metadata was not recorded.');
        $this->assertStringContainsString('disk unreachable', $raw, 'The underlying error text was lost.');

        // Replay through the real command, not a hand-rolled loop.
        $this->artisan('mulkihawler:replay-cleanup-journal')->assertSuccessful();

        $rows = DB::table('orphaned_files')->get();

        $this->assertCount(1, $rows, 'Replay did not produce exactly one outbox row.');

        $row = $rows->first();

        $this->assertSame('src:offer_media:41', $row->job_key);
        $this->assertSame('public', $row->disk);
        $this->assertSame('offers/1/lost.jpg', $row->path);
        $this->assertSame('offer_media', $row->source_type);
        $this->assertSame(41, (int) $row->source_id);
        $this->assertSame(1, (int) $row->attempts, 'One real attempt, counted once.');
        $this->assertNotNull($row->last_error, 'The error evidence was lost in replay.');

        // The successful line is consumed exactly once.
        $this->assertSame([], CleanupJournal::entries(), 'The replayed line was not removed.');
    }

    /** Replay is idempotent: a second run must not inflate the attempt count. */
    public function test_replaying_twice_does_not_inflate_attempts(): void
    {
        $this->breakOutboxTable();
        OrphanedFile::recordSafely('public', 'offers/2/lost.jpg', 'offer_media_cleanup_failed', [
            'source_type' => 'offer_media',
            'source_id' => 7,
        ]);
        $this->restoreOutboxTable();

        $this->artisan('mulkihawler:replay-cleanup-journal')->assertSuccessful();
        $this->artisan('mulkihawler:replay-cleanup-journal')->assertSuccessful();

        $rows = DB::table('orphaned_files')->where('job_key', 'src:offer_media:7')->get();

        $this->assertCount(1, $rows);
        $this->assertSame(1, (int) $rows->first()->attempts, 'The second replay recorded a phantom attempt.');
    }

    /**
     * Unknown context keys must never become SQL identifiers.
     *
     * This is the exact shape the fallback produces, plus deliberate nonsense.
     * It must be accepted and stored, with the unknown keys left out of the
     * statement rather than turned into columns.
     */
    public function test_unknown_context_keys_do_not_become_columns(): void
    {
        $row = OrphanedFile::record('public', 'projects/9/x.jpg', 'journal_replay', [
            'source_type' => 'project_media',
            'source_id' => 3,
            'outbox_error' => 'SQLSTATE[HY000]: database is locked',
            'totally_unknown_key' => 'nonsense',
            'another' => ['nested' => true],
        ]);

        $this->assertSame('src:project_media:3', $row->job_key);
        $this->assertSame(1, (int) $row->attempts);

        /*
         * v6 merge: unknown journal keys are FOLDED into `last_error`
         * rather than silently dropped or attempted as columns — that
         * folding is the fix which made the fallback replayable. The
         * diagnostic must therefore still be present, and the unknown key
         * must be visible in the same field rather than lost.
         */
        $this->assertStringContainsString('SQLSTATE[HY000]: database is locked', (string) $row->last_error);
        $this->assertStringContainsString('totally_unknown_key', (string) $row->last_error);

        $columns = Schema::getColumnListing('orphaned_files');
        $this->assertNotContains('totally_unknown_key', $columns);
        $this->assertNotContains('another', $columns);
        $this->assertNotContains('outbox_error', $columns);
    }

    /** A journal line whose context is hostile still replays safely. */
    public function test_a_malformed_context_does_not_poison_the_queue(): void
    {
        CleanupJournal::append('public', 'projects/1/a.jpg', 'journal_replay', [
            'source_type' => 'project_media',
            'source_id' => 5,
            'outbox_error' => 'original failure',
            'job_key' => 'ATTEMPTED-IDENTITY-OVERRIDE',
            'attempts' => 9999,
        ]);

        $this->artisan('mulkihawler:replay-cleanup-journal')->assertSuccessful();

        $row = DB::table('orphaned_files')->first();

        $this->assertNotNull($row, 'The line failed to replay.');
        // Identity and lifecycle columns are computed, never caller-supplied.
        $this->assertSame('src:project_media:5', $row->job_key);
        $this->assertSame(1, (int) $row->attempts);
        $this->assertSame([], CleanupJournal::entries());
    }
}
