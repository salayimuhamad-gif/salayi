<?php

declare(strict_types=1);

/*
 * Generate the release documentation from recorded evidence.
 *
 * The audited release shipped documentation that contradicted its own files:
 * 461 PHP files claimed against 488 present, 38 migrations against 40, "no
 * PHPUnit method has ever run" against a green suite. Numbers typed by hand go
 * stale the moment anything changes, and a reader cannot tell which half is
 * true.
 *
 * Counts are therefore measured from the tree, and results are read from
 * docs/release-evidence.json, which scripts/collect-release-evidence.php
 * produces by RUNNING the gates. `--check` fails when any generated document is
 * out of date, so packaging cannot proceed on stale claims.
 *
 * Usage: php scripts/generate-release-docs.php [--check]
 */

require __DIR__.'/support/EvidencePath.php';

use Mulkihawler\Tooling\EvidencePath;

/*
 * Release tooling must not "succeed" while emitting warnings. An undefined
 * array key produced a blank identity field in a shipped document and still
 * exited 0, because the gate judged only the exit code.
 */
set_error_handler(static function (int $severity, string $message, string $file, int $line): bool {
    throw new ErrorException($message, 0, $severity, $file, $line);
});

$root = dirname(__DIR__);
$check = in_array('--check', $argv, true);

/**
 * Paths that exist while developing but are never part of a release.
 *
 * `bootstrap/cache/packages.php` and `services.php` are written by Laravel's
 * package discovery during `composer install`. The packaging step empties
 * `bootstrap/cache` down to a `.gitignore`, so they do not ship — but the file
 * count included them, and `docs/VERIFICATION.md` therefore recorded 431 PHP
 * files in the development tree against 429 in the archive. The document was
 * accurate where it was written and stale the moment it was packaged, which is
 * the whole failure mode this generator exists to prevent.
 *
 * Counting only what ships makes the figure identical in both trees, so the
 * generated documentation is reproducible from the delivered archive.
 *
 * @var list<string>
 */
const GENERATED_RUNTIME_PATHS = [
    '/bootstrap/cache/',
    '/storage/framework/',
    '/storage/logs/',
    '/vendor/',
    '/node_modules/',
];

function isGeneratedRuntimeFile(string $path): bool
{
    $normalised = str_replace('\\', '/', $path);

    foreach (GENERATED_RUNTIME_PATHS as $fragment) {
        if (str_contains($normalised, $fragment)) {
            return true;
        }
    }

    return false;
}

function countPhp(string $dir, ?string $mustContain = null): int
{
    if (! is_dir($dir)) {
        return 0;
    }

    $n = 0;

    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir)) as $f) {
        if (! $f->isFile() || $f->getExtension() !== 'php') {
            continue;
        }

        if (isGeneratedRuntimeFile($f->getPathname())) {
            continue;
        }

        if ($mustContain !== null && ! str_contains(str_replace('\\', '/', $f->getPathname()), $mustContain)) {
            continue;
        }

        $n++;
    }

    return $n;
}

$phpFiles = count(glob($root.'/*.php') ?: []);

foreach (['app', 'bootstrap', 'config', 'database', 'routes', 'scripts', 'tests', 'public'] as $d) {
    $phpFiles += countPhp($root.'/'.$d);
}

$moduleMigrations = countPhp($root.'/app', '/Database/Migrations/');
$frameworkMigrations = count(glob($root.'/database/migrations/*.php') ?: []);

$testMethods = 0;

foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root.'/tests')) as $f) {
    if ($f->isFile() && $f->getExtension() === 'php') {
        $testMethods += preg_match_all('/\n\s*public function test_\w+\(/', (string) file_get_contents($f->getPathname()));
    }
}

$modules = glob($root.'/app/Modules/*', GLOB_ONLYDIR) ?: [];
$withMigrations = array_filter($modules, static fn (string $m): bool => is_dir($m.'/Database/Migrations'));

$counts = [
    'php_files' => $phpFiles,
    'module_migrations' => $moduleMigrations,
    'framework_migrations' => $frameworkMigrations,
    'total_migrations' => $moduleMigrations + $frameworkMigrations,
    'test_methods' => $testMethods,
    'modules' => count($modules),
    'modules_with_migrations' => count($withMigrations),
];

