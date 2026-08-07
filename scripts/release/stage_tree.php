<?php

declare(strict_types=1);

/*
 * Stage the exact file set that will be archived, write its manifest, and prove
 * the two agree in both directions.
 *
 * The previous split was the whole defect: `write_tree_manifest.php` walked the
 * live directory while the working-tree ZIP was staged from `SHA256SUMS.txt`.
 * Two notions of "eligible" meant a `__pycache__/*.pyc` regenerated between the
 * two steps landed in the manifest and not in the archive. One implementation
 * of eligibility now drives both, and the staged set is compared against the
 * manifest before anything is packaged.
 *
 * Usage:
 *   php scripts/release/stage_tree.php --project-root=DIR --stage-dir=DIR
 *                                      [--allow-symlinks]
 */

require __DIR__.'/../support/SourceIdentity.php';

use Mulkihawler\Tooling\SourceIdentity;

$option = static function (string $name) use ($argv): ?string {
    foreach ($argv as $argument) {
        if (str_starts_with($argument, '--'.$name.'=')) {
            return substr($argument, strlen($name) + 3);
        }
    }

    return null;
};

$root = rtrim($option('project-root') ?? dirname(__DIR__, 2), '/');
$stage = $option('stage-dir');
$allowSymlinks = in_array('--allow-symlinks', $argv, true);

if ($stage === null) {
    fwrite(STDERR, "STAGING FAILED: --stage-dir is required.\n");
    exit(1);
}

$stage = rtrim($stage, '/');

// Purge anything a tool can regenerate before the set is decided. Doing this
// after the walk is what allowed the manifest and the archive to disagree.
exec('find '.escapeshellarg($root).' -name __pycache__ -type d -prune -exec rm -rf {} + 2>/dev/null');
exec('find '.escapeshellarg($root).' \( -name "*.pyc" -o -name "*.pyo" \) -delete 2>/dev/null');

/*
 * Generated browser credentials never belong in a staged tree. Purged rather
 * than merely skipped, so a delivered archive cannot carry them.
 */
@unlink($root.'/tests/Browser/support/fixtures.json');

try {
    $eligible = SourceIdentity::eligibleFiles($root, $allowSymlinks);
} catch (RuntimeException $e) {
    fwrite(STDERR, 'STAGING FAILED: '.$e->getMessage()."\n");
    exit(1);
}

sort($eligible, SORT_STRING);

if ($eligible === []) {
    fwrite(STDERR, "STAGING FAILED: no eligible files were found.\n");
    exit(1);
}

// Copy exactly the eligible set — nothing selected by a second policy.
exec('rm -rf '.escapeshellarg($stage));

if (! mkdir($stage, 0o777, true) && ! is_dir($stage)) {
    fwrite(STDERR, 'STAGING FAILED: could not create '.$stage."\n");
    exit(1);
}

foreach ($eligible as $relative) {
    $target = $stage.'/'.$relative;
    $directory = dirname($target);

    if (! is_dir($directory) && ! mkdir($directory, 0o777, true) && ! is_dir($directory)) {
        fwrite(STDERR, 'STAGING FAILED: could not create '.$directory."\n");
        exit(1);
    }

    if (! copy($root.'/'.$relative, $target)) {
        fwrite(STDERR, 'STAGING FAILED: could not copy '.$relative."\n");
        exit(1);
    }
}

// The manifest is built FROM THE STAGED SET, not from the live directory.
try {
    $built = SourceIdentity::buildManifest($stage, $allowSymlinks);
} catch (RuntimeException $e) {
    fwrite(STDERR, 'STAGING FAILED: '.$e->getMessage()."\n");
    exit(1);
}

$hash = hash('sha256', $built['manifest']);

// Both directions, against the set that will actually be archived.
$manifestSet = $built['files'];
$missing = array_diff($eligible, $manifestSet);
$extra = array_diff($manifestSet, $eligible);

if ($missing !== [] || $extra !== []) {
    fwrite(STDERR, "STAGING FAILED: the staged set and the manifest disagree.\n");

    foreach (array_slice($missing, 0, 10) as $path) {
        fwrite(STDERR, '  eligible but not in the manifest: '.$path."\n");
    }

    foreach (array_slice($extra, 0, 10) as $path) {
        fwrite(STDERR, '  in the manifest but not eligible: '.$path."\n");
    }

    exit(1);
}

/*
 * SHA256SUMS.txt is the same information in sha256sum(1) format, derived from
 * the same eligible set so `sha256sum -c` and the manifest can never disagree.
 */
foreach ([
    $stage.'/SHA256SUMS.txt' => $built['manifest'],
    $stage.'/'.SourceIdentity::MANIFEST_NAME => $built['manifest'],
    $stage.'/TREE_MANIFEST.sha256' => $hash.'  '.SourceIdentity::MANIFEST_NAME."\n",
] as $path => $contents) {
    if (file_put_contents($path, $contents) !== strlen($contents)) {
        fwrite(STDERR, 'STAGING FAILED: could not write '.$path."\n");
        exit(1);
    }
}

printf("staged files: %d\n", count($eligible));
printf("exclusion policy version: %d\n", SourceIdentity::EXCLUSION_POLICY_VERSION);
printf("stage: %s\n", $stage);
printf("final_tree_manifest_sha256: %s\n", $hash);

exit(0);
