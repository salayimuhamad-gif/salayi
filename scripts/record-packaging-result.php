<?php

declare(strict_types=1);

/*
 * Run the hardened packaging script and record what it actually produced.
 *
 * A release gate that reads "packaging: PASS" from a hand-written file proves
 * nothing. This runs the command, captures its exit code, then measures the
 * artifacts on disk — basename, byte size, SHA-256 — and refuses to report
 * success unless every expected archive exists and every forbidden thing is
 * absent.
 *
 * Written atomically, so a partial result can never be read as a whole one.
 *
 * Usage: php scripts/record-packaging-result.php --out=DIR --json=FILE
 *                                                [--source-commit=<sha>]
 *
 * --source-commit is required only when packaging a tree that is not a git
 * checkout; see scripts/support/SourceIdentity.php.
 */

require __DIR__.'/support/SourceIdentity.php';

use Mulkihawler\Tooling\SourceIdentity;

$root = dirname(__DIR__);
chdir($root);

const SCHEMA_VERSION = 1;
const SCRIPT_NAME = 'record-packaging-result.php';

$options = getopt('', ['out:', 'json:']);

foreach (['out', 'json'] as $required) {
    if (! isset($options[$required])) {
        fwrite(STDERR, "usage: php scripts/record-packaging-result.php --out=DIR --json=FILE\n");
        exit(1);
    }
}

$out = rtrim((string) $options['out'], '/');
$jsonPath = (string) $options['json'];

function shellRun(string $command): array
{
    $lines = [];
    $code = 0;
    exec($command.' 2>&1', $lines, $code);

    return ['code' => $code, 'output' => implode("\n", $lines)];
}

$failures = [];

/*
 * A release must describe a commit. Packaging a dirty tree produces archives
 * whose contents match no recorded state, which is exactly how "the version we
 * shipped" stops being answerable.
 *
 * Resolved through SourceIdentity so this runs from a delivered git-less tree
 * as well as from a checkout. Previously the git failure message itself
 * satisfied the dirty-tree test, so a tree with no `.git` was reported as
 * "dirty" and the commit silently recorded as an empty string — a wrong
 * diagnosis and a missing identity from the same defect.
 */
$treeManifest = '';
$baselineCommit = null;
$sourceArchive = null;

try {
    $identity = SourceIdentity::resolve($root, $argv);
    $treeManifest = $identity->finalTreeManifestSha256;
    $baselineCommit = $identity->baselineCommit;
    $sourceArchive = $identity->sourceArchiveSha256;
} catch (RuntimeException $e) {
    $failures[] = 'the source identity could not be established: '.$e->getMessage();
}

// An override would mean the archives were built with a failing gate.
$override = getenv('RELEASE_ALLOW_STATIC_ANALYSIS_DEBT');

if ($override !== false && $override !== '' && $override !== '0') {
    $failures[] = 'a static-analysis override is present in the environment';
}

$scriptHash = hash_file('sha256', $root.'/scripts/package-release.sh');
$selfHash = hash_file('sha256', __FILE__);
$startedAt = gmdate('Y-m-d\TH:i:s\Z');

// A previous candidate in the output directory could be mistaken for a result
// of this run, so the run starts from an empty directory.
if (is_dir($out) && glob($out.'/*') !== []) {
    $failures[] = 'the output directory is not empty; a previous candidate could be mistaken for this build';
}

$command = 'bash scripts/package-release.sh '.escapeshellarg($out);
$run = $failures === [] ? shellRun($command) : ['code' => -1, 'output' => 'not executed'];

if ($failures === [] && $run['code'] !== 0) {
    $failures[] = 'the packaging command exited '.$run['code'];
}

$finishedAt = gmdate('Y-m-d\TH:i:s\Z');

// ------------------------------------------------------------- artifacts
$version = trim(shellRun('php artisan tinker --execute="echo config(\'mulkihawler.version\');"')['output']);

$expected = [
    'clean_source' => "Mulkihawler_{$version}_Clean_Source.zip",
    'production_deployment' => "Mulkihawler_{$version}_Production_Deployment.zip",
    'release_bundle' => "Mulkihawler_{$version}_Release_Bundle.zip",
];

$artifacts = [];

