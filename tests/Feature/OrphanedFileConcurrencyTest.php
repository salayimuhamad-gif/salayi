<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\Projects\Models\OrphanedFile;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Exact attempt accounting for `OrphanedFile::record()` under real contention.
 *
 * The defect: the method read the row, and when it found none ran an upsert
 * whose conflict branch touched only `updated_at`, then read again. Two callers
 * creating the first job for one `job_key` could both see no row; one inserted
 * `attempts = 1` and the loser incremented nothing, so both returned
 * `attempts = 1` and a real failed cleanup attempt vanished from the count.
 *
 * These tests use SEPARATE OS PROCESSES with their own connections, released
 * together by a barrier file. A sequential loop in one process cannot fail the
 * way the bug failed and would have passed against the broken implementation.
 */
final class OrphanedFileConcurrencyTest extends TestCase
{
    private string $dbPath = '';

    private string $barrier = '';

    protected function setUp(): void
    {
        parent::setUp();

        // A file-backed database, because ":memory:" is private to one
        // connection and therefore cannot be contended at all.
        $this->dbPath = sys_get_temp_dir().'/mulkihawler-concurrency-'.bin2hex(random_bytes(6)).'.sqlite';
        $this->barrier = $this->dbPath.'.barrier';
        mkdir($this->barrier, 0o777, true);

        touch($this->dbPath);

        config([
            'database.default' => 'sqlite',
            'database.connections.sqlite.database' => $this->dbPath,
        ]);

        DB::purge('sqlite');
        DB::statement('PRAGMA journal_mode = WAL');
        DB::statement('PRAGMA busy_timeout = 15000');

        $this->artisan('migrate', ['--force' => true])->run();
    }

    protected function tearDown(): void
    {
        DB::purge('sqlite');

        // Every artefact, including those left by a failed run.
        foreach (glob($this->barrier.'/*') ?: [] as $file) {
            @unlink($file);
        }

        @rmdir($this->barrier);

        foreach ([$this->dbPath, $this->dbPath.'-wal', $this->dbPath.'-shm'] as $file) {
            if ($file !== '' && file_exists($file)) {
                @unlink($file);
            }
        }

        parent::tearDown();
    }

    /**
     * Start `$count` workers, release them together, and collect their results.
     *
     * @return list<array<string, mixed>>
     */
    private function race(int $count, string $disk, string $path, ?string $sourceType = null, ?int $sourceId = null): array
    {
        foreach (glob($this->barrier.'/*') ?: [] as $stale) {
            @unlink($stale);
        }

        $worker = base_path('tests/Support/concurrent-record-worker.php');
        $processes = [];
        $pipes = [];

        for ($i = 0; $i < $count; $i++) {
            $command = [
                PHP_BINARY, $worker, $this->dbPath, $this->barrier, (string) $i, $disk, $path,
            ];

            if ($sourceType !== null) {
                $command[] = $sourceType;
                $command[] = (string) $sourceId;
            }

            $descriptors = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
            $process = proc_open($command, $descriptors, $procPipes, base_path());

            $this->assertIsResource($process, 'Could not start a concurrency worker.');

            $processes[$i] = $process;
            $pipes[$i] = $procPipes;
        }

        /*
         * WAIT FOR EVERY WORKER, THEN OPEN THE GATE.
         *
         * A fixed sleep is a guess about how long booting Laravel takes. When
         * the guess is wrong the workers serialise and the test proves nothing
         * while still reporting success — the worst possible outcome for a
         * concurrency guard. Each worker writes a ready marker once its
         * connection is open; only when all of them are present is the gate
         * released.
         */
        $deadline = microtime(true) + 60;

        while (count(glob($this->barrier.'/ready-*') ?: []) < $count) {
            if (microtime(true) > $deadline) {
                $present = array_map('basename', glob($this->barrier.'/ready-*') ?: []);

                $this->fail(sprintf(
                    'Only %d of %d workers reached the barrier within 60s. Present: [%s].',
                    count($present),
                    $count,
                    implode(', ', $present),
                ));
            }

            usleep(1000);
        }

        touch($this->barrier.'/go');

        $results = [];

        foreach ($processes as $i => $process) {
            $stdout = stream_get_contents($pipes[$i][1]);
            $stderr = stream_get_contents($pipes[$i][2]);

            fclose($pipes[$i][1]);
            fclose($pipes[$i][2]);

            $exit = proc_close($process);

            // Every worker's exit code and output is asserted, so a worker that
            // died before recording cannot be mistaken for one that succeeded.
            $this->assertSame(
                0,
                $exit,
                "Worker {$i} exited {$exit}.\nstdout: {$stdout}\nstderr: {$stderr}",
            );

            $decoded = json_decode(trim((string) $stdout), true);

            $this->assertIsArray(
                $decoded,
                "Worker {$i} produced no result (exit {$exit}). stderr: {$stderr} stdout: {$stdout}",
            );
            $this->assertTrue(
                $decoded['ok'] ?? false,
                "Worker {$i} failed: ".($decoded['error'] ?? 'unknown'),
            );

            $results[] = $decoded;
        }

        return $results;
    }

    /**
     * THE CENTRAL REGRESSION.
     *
     * N simultaneous first-creations must leave exactly one row carrying
     * exactly N attempts. The broken implementation left `attempts = 1`.
     */
    public function test_simultaneous_first_creation_counts_every_attempt(): void
    {
        $workers = 8;

        $this->race($workers, 'public', 'offers/1/contended.jpg', 'offer_media', 41);

        $rows = DB::table('orphaned_files')->where('job_key', 'src:offer_media:41')->get();

        $this->assertCount(1, $rows, 'Exactly one row per job_key.');
        $this->assertSame(
            $workers,
            (int) $rows->first()->attempts,
            'Every concurrent attempt must be counted exactly once.',
        );
    }