$path = EvidencePath::evidenceFile($root, $argv);

if (! is_file($path)) {
    fwrite(STDERR, "docs/release-evidence.json is missing. Run: php scripts/collect-release-evidence.php\n");
    exit(1);
}

/** @var array<string, mixed> $e */
$e = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);

if (($e['schema_version'] ?? 0) !== 2) {
    fwrite(STDERR, "docs/release-evidence.json uses an older schema. Re-collect it.\n");
    exit(1);
}

/** @var array<string, array{name: string, status: string, result: string, command: string, exit: int}> $gates */
$gates = $e['gates'];

$failed = array_filter($gates, static fn (array $g): bool => $g['status'] === 'FAIL');
$notRun = array_filter($gates, static fn (array $g): bool => $g['status'] === 'NOT RUN');

$table = "| Gate | Status | Result | Exit |\n| --- | --- | --- | --- |\n";

foreach ($gates as $g) {
    $table .= sprintf(
        "| %s | %s | %s | %s |\n",
        $g['name'],
        $g['status'],
        str_replace('|', '/', (string) $g['result']),
        $g['exit'] === -1 ? '--' : (string) $g['exit'],
    );
}

/*
 * Validate the schema BEFORE writing anything. The generator used to read
 * `commit`, `commit_short` and `working_tree`, which the collector stopped
 * emitting when the three identities were separated. PHP filled the gaps with
 * warnings and empty strings, the documents shipped with a blank
 * "Source commit: ``", and the gate still exited 0 because it only judged the
 * exit code. Missing mandatory fields now stop the run.
 */
$required = [
    'generated_at', 'generated_at_date', 'version', 'baseline_commit',
    'final_tree_manifest_sha256', 'source_identity', 'phpunit', 'phpstan',
    'toolchain', 'gates',
];

$absent = array_values(array_filter(
    $required,
    static fn (string $field): bool => ! array_key_exists($field, $e),
));

if ($absent !== []) {
    fwrite(STDERR, 'EVIDENCE SCHEMA INVALID: missing field(s): '.implode(', ', $absent)."\n");
    exit(1);
}

if (! is_string($e['final_tree_manifest_sha256'])
    || preg_match('/^[0-9a-f]{64}$/', $e['final_tree_manifest_sha256']) !== 1) {
    fwrite(STDERR, "EVIDENCE SCHEMA INVALID: final_tree_manifest_sha256 is not a 64-character digest.\n");
    exit(1);
}

$stamp = $e['generated_at'];
$date = $e['generated_at_date'];
$version = $e['version'];
$baseline = $e['baseline_commit'] ?? null;
$treeManifest = $e['final_tree_manifest_sha256'];
$sourceArchive = $e['source_archive_sha256'] ?? null;
$identityMethod = $e['source_identity']['established_by'] ?? 'unknown';
$pu = $e['phpunit'];
$stan = $e['phpstan'];

$sourceArchiveLabel = $sourceArchive ?? 'not packaged at collection time';
$treeManifestShort = substr($treeManifest, 0, 12);
$tool = '';

foreach ($e['toolchain'] as $name => $value) {
    if ($value !== null) {
        $tool .= sprintf("%-10s %s\n", $name, $value);
    }
}

/*
 * The verdict is COMPUTED. A previous edition said NOT READY while the README
 * described a verified production package, and neither could be trusted because
 * neither was derived from anything.
 */
