<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\Projects\Console\SweepOrphanedFiles;
use App\Modules\Projects\Enums\ConstructionStatus;
use App\Modules\Projects\Enums\DeliveryStatus;
use App\Modules\Projects\Enums\ProjectType;
use App\Modules\Projects\Enums\PublicationStatus;
use App\Modules\Projects\Models\OrphanedFile;
use App\Modules\Projects\Models\Project;
use App\Modules\Projects\Models\ProjectMedia;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * The Sweep compare-and-swap, exercised across two real processes.
 *
 * DELIBERATELY WITHOUT RefreshDatabase. That trait wraps every test in a
 * transaction that never commits, so the command's own DB::transaction()
 * degrades to a savepoint and a second process sees nothing — the executed
 * probe reported "database is locked" for exactly this reason.
 *
 * The cost is manual cleanup, which `tearDown()` does explicitly.
 */
final class SweepCasConcurrencyTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        /*
         * COMMITTED SCHEMA, built explicitly.
         *
         * RefreshDatabase is deliberately absent — its open transaction is
         * what stopped a second process from seeing anything — so the schema
         * is not created for us either. `migrate:fresh` commits its DDL, which
         * is exactly what the child process needs.
         */
        $this->artisan('migrate:fresh', ['--force' => true])->assertSuccessful();

        $this->assertSame(
            0,
            DB::transactionLevel(),
            'Schema setup must leave no open transaction.',
        );
    }

    protected function tearDown(): void
    {
        /*
         * Committed rows are real, so they are removed by hand. Ordered
         * child-first: the ledger's foreign key is RESTRICT by design and
         * would otherwise block the cleanup it is protecting.
         */
        foreach ([
            'cleanup_journal_imports',
            'project_media',
            'orphaned_files',
            // The committed project row too: without RefreshDatabase nothing
            // rolls it back, and it leaked into every later test.
            'projects',
        ] as $table) {
            if (Schema::hasTable($table)) {
                DB::table($table)->delete();
            }
        }

        SweepOrphanedFiles::setCasBarrier(null);

        foreach (glob(storage_path('app/cas-*')) ?: [] as $leftover) {
            @unlink($leftover);
        }

        parent::tearDown();
    }

    /**
     * A competing update commits between the claim and the CAS.
     *
     * The `DB::listen` version could not work: the listener fired while this
     * connection still held its claim transaction, so the second writer got
     * "database is locked" on SQLite rather than committing.
     *
     * `SweepOrphanedFiles::setCasBarrier()` fires AFTER that transaction has
     * committed and BEFORE the CAS, so a separate process can commit its own
     * update in the window the guard exists to defend. The barrier blocks
     * until that commit is confirmed.
     */
    public function test_the_sweep_stands_down_when_a_competing_update_commits_before_its_cas(): void
    {
        /*
         * Self-contained setup. The helpers in ProjectWizardTest assume that
         * class's RefreshDatabase lifecycle, which is the very thing this test
         * must not have — so the one row it needs is built directly.
         */
        $project = Project::query()->create([
            'slug' => 'sweep-cas-process',
            'name_ckb' => 'sweep-cas-process',
            'project_type' => ProjectType::Residential->value,
            'construction_status' => ConstructionStatus::UnderConstruction->value,
            'delivery_status' => DeliveryStatus::NotStarted->value,
            'publication_status' => PublicationStatus::Draft->value,
        ]);

        $media = ProjectMedia::query()->create([
            'project_id' => $project->id,
            'kind' => 'image',
            'disk' => 'public',
            'path' => 'projects/cas/cas-process.jpg',
            'mime_type' => 'image/jpeg',
            'size_bytes' => 10,
            'sort_order' => 0,
            'is_cover' => true,
            'cleanup_pending' => true,
        ]);

        $job = OrphanedFile::record(
            'public',
            (string) $media->path,
            'project_media_cleanup_exhausted',
            ['source_type' => 'project_media', 'source_id' => $media->id],
        );

        $media->forceFill(['cleanup_outbox_id' => $job->id])->save();

        /*
         * NO OUTER TRANSACTION. The previous version committed and then
         * immediately reopened one, which left the command's own
         * DB::transaction() as a nested savepoint — so the claim never really
         * committed and the child process could not write at all.
         *
         * Asserted rather than assumed: without this, the test passes for the
         * wrong reason the moment a trait reintroduces one.
         */
        /*
         * A SHARED FILE, asserted. Two processes opening ":memory:" get two
         * separate databases, so the child would see an empty schema and this
         * test would pass without ever exercising the race.
         */
        $database = (string) config('database.connections.'.config('database.default').'.database');

        $this->assertNotSame(':memory:', $database, 'The CAS test needs a shared database.');

        $this->assertSame(
            0,
            DB::transactionLevel(),
            'The Sweep CAS test requires no outer transaction.',
        );

        $observed = (int) $job->attempts;
        $token = bin2hex(random_bytes(4));
        $go = storage_path("app/cas-go-{$token}");
        $done = storage_path("app/cas-done-{$token}");
        $workerPath = storage_path("app/cas-worker-{$token}.php");
        $visible = storage_path("app/cas-visible-{$token}");

        file_put_contents($workerPath, <<<'PHP'
        <?php
        [$script, $base, $jobId, $go, $done, $visible] = $argv;

        require $base.'/vendor/autoload.php';
        $app = require $base.'/bootstrap/app.php';
        $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

        // Prove this process can SEE the parent's committed row before
        // signalling readiness. If it cannot, the databases are not shared and
        // nothing below would be meaningful.
        $target = Illuminate\Support\Facades\DB::table('orphaned_files')
            ->where('id', (int) $jobId)
            ->first();

        if ($target === null) {
            fwrite(STDERR, "child cannot see orphaned_files id={$jobId}\n");

            exit(2);
        }

        file_put_contents($visible, (string) $target->attempts);

        $deadline = microtime(true) + 60;

        while (! is_file($go) && microtime(true) < $deadline) {
            usleep(500);
        }

        // Its OWN connection and its OWN commit: the claim transaction has
        // already ended, so this cannot deadlock against it.
        Illuminate\Support\Facades\DB::table('orphaned_files')
            ->where('id', (int) $jobId)
            ->update(['attempts' => Illuminate\Support\Facades\DB::raw('attempts + 3')]);

        $now = (int) Illuminate\Support\Facades\DB::table('orphaned_files')
            ->where('id', (int) $jobId)
            ->value('attempts');

        file_put_contents($done, (string) $now);

        exit(0);
        PHP);

        $process = proc_open(
            ['php', $workerPath, base_path(), (string) $job->id, $go, $done, $visible],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
        );

        $this->assertIsResource($process, 'Could not start the competing worker.');

        /*
         * The child must be able to SEE the row before the barrier releases
         * it. Without this the test could pass while the child updated zero
         * rows in a database of its own.
         */
        $deadline = microtime(true) + 30;

        while (! is_file($visible) && microtime(true) < $deadline) {
            usleep(1000);
        }

        $this->assertFileExists($visible, 'The child process could not read the target row.');

        $fired = false;

        SweepOrphanedFiles::setCasBarrier(
            function () use ($go, $done, &$fired): void {
                $fired = true;

                file_put_contents($go, '1');

                // Block until the competing update has actually COMMITTED.
                $deadline = microtime(true) + 60;

                while (! is_file($done) && microtime(true) < $deadline) {
                    usleep(500);
                }
            },
        );

        try {
            $this->artisan('mulkihawler:sweep-orphaned-files');
        } finally {
            SweepOrphanedFiles::setCasBarrier(null);
        }

        foreach ($pipes as $pipe) {
            fclose($pipe);
        }

        $workerExit = proc_close($process);

        @unlink($workerPath);
        @unlink($go);

        $this->assertTrue($fired, 'The barrier never fired; the interleaving was not exercised.');
        $this->assertSame(0, $workerExit, 'The competing worker failed.');
        $this->assertFileExists($done, 'The competing update never committed.');

        $job = OrphanedFile::query()->findOrFail($job->id);

        /*
         * The CAS binds to a token that no longer exists, so it matches
         * nothing. The newer count survives and the job stays OPEN.
         */
        $this->assertSame($observed + 3, (int) $job->attempts);
        $this->assertNull($job->resolved_at, 'A concurrently updated job must stay open.');
        $this->assertNotNull($job->active_key, 'An open job keeps its identity.');
        $this->assertDatabaseHas('project_media', ['id' => $media->id]);

        @unlink($done);
    }
}