    /** Concurrent updates of an EXISTING unresolved job must also be exact. */
    public function test_simultaneous_updates_of_an_existing_job_count_exactly(): void
    {
        OrphanedFile::record('public', 'offers/2/existing.jpg', 'seed', [
            'source_type' => 'offer_media', 'source_id' => 7,
        ]);

        $this->assertSame(1, (int) DB::table('orphaned_files')->where('job_key', 'src:offer_media:7')->value('attempts'));

        $workers = 6;
        $this->race($workers, 'public', 'offers/2/existing.jpg', 'offer_media', 7);

        $this->assertSame(
            1 + $workers,
            (int) DB::table('orphaned_files')->where('job_key', 'src:offer_media:7')->value('attempts'),
        );
        $this->assertSame(1, DB::table('orphaned_files')->where('job_key', 'src:offer_media:7')->count());
    }

    /**
     * A resolved job reused concurrently yields ONE correctly reset lifecycle.
     *
     * The first writer sees `resolved_at` set and restarts at 1; every later
     * writer sees it already cleared and increments. So N writers still produce
     * N, and none of them inherits the previous lifecycle's exhausted state.
     */
    public function test_a_resolved_job_reused_concurrently_resets_once(): void
    {
        $job = OrphanedFile::record('public', 'offers/3/reused.jpg', 'seed', [
            'source_type' => 'offer_media', 'source_id' => 9,
        ]);

        /*
         * v6 merge: under the strict cleanup lifecycle a RESOLVED incident
         * releases `active_key` — that release is what lets a later
         * incident at the same path become a NEW row instead of
         * overwriting the earlier one's evidence. Leaving the key attached
         * to a resolved row is a state production never produces, and the
         * racers would then increment the dead lifecycle.
         */
        DB::table('orphaned_files')->where('id', $job->id)->update([
            'attempts' => 25,
            'resolved_at' => now(),
            'active_key' => null,
            'last_error' => 'previous lifecycle',
            'file_resolved_at' => now(),
            'source_finalised_at' => now(),
            'handed_off_at' => now(),
        ]);

        $workers = 5;
        $this->race($workers, 'public', 'offers/3/reused.jpg', 'offer_media', 9);

        // Exactly ONE live lifecycle; the resolved one survives as history.
        $live = DB::table('orphaned_files')
            ->where('job_key', 'src:offer_media:9')
            ->whereNull('resolved_at');

        $this->assertSame(1, (clone $live)->count(), 'the race did not produce exactly one live lifecycle');
        $this->assertSame(
            1,
            DB::table('orphaned_files')
                ->where('job_key', 'src:offer_media:9')
                ->whereNotNull('resolved_at')
                ->count(),
            'the previous lifecycle\'s evidence was destroyed',
        );

        $row = (clone $live)->first();
        $this->assertNotSame($job->id, (int) $row->id, 'the dead lifecycle was reused instead of preserved');
        $this->assertNull($row->resolved_at, 'The new lifecycle must be unresolved.');
        $this->assertNull($row->last_error, 'Exhausted evidence must not leak into the new lifecycle.');
        $this->assertNull($row->file_resolved_at);
        $this->assertNull($row->source_finalised_at);
        $this->assertNull($row->handed_off_at);
        $this->assertSame(
            $workers,
            (int) $row->attempts,
            'The reset restarts at 1 and later writers increment from there.',
        );
    }

    /** Different domains sharing a numeric id must remain distinct jobs. */
    public function test_different_source_types_with_the_same_id_stay_distinct(): void
    {
        $this->race(3, 'public', 'a/same.jpg', 'offer_media', 12);
        $this->race(4, 'public', 'a/same.jpg', 'project_media', 12);

        $this->assertSame(3, (int) DB::table('orphaned_files')->where('job_key', 'src:offer_media:12')->value('attempts'));
        $this->assertSame(4, (int) DB::table('orphaned_files')->where('job_key', 'src:project_media:12')->value('attempts'));
        $this->assertSame(2, DB::table('orphaned_files')->count());
    }

    /** Two different sources reusing one disk/path must remain distinct jobs. */
    public function test_different_sources_reusing_one_path_stay_distinct(): void
    {
        $this->race(2, 'public', 'offers/9/recycled.jpg', 'offer_media', 100);
        $this->race(2, 'public', 'offers/9/recycled.jpg', 'offer_media', 101);

        $this->assertSame(2, DB::table('orphaned_files')->where('path', 'offers/9/recycled.jpg')->count());
        $this->assertSame(2, (int) DB::table('orphaned_files')->where('job_key', 'src:offer_media:100')->value('attempts'));
        $this->assertSame(2, (int) DB::table('orphaned_files')->where('job_key', 'src:offer_media:101')->value('attempts'));
    }

    /** Path-only work is keyed by a bounded hash and stays one row. */
    public function test_path_only_jobs_are_counted_exactly(): void
    {
        $workers = 5;
        $this->race($workers, 'public', 'projects/7/orphan.jpg');

        $key = 'path:'.hash('sha256', 'public'."\0".'projects/7/orphan.jpg');

        $this->assertSame(1, DB::table('orphaned_files')->where('job_key', $key)->count());
        $this->assertSame($workers, (int) DB::table('orphaned_files')->where('job_key', $key)->value('attempts'));
        $this->assertLessThanOrEqual(255, strlen($key));
    }
}