foreach ($expected as $role => $basename) {
    $path = $out.'/'.$basename;

    if (! is_file($path)) {
        $failures[] = "expected artifact missing: {$basename}";

        continue;
    }

    $artifacts[] = [
        'role' => $role,
        'filename' => $basename,
        'bytes' => filesize($path),
        'sha256' => hash_file('sha256', $path),
    ];
}

// Nothing in the output directory but the artifacts and the checksum file.
$unexpected = [];

foreach (glob($out.'/*') ?: [] as $entry) {
    $name = basename($entry);

    if (in_array($name, array_values($expected), true)) {
        continue;
    }

    if (in_array($name, ['SHA256SUMS.txt', 'FINAL_RELEASE_REPORT.md', 'RELEASE_DECISION.md'], true)) {
        continue;
    }

    $unexpected[] = $name;
}

if ($unexpected !== []) {
    $failures[] = 'unexpected files in the output directory: '.implode(', ', $unexpected);
}

// Packaging internals must never survive into a delivered archive.
$internals = ['bundle-list', 'file-list', 'archive-list', '.tmp'];
$internalHits = [];

foreach ($artifacts as $artifact) {
    $listing = shellRun('unzip -l '.escapeshellarg($out.'/'.$artifact['filename']));

    foreach ($internals as $needle) {
        if (str_contains($listing['output'], $needle)) {
            $internalHits[] = $artifact['filename'].' contains '.$needle;
        }
    }
}

if ($internalHits !== []) {
    $failures[] = 'packaging internals shipped: '.implode('; ', $internalHits);
}

// The script cleans its staging directory on exit, including on failure.
$stagingLeft = shellRun('find '.escapeshellarg(sys_get_temp_dir()).' -maxdepth 1 -name "tmp.*" -newermt "-10 minutes" -type d | wc -l');

$assertions = [
    'clean_tree' => $treeManifest !== '',
    'no_override' => $override === false || $override === '' || $override === '0',
    'command_exit_zero' => $run['code'] === 0,
    'all_artifacts_present' => count($artifacts) === count($expected),
    'no_unexpected_output_files' => $unexpected === [],
    'no_packaging_internals' => $internalHits === [],
    'hashes_calculated' => ! in_array(false, array_map(
        static fn (array $a): bool => $a['sha256'] !== '',
        $artifacts,
    ), true),
];

foreach ($assertions as $name => $ok) {
    if (! $ok && ! in_array($name, array_map(static fn (string $f): string => $f, $failures), true)) {
        // Already described above; the map is what the collector reads.
        continue;
    }
}

$result = $failures === [] && ! in_array(false, $assertions, true) ? 'PASS' : 'FAIL';

$document = [
    'schema_version' => SCHEMA_VERSION,
    'result_type' => 'packaging',
    'generated_by' => SCRIPT_NAME,
    'generator_version' => substr($selfHash, 0, 16),
    'packaging_script' => 'scripts/package-release.sh',
    'packaging_script_sha256' => $scriptHash,
    'source_tree_manifest_sha256' => $treeManifest,
    'baseline_commit' => $baselineCommit,
    'source_archive_sha256' => $sourceArchive,
    'artifact_role' => 'set',
    'artifacts' => $artifacts,
    'started_at' => $startedAt,
    'finished_at' => $finishedAt,
    'exit_code' => $run['code'],
    'override_used' => false,
    'commands' => ['bash scripts/package-release.sh <output-directory>'],
    'assertions' => $assertions,
    'failures' => $failures,
    'staging_directories_left' => (int) trim($stagingLeft['output']),
    'host' => ['php' => PHP_VERSION, 'platform' => PHP_OS_FAMILY],
    'result' => $result,
];

$tmp = $jsonPath.'.tmp';
file_put_contents($tmp, json_encode($document, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n");
rename($tmp, $jsonPath);   // atomic

printf("packaging result: %s\n", $result);

foreach ($artifacts as $artifact) {
    printf("  %-28s %12d  %s\n", $artifact['filename'], $artifact['bytes'], $artifact['sha256']);
}

foreach ($failures as $failure) {
    printf("  FAIL  %s\n", $failure);
}

printf("  wrote %s\n", $jsonPath);

exit($result === 'PASS' ? 0 : 1);
