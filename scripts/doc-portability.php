<?php

declare(strict_types=1);

require __DIR__.'/support/EvidencePath.php';

use Mulkihawler\Tooling\EvidencePath;

/*
 * Prove the generated documentation is reproducible from the DELIVERED tree.
 *
 * A release shipped with `generate-release-docs.php --check` failing inside its
 * own Clean Source archive. Every gate had passed in the development tree, and
 * the very first thing a recipient might run said the documentation was stale.
 *
 * The cause was a figure measured in a state the archive does not preserve:
 * Laravel's package-discovery caches exist while developing, packaging removes
 * them, and the PHP file count included them — 431 where it was written, 429
 * where it was read.
 *
 * This gate simulates that packaging removal and requires the generated
 * documents to come out identical either way. It catches the whole class, not
 * just the two files that happened to expose it.
 *
 * Usage: php scripts/doc-portability.php
 */

$root = dirname(__DIR__);

/*
 * The paths packaging empties. Kept beside the generator's own exclusion list
 * so a future addition to one is visible against the other.
 *
 * @var list<string>
 */
$emptiedByPackaging = [
    'bootstrap/cache',
    'storage/framework/cache/data',
    'storage/framework/sessions',
    'storage/framework/views',
    'storage/logs',
];

$failures = [];

echo "Documentation portability\n";
echo str_repeat('─', 68)."\n";

$generated = [
    /*
     * Generated into the external evidence directory, not the source tree, so
     * regenerating them cannot change the authenticated tree. Paths are
     * relative to <evidence-dir>/reports.
     */
    'VERIFICATION.md',
    'ROADMAP_STATUS.md',
    'RELEASE_DECISION.md',
    'FINAL_RELEASE_VERIFICATION.md',
];

// What the documents say in the development tree, as they stand.
$before = [];

$reportRoot = EvidencePath::directory($root, $argv).'/reports';

foreach ($generated as $relative) {
    $path = $reportRoot.'/'.$relative;

    if (! is_file($path)) {
        $failures[] = "{$relative} has not been generated";

        continue;
    }

    $before[$relative] = (string) file_get_contents($path);
}

if ($failures !== []) {
    foreach ($failures as $failure) {
        echo "  FAIL  {$failure}\n";
    }

    exit(1);
}

/*
 * Copy the tree to a scratch directory, remove exactly what packaging removes,
 * and regenerate there. The real tree is never modified.
 */
$scratch = sys_get_temp_dir().'/doc-portability-'.bin2hex(random_bytes(4));

exec(sprintf(
    'cp -a %s %s 2>&1',
    escapeshellarg($root),
    escapeshellarg($scratch),
), $copyOutput, $copyCode);

if ($copyCode !== 0) {
    echo "  FAIL  could not stage a copy of the tree\n";
    exit(1);
}

register_shutdown_function(static function () use ($scratch): void {
    exec('rm -rf '.escapeshellarg($scratch));
});

$removed = 0;

foreach ($emptiedByPackaging as $relative) {
    $directory = $scratch.'/'.$relative;

    if (! is_dir($directory)) {
        continue;
    }

    foreach (new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($directory, RecursiveDirectoryIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST,
    ) as $entry) {
        if ($entry->isFile() && $entry->getFilename() !== '.gitignore') {
            unlink($entry->getPathname());
            $removed++;
        }
    }
}

printf("  %-46s %d\n", 'runtime files removed, as packaging does', $removed);

// Regenerate in the packaged-shaped tree.
/*
 * The packaged copy gets its OWN evidence directory, seeded with the same
 * evidence, so regeneration writes reports there instead of back into either
 * tree. Reports live outside the source now, so the comparison has to follow
 * them rather than look in a docs/ directory that no longer exists.
 */
$scratchEvidence = $scratch.'-evidence';
@mkdir($scratchEvidence.'/reports', 0o777, true);
copy(EvidencePath::evidenceFile($root, $argv), $scratchEvidence.'/'.EvidencePath::FILENAME);

exec(sprintf(
    'cd %s && %s=%s php scripts/generate-release-docs.php 2>&1',
    escapeshellarg($scratch),
    EvidencePath::ENV,
    escapeshellarg($scratchEvidence),
), $genOutput, $genCode);

if ($genCode !== 0) {
    echo '  FAIL  the generator failed in the packaged tree: '.implode(' ', $genOutput)."\n";
    exit(1);
}

foreach ($generated as $relative) {
    $after = (string) @file_get_contents($scratchEvidence.'/reports/'.$relative);

    if ($after === $before[$relative]) {
        continue;
    }

    $failures[] = "{$relative} differs when generated from a packaged tree";

    // Show the first differing line, so the cause is obvious rather than hunted.
    $left = explode("\n", $before[$relative]);
    $right = explode("\n", $after);

    foreach ($left as $i => $line) {
        if (($right[$i] ?? null) !== $line) {
            $failures[] = sprintf(
                '    line %d: development tree says "%s", packaged tree says "%s"',
                $i + 1,
                trim($line),
                trim($right[$i] ?? '<absent>'),
            );

            break;
        }
    }
}

// The delivered tree must also pass the generator's own staleness check.
exec(sprintf(
    'cd %s && '.EvidencePath::ENV.'='.escapeshellarg($scratchEvidence)
        .' php scripts/generate-release-docs.php --check 2>&1',
    escapeshellarg($scratch),
), $checkOutput, $checkCode);

if ($checkCode !== 0) {
    $failures[] = 'generate-release-docs.php --check fails inside a packaged tree: '
        .implode(' ', $checkOutput);
}

printf("  %-46s %d\n", 'generated documents compared', count($generated));

echo str_repeat('─', 68)."\n";

if ($failures !== []) {
    foreach ($failures as $failure) {
        echo "  FAIL  {$failure}\n";
    }

    echo "\n  DOCUMENTATION IS NOT REPRODUCIBLE FROM THE DELIVERED TREE\n";
    exit(1);
}

echo "  PASS  the documentation regenerates identically from a packaged tree\n";
exit(0);
