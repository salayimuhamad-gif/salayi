<?php

declare(strict_types=1);
use App\Modules\Projects\Models\OrphanedFile;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\DB;

/*
 * One INDEPENDENT process that records a single cleanup attempt.
 *
 * A concurrency test that loops in one process proves nothing: every statement
 * is already serialised by the single connection, so a lost update cannot occur
 * and the defect this exists to catch stays invisible. Each worker here is a
 * separate OS process with its own PDO handle, and every worker blocks on a
 * barrier file so they all reach the UPSERT at the same moment.
 *
 * Usage:
 *   php concurrent-record-worker.php <db-path> <barrier> <disk> <path> \
 *       [source_type] [source_id]
 */

$root = dirname(__DIR__, 2);

[$script, $dbPath, $barrierDir, $workerId, $disk, $path] = array_pad(array_slice($argv, 0, 6), 6, null);
$sourceType = $argv[6] ?? null;
$sourceId = $argv[7] ?? null;

require $root.'/vendor/autoload.php';

/** @var Application $app */
$app = require $root.'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

config([
    'database.default' => 'sqlite',
    'database.connections.sqlite.database' => $dbPath,
    'database.connections.sqlite.foreign_key_constraints' => true,
]);

DB::purge('sqlite');

/*
 * SQLite serialises writers, so a contended writer must wait rather than fail
 * fast. Without this every worker but one dies with "database is locked" and
 * the test would measure the timeout, not the accounting.
 */
DB::statement('PRAGMA busy_timeout = 15000');

/*
 * READY, THEN WAIT — NO TIMING ASSUMPTION.
 *
 * The parent used to sleep a fixed 400 ms and then open the gate. On a loaded
 * machine a worker could still be booting Laravel when the gate opened, so it
 * arrived late and ran on its own: the test passed without ever exercising the
 * race it exists to prove. Each worker now announces itself only after its
 * connection is open, and the parent waits for every announcement before
 * releasing anybody.
 */
file_put_contents($barrierDir.'/ready-'.$workerId, (string) getmypid());

$deadline = microtime(true) + 60;

while (! file_exists($barrierDir.'/go')) {
    if (microtime(true) > $deadline) {
        fwrite(STDERR, "worker {$workerId}: start gate never opened\n");
        exit(2);
    }

    usleep(200);
}

$context = [];

if ($sourceType !== null && $sourceId !== null) {
    $context = ['source_type' => $sourceType, 'source_id' => (int) $sourceId];
}

try {
    $row = OrphanedFile::record(
        (string) $disk,
        (string) $path,
        'concurrent-worker',
        $context,
    );

    echo json_encode(['ok' => true, 'id' => $row->id, 'attempts' => $row->attempts]), PHP_EOL;
    exit(0);
} catch (Throwable $e) {
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]), PHP_EOL;
    exit(1);
}
