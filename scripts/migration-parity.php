<?php

declare(strict_types=1);

/*
 * Prove that the migration set is genuinely reversible.
 *
 * A "42 applied → 41 reversed" report once looked like a migration that could
 * not be rolled back. It was a miscount — `Creating migration table` also
 * prints DONE — but the only way to know that is to compare the SCHEMA, not the
 * console output. This gate does that: it migrates a disposable database,
 * fingerprints every table and column, rolls the whole set back, and re-applies
 * it, then requires the fingerprint to match exactly.
 *
 * A migration that leaves a table, column, or index behind fails here rather
 * than surprising somebody mid-upgrade.
 *
 * Usage: php scripts/migration-parity.php
 */

require __DIR__.'/../vendor/autoload.php';

/** @var Application $app */
$app = require __DIR__.'/../bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

$failures = [];

/** A disposable database, so nothing here can touch a real one. */
$scratch = tempnam(sys_get_temp_dir(), 'migration-parity-').'.sqlite';
touch($scratch);

register_shutdown_function(static function () use ($scratch): void {
    @unlink($scratch);
});

config([
    'database.default' => 'sqlite',
    'database.connections.sqlite.database' => $scratch,
    'database.connections.sqlite.foreign_key_constraints' => true,
]);

DB::purge('sqlite');

/**
 * Every table with its column names, sorted, so the comparison is order-free.
 *
 * @return array<string, list<string>>
 */
function fingerprint(): array
{
    $signature = [];

    foreach (Schema::getTables() as $table) {
        $name = $table['name'];

        if ($name === 'migrations') {
            // Bookkeeping, not application schema.
            continue;
        }

        $columns = array_map(
            static fn (array $column): string => (string) $column['name'],
            Schema::getColumns($name),
        );

        sort($columns);
        $signature[$name] = $columns;
    }

    ksort($signature);

    return $signature;
}

function run(string $command): int
{
    return Artisan::call($command, ['--force' => true]);
}

/** How many migration files exist on disk. */
$onDisk = 0;

foreach (['app', 'database/migrations'] as $root) {
    $dir = __DIR__.'/../'.$root;

    if (! is_dir($dir)) {
        continue;
    }

    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir)) as $file) {
        if ($file->isFile()
            && $file->getExtension() === 'php'
            && str_contains(str_replace('\\', '/', $file->getPathname()), 'igrations/')) {
            $onDisk++;
        }
    }
}

echo "Migration parity\n";
echo str_repeat('─', 68)."\n";

// ---- 1. fresh migration
if (run('migrate') !== 0) {
    $failures[] = 'the initial migration failed';
}

$applied = DB::table('migrations')->count();
$fresh = fingerprint();

printf("  %-46s %d\n", 'migration files on disk', $onDisk);
printf("  %-46s %d\n", 'applied by a fresh migrate', $applied);
printf("  %-46s %d\n", 'tables created', count($fresh));

if ($applied !== $onDisk) {
    $failures[] = "only {$applied} of {$onDisk} migration files were applied";
}

// ---- 2. full rollback
if (run('migrate:rollback') !== 0) {
    $failures[] = 'the rollback failed';
}

$remaining = DB::table('migrations')->count();
$leftBehind = fingerprint();

printf("  %-46s %d\n", 'migrations still recorded after rollback', $remaining);
printf("  %-46s %d\n", 'application tables left behind', count($leftBehind));

if ($remaining !== 0) {
    $failures[] = "{$remaining} migration(s) were not reversed";
}

if ($leftBehind !== []) {
    $failures[] = 'tables left behind by rollback: '.implode(', ', array_keys($leftBehind));
}

// ---- 3. re-migrate and compare
if (run('migrate') !== 0) {
    $failures[] = 'the second migration failed';
}

$again = fingerprint();

printf("  %-46s %d\n", 'applied by the second migrate', DB::table('migrations')->count());

if ($again !== $fresh) {
    foreach ($fresh as $table => $columns) {
        if (! isset($again[$table])) {
            $failures[] = "table {$table} is missing after re-migration";

            continue;
        }

        if ($again[$table] !== $columns) {
            $missing = implode(', ', array_diff($columns, $again[$table]));
            $extra = implode(', ', array_diff($again[$table], $columns));
            $failures[] = trim("table {$table} differs after re-migration"
                .($missing === '' ? '' : " (missing: {$missing})")
                .($extra === '' ? '' : " (extra: {$extra})"));
        }
    }

    foreach (array_keys($again) as $table) {
        if (! isset($fresh[$table])) {
            $failures[] = "unexpected table {$table} after re-migration";
        }
    }
} else {
    printf("  %-46s %s\n", 'schema after rollback + re-migrate', 'identical to a fresh migration');
}

echo str_repeat('─', 68)."\n";

if ($failures !== []) {
    foreach ($failures as $failure) {
        echo "  FAIL  {$failure}\n";
    }

    echo "\n  MIGRATION PARITY FAILED\n";
    exit(1);
}

echo "  PASS  every migration reverses and re-applies to the same schema\n";
exit(0);