if ($failed !== []) {
    $decision = 'NOT READY FOR PRODUCTION';
    $blockers = "The following mandatory gate(s) failed:\n\n- "
        .implode("\n- ", array_map(
            static fn (array $g): string => $g['name'].' -- '.$g['result'],
            $failed,
        ));
} elseif ($notRun !== []) {
    $decision = 'STATIC ANALYSIS AND TEST GATES PASS / RELEASE PACKAGING NOT YET VERIFIED';
    $blockers = "No gate has failed, but the release is NOT production-ready: the\n"
        ."following gate(s) have not been executed from this commit, so nothing may\n"
        ."claim they passed.\n\n- "
        .implode("\n- ", array_map(
            static fn (array $g): string => $g['name'].' -- '.$g['result'],
            $notRun,
        ));
} elseif (($e['artifact_class'] ?? '') === 'PHASE A VALIDATION CANDIDATES') {
    /*
     * PHASE A, deliberately not READY FOR PRODUCTION.
     *
     * Every gate passed — but against VALIDATION CANDIDATES, and the final
     * artifacts are rebuilt from the commit that records this very document.
     * Their bytes therefore cannot exist yet. Declaring production readiness
     * here would certify files nobody has audited, and chasing the hashes with
     * another commit would rebuild the archives again, forever.
     *
     * Final readiness is declared in an EXTERNAL release manifest bound to the
     * final artifact hashes, which needs no further source change.
     */
    $decision = 'READY TO BUILD FINAL RELEASE ARTIFACTS';
    /*
     * The prose must not restate a gate's status in different words.
     *
     * An earlier edition said the independent audit and deployment test
     * "remain pending" while the table above marked both PASS. Each statement
     * was defensible — the table described the Phase A candidates, the prose
     * meant the not-yet-built artifacts — but a reader sees one gate name
     * carrying two opposite statuses, and that ambiguity IS the defect. The
     * table is now the only place a status is stated; the prose describes which
     * bytes were examined and says nothing about pass or pending.
     */
    $blockers = "Every gate in the table above was executed from this commit and passed.\n\n"
        ."**What those gates examined.** The archives they name are Phase A\n"
        ."validation candidates. The delivered artifacts are different bytes,\n"
        ."produced by running the packaging script again from this metadata commit\n"
        ."once it exists — so their hashes cannot appear in a document that is\n"
        ."itself an input to building them.\n\n"
        ."**Where the delivered artifacts are certified.** In the external release\n"
        ."manifest, bound to their hashes and produced after they are built.\n"
        ."Nothing in this repository describes those bytes, and no statement here\n"
        .'should be read as doing so.';
} else {
    $decision = 'READY FOR PRODUCTION';
    $blockers = 'None. Every mandatory gate passed from this commit; each result is in the table above.';
}

/*
 * The limitations text is DERIVED, so it cannot contradict the table above.
 * A previous edition listed the artifact gates as NOT RUN in prose while the
 * same document showed them PASS — stale Phase A wording that survived because
 * it was hand-written.
 */
$limitationLines = [];

if ($notRun !== []) {
    $limitationLines[] = '- The following gate(s) have not been executed from this commit and are'
        ."\n  recorded as NOT RUN rather than assumed: "
        .implode(', ', array_map(static fn (array $g): string => $g['name'], $notRun)).'.';
} else {
    $limitationLines[] = '- Every gate listed above was executed from this commit. The artifacts they'
        ."\n  describe are Phase A validation candidates; the delivered artifacts are"
        ."\n  rebuilt from this metadata commit and verified in external reports.";
}

$limitationLines[] = '- The outbox concurrency suite drives SQLite directly, because it needs a'
    ."\n  file-backed database shared between independent OS processes.";

$limitations = implode("\n", $limitationLines);

/*
 * ONE STATE OBJECT drives the verdict, the blockers and the archive
 * explanation.
 *
 * These were three separate pieces of text, and the last one was a constant. It
 * said "until the packaging gates above report PASS ... the results do not
 * exist yet" in a document whose own table marked those gates PASS. Each
 * release fixed the paragraph that had been pointed out and shipped with the
 * next one still hard-coded. Deriving all of them from one value is what stops
 * that repeating.
 */
$artifactGateKeys = ['packaging', 'content_audit', 'smoke'];
$artifactGates = array_intersect_key($gates, array_flip($artifactGateKeys));

$artifactFailed = array_filter($artifactGates, static fn (array $g): bool => $g['status'] === 'FAIL');
$artifactNotRun = array_filter($artifactGates, static fn (array $g): bool => $g['status'] === 'NOT RUN');

if ($artifactFailed !== []) {
    $artifactState = 'ARTIFACT_FAILED';
} elseif ($artifactNotRun !== []) {
    $artifactState = 'ARTIFACT_NOT_RUN';
} elseif ($artifactGates !== []) {
    $artifactState = 'PHASE_A_PASS';
} else {
    $artifactState = 'ARTIFACT_NOT_RUN';
}

$artifactClass = $e['artifact_class'] ?? 'NO ARTIFACTS VERIFIED';

