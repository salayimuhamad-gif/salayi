<?php

declare(strict_types=1);

/**
 * Migration safety guard (spec 33.3 "migration safety", 26.1 "no destructive
 * shortcuts", 37.6 "upgrade preserves data").
 *
 * A migration that drops a column or a table is not forbidden — sometimes it is
 * correct — but it must be a deliberate, reviewed decision. This script forces
 * that: a destructive operation must carry an explicit acknowledgement comment
 * on the preceding lines, so the diff shows someone thought about it.
 *
 *     // MIGRATION-GUARD: intentional-drop — <reason>
 *     $table->dropColumn('legacy_price');
 *
 * It also enforces two things that are always bugs on a live deployment:
 *   - every migration must define down(), or a failed upgrade cannot roll back;
 *   - no raw DROP DATABASE / TRUNCATE in a migration.
 *
 * Usage: php scripts/migration-guard.php
 */
$root = dirname(__DIR__);

$paths = array_merge(
    glob($root.'/database/migrations/*.php') ?: [],
    glob($root.'/app/Modules/*/Database/Migrations/*.php') ?: [],
);

sort($paths);

$destructive = [
    '/->\s*dropColumn\s*\(/' => 'dropColumn',
    '/->\s*dropIfExists\s*\(/' => 'dropIfExists',
    '/Schema::\s*drop\s*\(/' => 'Schema::drop',
    '/->\s*dropForeign\s*\(/' => 'dropForeign',
    '/->\s*dropUnique\s*\(/' => 'dropUnique',
    '/->\s*dropPrimary\s*\(/' => 'dropPrimary',
    '/->\s*renameColumn\s*\(/' => 'renameColumn',
];

$alwaysForbidden = [
    '/DROP\s+DATABASE/i' => 'DROP DATABASE',
    '/TRUNCATE\s+TABLE/i' => 'TRUNCATE TABLE',
    '/DELETE\s+FROM\s+\w+\s*;/i' => 'unqualified DELETE',
];

$acknowledgement = '/MIGRATION-GUARD:\s*intentional-drop/i';

$failures = [];
$notes = [];

foreach ($paths as $path) {
    $relative = ltrim(str_replace($root, '', $path), '/');
    $lines = file($path, FILE_IGNORE_NEW_LINES) ?: [];
    $source = implode("\n", $lines);

    // 1. down() must exist and must not be empty.
    if (preg_match('/public\s+function\s+down\s*\(\s*\)\s*:\s*void\s*\{(.*?)\n\s{4}\}/s', $source, $m) !== 1) {
        $failures[] = [$relative, 0, 'no down() method — a failed upgrade could not be rolled back'];
    } elseif (trim($m[1]) === '') {
        $failures[] = [$relative, 0, 'down() is empty — a failed upgrade could not be rolled back'];
    }

    foreach ($lines as $index => $line) {
        $lineNumber = $index + 1;

        foreach ($alwaysForbidden as $pattern => $label) {
            if (preg_match($pattern, $line) === 1) {
                $failures[] = [$relative, $lineNumber, "{$label} is never allowed in a migration"];
            }
        }

        foreach ($destructive as $pattern => $label) {
            if (preg_match($pattern, $line) !== 1) {
                continue;
            }

            // A drop inside down() is the expected inverse of up(); only
            // destructive changes in up() need acknowledgement.
            $before = implode("\n", array_slice($lines, 0, $index));
            $lastUp = strrpos($before, 'function up(');
            $lastDown = strrpos($before, 'function down(');

            $insideDown = $lastDown !== false && ($lastUp === false || $lastDown > $lastUp);

            if ($insideDown) {
                continue;
            }

            $context = implode("\n", array_slice($lines, max(0, $index - 4), 4));

            if (preg_match($acknowledgement, $context) === 1) {
                $notes[] = [$relative, $lineNumber, "{$label} — acknowledged"];

                continue;
            }

            $failures[] = [
                $relative,
                $lineNumber,
                "{$label} in up() without a MIGRATION-GUARD: intentional-drop comment",
            ];
        }
    }
}

