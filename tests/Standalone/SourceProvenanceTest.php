<?php

declare(strict_types=1);

/*
 * Provenance tests for scripts/support/SourceIdentity.php.
 *
 * Each case builds a throwaway tree on disk and asserts what the resolver does
 * with it. Source-string matching would prove only that a message exists; these
 * run the code, because the defects being guarded against were all cases where
 * the resolver ran happily and returned a wrong answer.
 *
 * Usage: php tests/Standalone/SourceProvenanceTest.php
 */

require __DIR__.'/../../scripts/support/TestTally.php';
require __DIR__.'/../../scripts/support/SourceIdentity.php';

use Mulkihawler\Tooling\SourceIdentity;
use Mulkihawler\Tooling\TestTally;

const BASELINE = '9c0188f81843cfe4786b7f72ecdc2a3fae89cd82';

$ok = static function (string $name, bool $passed, string $why = ''): void {
    if ($passed) {
        TestTally::pass();
        echo "  pass {$name}\n";

        return;
    }

    TestTally::fail();
    echo "  FAIL {$name}".($why !== '' ? "  ({$why})" : '')."\n";
};

/** Build a minimal project tree in a fresh temporary directory. */
$makeTree = static function (array $files): string {
    $root = sys_get_temp_dir().'/prov-'.bin2hex(random_bytes(6));

    foreach ($files as $relative => $contents) {
        $path = $root.'/'.$relative;
        @mkdir(dirname($path), 0o777, true);
        file_put_contents($path, $contents);
    }

    return $root;
};

$removeTree = static function (string $root) use (&$removeTree): void {
    if (! is_dir($root)) {
        return;
    }

    foreach (scandir($root) ?: [] as $entry) {
        if ($entry === '.' || $entry === '..') {
            continue;
        }

        $path = $root.'/'.$entry;

        if (is_link($path) || is_file($path)) {
            unlink($path);

            continue;
        }

        $removeTree($path);
    }

    rmdir($root);
};

/** Write TREE_MANIFEST.txt for a tree and return its detached hash. */
$writeManifest = static function (string $root): string {
    $built = SourceIdentity::buildManifest($root);
    file_put_contents($root.'/'.SourceIdentity::MANIFEST_NAME, $built['manifest']);

    return hash('sha256', $built['manifest']);
};

/** @return array{0: bool, 1: string} did it throw, and with what message */
$attempt = static function (callable $fn): array {
    try {
        $fn();

        return [false, ''];
    } catch (Throwable $e) {
        return [true, $e->getMessage()];
    }
};

$sample = [
    'composer.json' => "{\n}\n",
    'artisan' => "#!/usr/bin/env php\n",
    'app/Example.php' => "<?php\n",
];

echo "SourceIdentity provenance\n";

// ---------------------------------------------------------------- 1. clean
$root = $makeTree($sample);
$trusted = $writeManifest($root);

$identity = SourceIdentity::resolve($root, [
    '--baseline-commit='.BASELINE,
    '--trusted-manifest-sha256='.$trusted,
]);

$ok('a clean git-less source resolves', $identity->source === SourceIdentity::SOURCE_MANIFEST);
$ok('it verifies every eligible file', $identity->filesVerified === 3);
$ok('the manifest hash is externally bound', $identity->externallyBound);

// -------------------------- 2. modified tree still carrying a baseline commit
$ok('the baseline commit is recorded as an ancestor, not as the tree identity',
    $identity->baselineCommit === BASELINE
    && $identity->finalTreeManifestSha256 !== BASELINE
    && strlen($identity->finalTreeManifestSha256) === 64,
    'a 40-character commit must never end up in the tree-identity field');

$ok('the tree identity is the manifest hash',
    $identity->finalTreeManifestSha256 === $trusted);

// ------------------------------------------------- deterministic manifest
$again = SourceIdentity::buildManifest($root);
$ok('the manifest is deterministic', hash('sha256', $again['manifest']) === $trusted);

$removeTree($root);

// ------------------------------------------------------------ 3. missing file
$root = $makeTree($sample);
$trusted = $writeManifest($root);
unlink($root.'/app/Example.php');
[$threw, $message] = $attempt(static fn () => SourceIdentity::resolve($root, [
    '--trusted-manifest-sha256='.$trusted,
]));
$ok('a missing file is rejected', $threw && str_contains($message, 'missing'));
$removeTree($root);

// ------------------------------------------------------- 4. extra unlisted file
$root = $makeTree($sample);
$trusted = $writeManifest($root);
file_put_contents($root.'/app/Sneaked.php', "<?php\n");
[$threw, $message] = $attempt(static fn () => SourceIdentity::resolve($root, [
    '--trusted-manifest-sha256='.$trusted,
]));
$ok('an extra unlisted file is rejected',
    $threw && str_contains($message, 'absent from the manifest'),
    'checking only listed entries lets an added file ride along unseen');