$archiveMeaning = match ($artifactState) {
    'ARTIFACT_FAILED' => "Final artifact construction is blocked. The following gate(s) failed:\n\n- "
        .implode("\n- ", array_map(
            static fn (array $g): string => $g['name'].' -- '.$g['result'],
            $artifactFailed,
        ))
        ."\n\nNo archive built from this commit may be described as a release until they pass.",

    'ARTIFACT_NOT_RUN' => 'Packaging, independent archive inspection and consumer deployment '
        .'verification have not yet been executed for validation candidates from this commit, so '
        .'nothing in this tree may describe an archive as a release. Running them is the next step.',

    default => 'The Phase A packaging, independent audit and consumer deployment gates passed for '
        ."the exact validation-candidate hashes recorded in the evidence.\n\n"
        .'Those candidates are not the final deliverables. Final artifacts are rebuilt from the '
        .'Phase A metadata commit and certified in detached external evidence bound to their exact '
        .'hashes -- which is why no hash of a delivered file appears in this repository.',
};

$docs = [];

$docs['docs/VERIFICATION.md'] = <<<MD
# Verification report -- {$version}

Generated by `scripts/generate-release-docs.php` on {$date} from the tree it
describes and from `docs/release-evidence.json`, which
`scripts/collect-release-evidence.php` produces by running the gates. Do not
hand-edit: `--check` fails packaging when this file drifts.

Baseline commit (ancestor only): `{$baseline}`
Final tree-manifest SHA-256: `{$treeManifest}`
Source archive SHA-256: `{$sourceArchiveLabel}`
Source identity established by: {$identityMethod}
Evidence timestamp (UTC): {$stamp}

## Measured tree

| Measurement | Value |
| --- | --- |
| PHP files | {$counts['php_files']} |
| Module migrations | {$counts['module_migrations']} |
| Framework migrations | {$counts['framework_migrations']} |
| Total migrations | {$counts['total_migrations']} |
| PHPUnit test methods | {$counts['test_methods']} |
| Modules | {$counts['modules']} |
| Modules owning migrations | {$counts['modules_with_migrations']} |

## Toolchain

```
{$tool}
```

## Static analysis

PHPStan level {$stan['level']}: **{$stan['direct_findings']} findings** from the
direct `composer stan` command and **{$stan['measured_findings']}** from the
machine-readable measurement, with {$stan['analyser_errors']} analyser errors.
The two totals are required to agree; the collector refuses to record them
otherwise.

No baseline. No suppressions. No excluded production paths. No level reduction.

## Tests

PHPUnit: **{$pu['tests']} tests, {$pu['assertions']} assertions**,
{$pu['failures']} failures, {$pu['errors']} errors, {$pu['skipped']} skipped,
{$pu['incomplete']} incomplete, {$pu['risky']} risky (exit {$pu['exit']}).

## Gate results

{$table}
## Where each check ran

Every result above was executed against the tree whose manifest hashes to
`{$treeManifestShort}`, which
is an extraction of the clean-source archive with `vendor/` and `node_modules/`
restored from `composer.lock` and `package-lock.json`.

The status of each gate is stated once, in the table above, and nowhere else in
this document — a status repeated in prose is a status that can drift out of
step with the table it claims to summarise.
MD;

$docs['docs/ROADMAP_STATUS.md'] = <<<MD
# Roadmap status -- {$version}

Generated by `scripts/generate-release-docs.php` on {$date}. An earlier edition
repeated counts that no longer matched the tree and stated that no PHPUnit
method had ever run, which stopped being true several rounds ago.

Baseline commit (ancestor only): `{$baseline}`
Final tree-manifest SHA-256: `{$treeManifest}`

## Implementation

All {$counts['modules']} modules under `app/Modules/` carry an implementation;
{$counts['modules_with_migrations']} own migrations. The roadmap's original
"registered, empty" phase is finished -- no module is a placeholder.

## Tests written vs executed

| | Value |
| --- | --- |
| PHPUnit test methods present | {$counts['test_methods']} |
| PHPUnit tests executed | {$pu['tests']} |
| Assertions executed | {$pu['assertions']} |
| Failures / errors | {$pu['failures']} / {$pu['errors']} |
| Standalone suite | {$gates['standalone']['result']} |

## Runtime verified