/*
 * Foreign-key declaration order.
 *
 * A table cannot be constrained against one that is created later in the same
 * migration; MySQL rejects it at migrate time with an error naming only the
 * constraint. This is invisible to php -l and to any check that does not model
 * ordering, so it is checked here.
 */
foreach ($paths as $path) {
    $relative = ltrim(str_replace($root, '', $path), '/');
    $source = (string) file_get_contents($path);

    if (! preg_match('/public\s+function\s+up\s*\(/', $source)) {
        continue;
    }

    $upStart = (int) strpos($source, 'function up(');
    $downStart = strpos($source, 'function down(');
    $upBody = substr($source, $upStart, ($downStart !== false ? $downStart - $upStart : null));

    preg_match_all("/Schema::create\(\s*'([a-z0-9_]+)'/i", $upBody, $created, PREG_OFFSET_CAPTURE);

    $createdAt = [];
    foreach ($created[1] as $match) {
        $createdAt[$match[0]] = $match[1];
    }

    preg_match_all("/->constrained\(\s*'([a-z0-9_]+)'/i", $upBody, $refs, PREG_OFFSET_CAPTURE);

    foreach ($refs[1] as $match) {
        $target = $match[0];
        $offset = $match[1];

        if (! isset($createdAt[$target])) {
            continue; // created by another migration; ordering is across files
        }

        if ($createdAt[$target] > $offset) {
            // $offset is relative to $upBody, so shift by $upStart before
            // counting lines against the full source.
            $line = substr_count(substr($source, 0, $upStart + $offset), "\n") + 1;
            $failures[] = [
                $relative,
                $line,
                sprintf('foreign key references "%s", which this migration creates later', $target),
            ];
        }
    }
}

echo "\nMigration guard — ".count($paths)." migration(s) inspected\n";
echo str_repeat('─', 68), "\n";

foreach ($notes as [$file, $line, $message]) {
    printf("  \033[36mNOTE\033[0m  %s:%d  %s\n", $file, $line, $message);
}

foreach ($failures as [$file, $line, $message]) {
    printf("  \033[31mFAIL\033[0m  %s%s  %s\n", $file, $line > 0 ? ':'.$line : '', $message);
}

echo str_repeat('─', 68), "\n";

/*
 * TABLE COLLISIONS AND PHANTOM TABLES.
 *
 * Both of these shipped in this codebase, and nothing caught either:
 *
 *   - Two migrations in different modules each ran Schema::create('ad_campaigns').
 *     The second fails with "table already exists" — on a real database, which
 *     means on the customer's first install and nowhere before it.
 *
 *   - A migration ran Schema::table('deployment_history') for a table that has
 *     never existed; the real one is `release_records`. Same failure, same
 *     moment.
 *
 * Neither is visible by reading a diff, and both are trivial to detect here.
 */
$createdTables = [];
$alteredTables = [];

foreach ($paths as $path) {
    $relative = ltrim(str_replace($root, '', $path), '/');
    $source = (string) file_get_contents($path);

    if (preg_match_all("/Schema::create\\(\\s*'([a-z0-9_]+)'/i", $source, $m)) {
        foreach ($m[1] as $table) {
            $createdTables[$table][] = basename($relative);
        }
    }

    if (preg_match_all("/Schema::table\\(\\s*'([a-z0-9_]+)'/i", $source, $m)) {
        foreach ($m[1] as $table) {
            $alteredTables[$table][] = basename($relative);
        }
    }
}

foreach ($createdTables as $table => $files) {
    $unique = array_values(array_unique($files));

    if (count($unique) > 1) {
        $failures[] = sprintf(
            'table "%s" is created by %d migrations (%s) — the second will fail with "table already exists"',
            $table,
            count($unique),
            implode(', ', $unique)
        );
    }
}

foreach ($alteredTables as $table => $files) {
    if (! isset($createdTables[$table])) {
        $failures[] = sprintf(
            'migration %s alters table "%s", which no migration creates',
            $files[0],
            $table
        );
    }
}

if ($failures === []) {
    echo "  \033[42;30m PASS \033[0m  every migration is reversible and free of unreviewed destructive operations\n\n";
    exit(0);
}

printf("  \033[41;37m FAIL \033[0m  %d issue(s)\n\n", count($failures));
exit(1);