$removeTree($root);

// ------------------------------------------------------ 5. duplicate manifest path
$root = $makeTree($sample);
$built = SourceIdentity::buildManifest($root);
$duplicated = $built['manifest'].hash_file('sha256', $root.'/composer.json').'  composer.json'."\n";
file_put_contents($root.'/'.SourceIdentity::MANIFEST_NAME, $duplicated);
[$threw, $message] = $attempt(static fn () => SourceIdentity::resolve($root, [
    '--trusted-manifest-sha256='.hash('sha256', $duplicated),
]));
$ok('a duplicate manifest path is rejected', $threw && str_contains($message, 'duplicate'));
$removeTree($root);

// ------------------------------------------------------------- 6. absolute path
$ok('an absolute path is refused',
    SourceIdentity::unsafePathReason('/etc/passwd') === 'absolute path');

$ok('a Windows absolute path is refused',
    SourceIdentity::unsafePathReason('C:\\windows\\system32') !== null);

// ------------------------------------------------------------ 7. ../ traversal
$ok('parent-directory traversal is refused',
    SourceIdentity::unsafePathReason('../outside.php') === 'parent-directory traversal');

$ok('traversal is refused mid-path',
    SourceIdentity::unsafePathReason('app/../../outside.php') === 'parent-directory traversal');

$ok('a NUL byte is refused',
    SourceIdentity::unsafePathReason("app/Example.php\0.txt") === 'contains a NUL byte');

$ok('a safe path is accepted',
    SourceIdentity::unsafePathReason('app/Modules/Identity/Models/User.php') === null);

// A refused path must be refused end-to-end, not merely by the helper.
$root = $makeTree($sample);
$built = SourceIdentity::buildManifest($root);
$poisoned = $built['manifest'].str_repeat('a', 64).'  ../outside.php'."\n";
file_put_contents($root.'/'.SourceIdentity::MANIFEST_NAME, $poisoned);
[$threw, $message] = $attempt(static fn () => SourceIdentity::resolve($root, [
    '--trusted-manifest-sha256='.hash('sha256', $poisoned),
]));
$ok('a traversal entry is refused by the resolver, not just the validator',
    $threw && str_contains($message, 'parent-directory traversal'));
$removeTree($root);

// ----------------------------------------------------------------- 8. symlink
$root = $makeTree($sample);
$linked = symlink('/etc/passwd', $root.'/app/link.php');

if ($linked) {
    [$threw, $message] = $attempt(static fn () => SourceIdentity::buildManifest($root));
    $ok('a symlink is refused unless explicitly approved',
        $threw && str_contains($message, 'symlink'));

    [$threw] = $attempt(static fn () => SourceIdentity::buildManifest($root, true));
    $ok('an approved symlink is skipped rather than followed', ! $threw);
} else {
    $ok('a symlink is refused unless explicitly approved', false, 'symlink() failed on this filesystem');
}

$removeTree($root);

// ------------------- 9. altered manifest AND file together, no detached hash
$root = $makeTree($sample);
$writeManifest($root);
file_put_contents($root.'/app/Example.php', "<?php // tampered\n");
$rebuilt = SourceIdentity::buildManifest($root);
file_put_contents($root.'/'.SourceIdentity::MANIFEST_NAME, $rebuilt['manifest']);

[$threw, $message] = $attempt(static fn () => SourceIdentity::resolve($root, []));
$ok('a self-authenticating manifest with no detached hash is refused',
    $threw && str_contains($message, 'self-authenticating'),
    'file and manifest changed together must not verify');

// The same tree, checked against the hash the operator carried separately.
[$threw, $message] = $attempt(static fn () => SourceIdentity::resolve($root, [
    '--trusted-manifest-sha256='.hash('sha256', "not the manifest\n"),
]));
$ok('a manifest that disagrees with the detached hash is refused',
    $threw && str_contains($message, 'does not match the trusted detached hash'));
$removeTree($root);

// ------------------------------------------------------- exclusion policy
/*
 * The policy is asserted behaviourally below rather than by measuring the
 * constant arrays. PHPStan can see those are non-empty literals, so comparing
 * them against [] is a tautology it correctly refuses — and a tautology proves
 * nothing about whether the right things are actually excluded.
 */