| Area | Evidence |
| --- | --- |
| Migration rollback and schema parity | {$gates['migration_parity']['result']} |
| Installer behaviour under a cached config | {$gates['installer']['result']} |
| Frontend typecheck | {$gates['typecheck']['result']} |
| Frontend lint | {$gates['eslint']['result']} |
| Production build | {$gates['build']['result']} |
| Static analysis | {$gates['stan']['result']} |

## Deferred product scope

Deferred scope is product work never claimed as delivered. It is tracked in
`CHANGELOG.md` under the relevant step entries and is deliberately NOT restated
here as a second, drifting table -- one source of truth per fact.
MD;

/*
 * MACHINE-READABLE STATE.
 *
 * Three releases in a row shipped a decision document whose prose contradicted
 * its own table, and each time the checker was reading English. These markers
 * are generated from the same state the table and verdict come from, so a
 * checker can compare structure against structure and only fall back to reading
 * prose as a second line of defence.
 */
$markers = "<!-- RELEASE_ARTIFACT_STATE: {$artifactState} -->\n"
    .'<!-- RELEASE_ARTIFACT_CLASS: '.str_replace(' ', '_', $artifactClass)." -->\n"
    .'<!-- FINAL_ARTIFACT_EVIDENCE: EXTERNAL -->';

$docs['docs/RELEASE_DECISION.md'] = <<<MD
# Release decision -- {$version}

{$markers}

**Verdict: {$decision}**

Generated by `scripts/generate-release-docs.php` on {$date} from
`docs/release-evidence.json`. The verdict is computed from the gate results, not
written by hand.

Baseline commit (ancestor only): `{$baseline}`
Final tree-manifest SHA-256: `{$treeManifest}`
Evidence timestamp (UTC): {$stamp}

<!-- CURRENT_RELEASE_STATE_START -->

## Gates

{$table}
## Blockers

{$blockers}

## What this means for the archives

{$archiveMeaning}

<!-- CURRENT_RELEASE_STATE_END -->
MD;

$docs['docs/FINAL_RELEASE_VERIFICATION.md'] = <<<MD
# Final release verification -- {$version}

Generated by `scripts/generate-release-docs.php` on {$date}.

Baseline commit (ancestor only): `{$baseline}`
Final tree-manifest SHA-256: `{$treeManifest}`
Evidence schema: {$e['schema_version']}

## Toolchain

```
{$tool}
```

## Every gate, with the command that produced it

{$table}
## PHPUnit

```
tests={$pu['tests']} assertions={$pu['assertions']} failures={$pu['failures']} errors={$pu['errors']} skipped={$pu['skipped']} incomplete={$pu['incomplete']} risky={$pu['risky']} exit={$pu['exit']}
```

## PHPStan

```
direct     {$stan['direct_findings']} findings
measured   {$stan['measured_findings']} findings
analyser   {$stan['analyser_errors']} errors
level      {$stan['level']}
baseline   no
```

## Known limitations

{$limitations}
MD;

$stale = [];

/*
 * These four reports are RUN-DERIVED: they carry the gate table, the tree
 * identity and current totals. Writing them inside the source made the source
 * change whenever evidence was collected, and because they contain the
 * documentation gates' own verdicts they could never truthfully describe the
 * run that produced them. They are generated into the external evidence
 * directory instead, so the authenticated tree is untouched by collection.
 */
$reportDirectory = EvidencePath::directory($root, $argv).'/reports';

if (! is_dir($reportDirectory) && ! mkdir($reportDirectory, 0o777, true) && ! is_dir($reportDirectory)) {
    fwrite(STDERR, 'REPORTS FAILED: could not create '.$reportDirectory."\n");
    exit(1);
}

foreach ($docs as $rel => $bodyText) {
    $target = $reportDirectory.'/'.basename($rel);
    $bodyText = rtrim($bodyText)."\n";

    if (is_file($target) && file_get_contents($target) === $bodyText) {
        continue;
    }

    if ($check) {
        $stale[] = $rel;

        continue;
    }

    file_put_contents($target, $bodyText);
    echo 'wrote '.$target."\n";
}

if ($check) {
    if ($stale !== []) {
        fwrite(STDERR, "Release documentation is stale:\n  ".implode("\n  ", $stale)
            ."\nRun: php scripts/generate-release-docs.php\n");
        exit(1);
    }

    echo "release documentation is current\n";
}

exit(0);
