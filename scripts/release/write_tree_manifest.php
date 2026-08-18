<?php

declare(strict_types=1);

/*
 * Write the deterministic complete tree manifest and its detached hash.
 *
 * The manifest is the identity of the source as it stands — not the baseline
 * commit, which is only an ancestor. Its hash is written to a SEPARATE file so
 * it can travel outside the archive it describes; a manifest checked only
 * against itself proves nothing, because an edit can rewrite both.
 *
 * Usage:
 *   php scripts/release/write_tree_manifest.php [--project-root=DIR]
 *                                               [--output-dir=DIR]
 *                                               [--allow-symlinks]
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

$root = $option('project-root') ?? dirname(__DIR__, 2);
$outputDir = $option('output-dir') ?? $root;
$allowSymlinks = in_array('--allow-symlinks', $argv, true);

try {
    $built = SourceIdentity::buildManifest($root, $allowSymlinks);
} catch (RuntimeException $e) {
    fwrite(STDERR, 'MANIFEST FAILED: '.$e->getMessage()."\n");
    exit(1);
}

if ($built['files'] === []) {
    fwrite(STDERR, "MANIFEST FAILED: no eligible files were found.\n");
    exit(1);
}

$manifestPath = rtrim($outputDir, '/').'/'.SourceIdentity::MANIFEST_NAME;
$hashPath = rtrim($outputDir, '/').'/'.str_replace('.txt', '.sha256', SourceIdentity::MANIFEST_NAME);
$hash = hash('sha256', $built['manifest']);
$hashLine = $hash.'  '.SourceIdentity::MANIFEST_NAME."\n";

/*
 * Announcing success without checking the write is how a release command comes
 * to report a manifest that is not on disk. Every step below is checked, the
 * write is atomic so a partial file cannot be mistaken for a complete one, and
 * the bytes are read back and re-hashed before anything is printed.
 */
if (! is_dir($outputDir) && ! @mkdir($outputDir, 0o777, true) && ! is_dir($outputDir)) {
    fwrite(STDERR, 'MANIFEST FAILED: the output directory could not be created: '.$outputDir."\n");
    exit(1);
}

if (! is_writable($outputDir)) {
    fwrite(STDERR, 'MANIFEST FAILED: the output directory is not writable: '.$outputDir."\n");
    exit(1);
}

/**
 * Write via a temporary file in the same directory, then rename. A rename
 * within one filesystem is atomic, so a reader never sees half a manifest.
 */
$writeAtomic = static function (string $path, string $contents): bool {
    $temporary = $path.'.tmp-'.bin2hex(random_bytes(6));
    $written = @file_put_contents($temporary, $contents, LOCK_EX);

    if ($written === false || $written !== strlen($contents)) {
        @unlink($temporary);

        return false;
    }

    if (! @rename($temporary, $path)) {
        @unlink($temporary);

        return false;
    }

    return true;
};

foreach ([[$manifestPath, $built['manifest']], [$hashPath, $hashLine]] as [$path, $contents]) {
    if (! $writeAtomic($path, $contents)) {
        fwrite(STDERR, 'MANIFEST FAILED: could not write '.$path."\n");
        exit(1);
    }
}

// Read back and prove what is on disk is what was intended.
$writtenManifest = @file_get_contents($manifestPath);
$writtenHashLine = @file_get_contents($hashPath);

if ($writtenManifest === false || $writtenHashLine === false) {
    fwrite(STDERR, "MANIFEST FAILED: the written files could not be read back.\n");
    exit(1);
}

if (! hash_equals($hash, hash('sha256', $writtenManifest))) {
    fwrite(STDERR, "MANIFEST FAILED: the manifest on disk does not hash to the computed value.\n");
    exit(1);
}

if (trim($writtenHashLine) !== trim($hashLine)) {
    fwrite(STDERR, "MANIFEST FAILED: the detached hash file does not contain the computed hash.\n");
    exit(1);
}

printf("files: %d\n", count($built['files']));
printf("exclusion policy version: %d\n", SourceIdentity::EXCLUSION_POLICY_VERSION);
printf("manifest: %s\n", $manifestPath);
printf("final_tree_manifest_sha256: %s\n", $hash);

exit(0);