$ok('vendor is excluded', SourceIdentity::isExcludedDir('vendor'));
$ok('a nested node_modules is excluded', SourceIdentity::isExcludedDir('resources/node_modules'));
$ok('.env is excluded', SourceIdentity::isExcludedFile('.env'));
$ok('a dated .backup variant is excluded',
    SourceIdentity::isExcludedFile('app/Foo.php.backup-20260802-014031'),
    'the audit called out .backup-* specifically');
$ok('.orig and .rej are excluded',
    SourceIdentity::isExcludedFile('app/Foo.php.orig') && SourceIdentity::isExcludedFile('app/Foo.php.rej'));
$ok('a normal dotfile is NOT excluded', ! SourceIdentity::isExcludedFile('.editorconfig'));

/*
 * Regression: a __pycache__/*.pyc regenerated between the manifest walk and the
 * archive staging appeared in TREE_MANIFEST.txt while being absent from the
 * delivered ZIP. Two different notions of "eligible" is the underlying fault;
 * these assert the one shared policy covers Python bytecode, and that a real
 * tree containing one produces a manifest that still matches the tree exactly.
 */
$ok('__pycache__ is excluded', SourceIdentity::isExcludedDir('scripts/release/__pycache__'));
$ok('a nested __pycache__ is excluded', SourceIdentity::isExcludedDir('a/b/__pycache__'));
$ok('.pyc is excluded', SourceIdentity::isExcludedFile('scripts/release/__pycache__/b.cpython-312.pyc'));
$ok('.pyo is excluded', SourceIdentity::isExcludedFile('scripts/x.pyo'));
$ok('a real .py source is NOT excluded', ! SourceIdentity::isExcludedFile('scripts/release/build.py'));

$root = $makeTree($sample + ['scripts/release/build.py' => "print(1)\n"]);
@mkdir($root.'/scripts/release/__pycache__', 0o777, true);
file_put_contents($root.'/scripts/release/__pycache__/build.cpython-312.pyc', "\x00bytecode");

$built = SourceIdentity::buildManifest($root);
$listed = array_map(static fn (string $l): string => explode('  ', $l, 2)[1],
    array_filter(explode("\n", $built['manifest'])));

$ok('bytecode never reaches the manifest',
    ! in_array('scripts/release/__pycache__/build.cpython-312.pyc', $listed, true));

$trusted = $writeManifest($root);
[$threw, $message] = $attempt(static fn () => SourceIdentity::resolve($root, [
    '--trusted-manifest-sha256='.$trusted,
]));
$ok('a tree containing bytecode still resolves, because both sides exclude it',
    ! $threw, $message);

// The failure mode itself: bytecode created AFTER the manifest was written must
// not make the tree stop matching, because it is not eligible on either side.
file_put_contents($root.'/scripts/release/__pycache__/late.cpython-312.pyc', "\x00later");
[$threw, $message] = $attempt(static fn () => SourceIdentity::resolve($root, [
    '--trusted-manifest-sha256='.$trusted,
]));
$ok('bytecode written after the freeze does not break the manifest',
    ! $threw,
    'a regenerated .pyc must not appear as an extra eligible file');

$removeTree($root);

// ------------------------------------------------------------ bad arguments
$root = $makeTree($sample);
$trusted = $writeManifest($root);

[$threw, $message] = $attempt(static fn () => SourceIdentity::resolve($root, [
    '--baseline-commit=deadbeef',
    '--trusted-manifest-sha256='.$trusted,
]));
$ok('a malformed baseline commit is refused', $threw && str_contains($message, 'not a 40-character'));

[$threw, $message] = $attempt(static fn () => SourceIdentity::resolve($root, [
    '--trusted-manifest-sha256=nonsense',
]));
$ok('a malformed trusted hash is refused', $threw && str_contains($message, '64-character'));

$identity = SourceIdentity::resolve($root, ['--trusted-manifest-sha256='.$trusted]);
$ok('an absent baseline is null rather than invented', $identity->baselineCommit === null);
$ok('the source archive hash is null when no archive is given',
    $identity->sourceArchiveSha256 === null);

$archive = $root.'/../prov-archive-'.bin2hex(random_bytes(4)).'.zip';
file_put_contents($archive, "PK\x03\x04 not really a zip\n");
$identity = SourceIdentity::resolve($root, [
    '--trusted-manifest-sha256='.$trusted,
    '--source-archive='.$archive,
]);
$ok('the source archive hash is recorded when supplied',
    $identity->sourceArchiveSha256 === hash_file('sha256', $archive));
unlink($archive);
$removeTree($root);

echo "\n";

if (TestTally::failures() === 0) {
    echo "ALL SOURCE PROVENANCE ASSERTIONS PASSED\n";
    exit(0);
}

echo TestTally::failures()." SOURCE PROVENANCE FAILURE(S)\n";
exit(1);
