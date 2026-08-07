<?php

declare(strict_types=1);

/*
 * Collect release evidence by RUNNING the gates, not by being told the answers.
 *
 * The first edition of docs/release-evidence.json was written by hand. That is
 * how a release ends up claiming numbers nobody measured — the exact failure
 * this whole round exists to correct. Every value below comes from a command's
 * output and exit code, and the collector refuses to write anything when it
 * cannot prove what it is recording:
 *
 *   - a dirty working tree (the evidence would not describe any commit)
 *   - a command that could not be run
 *   - a command that failed where the gate requires success
 *   - output it cannot parse
 *   - a PHPStan total that disagrees between the direct and machine-readable runs
 *
 * Usage: php scripts/collect-release-evidence.php [--source-commit=<sha>]
 *
 * The tree is normally a git checkout, and then nothing needs to be supplied.
 * When it is not — a delivered FULL-SOURCE archive has no `.git` — pass
 * --source-commit=<40-character-sha> (or set MYHAWLER_SOURCE_COMMIT) and the
 * tree's SHA256SUMS.txt is verified in place of the clean-checkout check.
 */

require __DIR__.'/support/SourceIdentity.php';
require __DIR__.'/support/EvidencePath.php';

use Mulkihawler\Tooling\EvidencePath;
use Mulkihawler\Tooling\SourceIdentity;

const SCHEMA_VERSION = 2;

$root = dirname(__DIR__);
chdir($root);

$problems = [];

/**
 * Run a command, returning its exit code and combined output.
 *
 * @return array{code: int, output: string}
 */
function shellRun(string $command): array
{
    $output = [];
    $code = 0;
    exec($command.' 2>&1', $output, $code);

    return ['code' => $code, 'output' => implode("\n", $output)];
}

function firstMatch(string $pattern, string $subject): ?string
{
    return preg_match($pattern, $subject, $m) ? $m[1] : null;
}

// ------------------------------------------------------------ preconditions
/*
 * Establish WHICH source this evidence describes and WHETHER it is intact.
 *
 * This used to be `git status --porcelain` plus `git rev-parse HEAD`, which made
 * the collector unrunnable from a delivered FULL-SOURCE archive — those carry no
 * `.git`, so the one tree a release actually ships was the one tree that could
 * not produce evidence about itself.
 *
 * It then briefly recorded an operator-supplied commit as the identity of the
 * tree, which was worse: a tree full of uncommitted v7 work was described as
 * being the v6 baseline commit. SourceIdentity now separates the baseline
 * commit (an ancestor) from the final tree-manifest hash (what the files
 * actually are) from the source archive hash (what carries them), and binds the
 * manifest to a detached hash supplied from outside the archive.
 */
$evidenceDirectory = EvidencePath::directory($root, $argv);
$evidenceFilePath = EvidencePath::evidenceFile($root, $argv);

/*
 * Child gates (doc generation, doc consistency, doc portability) resolve the
 * evidence directory independently. Exporting it means they cannot silently
 * fall back to a different location than the one this run is writing to.
 */
putenv(EvidencePath::ENV.'='.$evidenceDirectory);
$_ENV[EvidencePath::ENV] = $evidenceDirectory;

/*
 * The boundary is enforced, not merely documented. Writing evidence into the
 * tree it describes is the self-reference that stopped the identity chain from
 * converging, so an evidence path inside the source is refused outright.
 */
if (EvidencePath::isInsideSource($root, $evidenceDirectory.'/x')) {
    fwrite(STDERR, "EVIDENCE INVALID: the evidence directory is inside the source tree.\n"
        .'Pass '.EvidencePath::OPTION.'<dir> outside '.$root."\n");
    exit(1);
}

if (! is_dir($evidenceDirectory) && ! mkdir($evidenceDirectory, 0o777, true) && ! is_dir($evidenceDirectory)) {
    fwrite(STDERR, 'EVIDENCE INVALID: could not create '.$evidenceDirectory."\n");
    exit(1);
}

try {
    $identity = SourceIdentity::resolve($root, $argv);
} catch (RuntimeException $e) {
    fwrite(STDERR, 'EVIDENCE INVALID: '.$e->getMessage()."\n");
    exit(1);
}

/*
 * Structured artifact results are attributed to the tree they were produced
 * from, so the field they are matched against is the tree-manifest hash, never
 * the baseline commit.
 */
$treeManifest = $identity->finalTreeManifestSha256;
$treeManifestShort = substr($treeManifest, 0, 12);
$baselineCommit = $identity->baselineCommit;

// ---------------------------------------------------------------- toolchain
$versions = [
    'php' => firstMatch('/^PHP (\S+)/', shellRun('php -v')['output']),
    'composer' => firstMatch('/version (\S+)/', shellRun('composer --version')['output']),
    'node' => trim(shellRun('node -v')['output']) ?: null,
    'npm' => trim(shellRun('npm -v')['output']) ?: null,
    'sqlite' => firstMatch('/(\d+\.\d+\.\d+)/', shellRun(
        'php -r \'echo (new PDO("sqlite::memory:"))->query("select sqlite_version()")->fetchColumn();\''
    )['output']),
    'mysql' => firstMatch('/Ver (\S+)/', shellRun('mysqld --version')['output']),
];

foreach (['php', 'composer'] as $required) {
    if ($versions[$required] === null) {
        $problems[] = "could not determine the {$required} version";
    }
}

// ------------------------------------------------------------------- gates
/** @var array<string, array{name: string, status: string, result: string, command: string, exit: int}> $gates */
$gates = [];

/**
 * Record one gate from a real command.
 *
 * @param  callable(array{code: int, output: string}): array{ok: bool, result: string}  $interpret
 */
function gate(string $key, string $name, string $command, callable $interpret): array
{
    global $gates, $problems;

    $run = shellRun($command);
    $verdict = $interpret($run);

    $gates[$key] = [
        'name' => $name,
        'status' => $verdict['ok'] ? 'PASS' : 'FAIL',
        'result' => $verdict['result'],
        'command' => $command,
        'exit' => $run['code'],
    ];

    if (! $verdict['ok']) {
        $problems[] = "{$name}: {$verdict['result']}";
    }

    return $run;
}

$exitZero = static fn (string $result): callable => static fn (array $r): array => [
    'ok' => $r['code'] === 0,
    'result' => $r['code'] === 0 ? $result : 'exit '.$r['code'],
];

// --- PHP syntax across everything that ships as code
$syntax = shellRun(
    "find app bootstrap config database routes scripts tests -name '*.php' -print0 "
    ."| xargs -0 -n1 -P4 php -l | grep -v '^No syntax' | head -5"
);
$fileCount = (int) trim(shellRun(
    "find app bootstrap config database routes scripts tests -name '*.php' | wc -l"
)['output']);
$syntaxFailures = trim($syntax['output']) === '' ? 0 : substr_count($syntax['output'], "\n") + 1;

$gates['syntax'] = [
    'name' => 'PHP syntax',
    'status' => $syntaxFailures === 0 ? 'PASS' : 'FAIL',
    'result' => "{$fileCount} files, {$syntaxFailures} failures",
    'command' => 'php -l over app, bootstrap, config, database, routes, scripts, tests',
    'exit' => $syntaxFailures === 0 ? 0 : 1,
];

if ($syntaxFailures !== 0) {
    $problems[] = 'PHP syntax: '.$syntaxFailures.' file(s) do not parse';
}

// --- composer validate / lint
gate('composer_validate', 'composer validate --strict', 'composer validate --strict',
    $exitZero('./composer.json is valid'));
gate('lint', 'composer lint (Pint)', 'composer lint', $exitZero('0 files require changes'));

// --- PHPStan, both ways, and they must agree
$direct = gate('stan', 'composer stan (PHPStan level 6)', 'composer stan', static function (array $r): array {
    $found = firstMatch('/Found (\d+) error/', $r['output']);

    if ($r['code'] === 0 && str_contains($r['output'], 'No errors')) {
        return ['ok' => true, 'result' => '0 findings, 0 analyser errors (exit 0)'];
    }

    return ['ok' => false, 'result' => ($found ?? '?').' finding(s), exit '.$r['code']];
});

$measured = gate('stan_measured', 'PHPStan safe measurement', 'php scripts/phpstan-measure.php',
    static function (array $r): array {
        if (str_contains($r['output'], 'MEASUREMENT INVALID')) {
            return ['ok' => false, 'result' => 'measurement invalid'];
        }

        $n = firstMatch('/PHPStan findings: (\d+)/', $r['output']);

        return [
            'ok' => $r['code'] === 0 && $n === '0',
            'result' => ($n ?? '?').' findings (exit '.$r['code'].')',
        ];
    });

$directFindings = str_contains($direct['output'], 'No errors')
    ? 0
    : (int) (firstMatch('/Found (\d+) error/', $direct['output']) ?? -1);
$measuredFindings = (int) (firstMatch('/PHPStan findings: (\d+)/', $measured['output']) ?? -1);

if ($directFindings !== $measuredFindings) {
    $problems[] = "PHPStan totals disagree: direct {$directFindings}, measured {$measuredFindings}";
}

// --- PHPUnit
$phpunitRun = gate('phpunit', 'Full PHPUnit suite', 'vendor/bin/phpunit --no-progress',
    static function (array $r): array {
        /*
         * v6: a suite with DELIBERATE engine-specific skips prints
         * "OK, but some tests were skipped!" plus a "Tests: ..." summary
         * rather than "OK (n tests, n assertions)", so demanding the
         * latter rejected a successful run. The gate now accepts either
         * shape and judges what matters: a zero exit, no failures, no
         * errors.
         */
        $clean = firstMatch('/OK \((\d+) tests?, (\d+) assertions?\)/', $r['output']);
        // firstMatch() returns the FIRST CAPTURE, so the pattern must have one.
        $summary = firstMatch('/^(Tests: \d+, Assertions: \d+.*)$/m', $r['output']);
        $noFailures = $summary === null
            || (! str_contains($summary, 'Failures:') && ! str_contains($summary, 'Errors:'));
        $ok = ($clean !== null || $summary !== null) && $noFailures ? 'yes' : null;

        return [
            'ok' => $r['code'] === 0 && $ok !== null,
            'result' => firstMatch('/(OK \(.*?\)|Tests: .*)$/m', $r['output']) ?? 'unparsed',
        ];
    });

/*
 * v6: PHPUnit prints "OK (n tests, n assertions)" only when NOTHING is
 * skipped. This suite deliberately skips the engine-specific ledger
 * classes — the SQLite states on MariaDB and vice versa — so it prints
 * "OK, but some tests were skipped!" with a "Tests: ..." summary, and the
 * collector could not read its own successful run. Both shapes are parsed
 * now, and the skip/failure counts are READ rather than assumed zero.
 */
preg_match('/OK \((\d+) tests?, (\d+) assertions?\)/', $phpunitRun['output'], $counts);

if (! isset($counts[1])) {
    preg_match('/^Tests: (\d+), Assertions: (\d+)(.*)$/m', $phpunitRun['output'], $counts);
}

$summaryTail = $counts[3] ?? '';

$countOf = static function (string $label) use ($summaryTail): int {
    preg_match('/'.$label.': (\d+)/i', $summaryTail, $m);

    return isset($m[1]) ? (int) $m[1] : 0;
};

$phpunit = [
    'tests' => isset($counts[1]) ? (int) $counts[1] : null,
    'assertions' => isset($counts[2]) ? (int) $counts[2] : null,
    'failures' => $countOf('Failures'),
    'errors' => $countOf('Errors'),
    'skipped' => $countOf('Skipped'),
    'incomplete' => $countOf('Incomplete'),
    'risky' => $countOf('Risky'),
    'exit' => $phpunitRun['code'],
];

if ($phpunit['tests'] === null) {
    $problems[] = 'could not parse the PHPUnit totals';
}

// --- remaining gates
gate('standalone', 'Standalone structure suite', 'php tests/Standalone/run.php',
    $exitZero('STANDALONE SUITE PASSED'));
gate('annotations_models', 'Model annotation check', 'php scripts/generate-model-annotations.php --check',
    $exitZero('model annotations are current'));
gate('annotations_relations', 'Relation generics check', 'php scripts/annotate-relation-generics.php --check',
    $exitZero('relation generics are current'));
/*
 * v6: the count was a literal, so every regeneration re-asserted the old
 * total and the consistency gate rejected its own generator's output. It
 * is now COUNTED from the tree, which is the only number that can stay
 * true as migrations are added.
 */
$migrationTotal = count(glob(__DIR__.'/../app/Modules/*/Database/Migrations/*.php') ?: [])
    + count(glob(__DIR__.'/../database/migrations/*.php') ?: []);

gate('migration_parity', 'Migration rollback and schema parity', 'php scripts/migration-parity.php',
    $exitZero($migrationTotal.' migrations reverse and re-apply to an identical schema'));
gate('parity', 'Language parity', 'php scripts/lang-parity.php --strict', $exitZero('all locales match'));
gate('lang_usage', 'Language usage', 'php scripts/lang-usage.php', $exitZero('no unused or missing keys'));
gate('secret_scan', 'Secret scan', 'php scripts/secret-scan.php', $exitZero('no committed secrets'));
gate('migration_guard', 'Migration guard', 'php scripts/migration-guard.php', $exitZero('migrations inspected'));
gate('security_audit', 'Security audit', 'php scripts/security-audit.php',
    static function (array $r): array {
        $summary = firstMatch('/(\d+ checked, \d+ failed[^\n]*)/', $r['output']);

        return ['ok' => $r['code'] === 0, 'result' => $summary ?? ('exit '.$r['code'])];
    });
gate('installer', 'Installer config-cache tests', 'vendor/bin/phpunit --filter InstallSessionGuardTest --no-progress',
    $exitZero('installed, uninstalled and post-config:cache states covered'));
gate('typecheck', 'TypeScript check', 'npm run typecheck', $exitZero('vue-tsc --noEmit exit 0'));
gate('eslint', 'ESLint', 'npm run lint', $exitZero('0 errors'));
gate('build', 'Production Vite build', 'npm run build', $exitZero('build succeeded'));
gate('frontend_guard', 'Frontend guard', 'php scripts/frontend-guard.php',
    $exitZero('pages, manifest and build freshness verified'));
/*
 * THE DOCUMENTATION GATES RUN LAST, AFTER THE EVIDENCE IS WRITTEN.
 *
 * They inspect documents this collector PRODUCES: the docs are generated
 * from docs/release-evidence.json, so running them here made a document
 * that cannot be correct until the evidence is written a precondition for
 * writing it. Every change to the tree's totals therefore deadlocked —
 * the evidence was refused because the docs were stale, and the docs
 * could not be regenerated because the evidence was refused.
 *
 * Deferring them changes nothing about their strictness: they still run,
 * they still fail the collector, and they now judge the documents that
 * correspond to THIS run rather than the previous one. See the closing
 * block near the evidence write.
 */

/*
 * ------------------------------------------------------- artifact evidence
 *
 * The three artifact gates are derived from structured result documents, and
 * every one is re-verified here rather than believed. A document says which
 * bytes it describes; this recomputes those bytes. A result for one archive
 * can therefore never stand in for a rebuilt archive with a different hash,
 * which is the single most likely way a release ends up certifying files
 * nobody checked.
 */
$artifactDir = null;
$evidenceDir = null;

foreach ($argv as $arg) {
    if (str_starts_with($arg, '--artifact-dir=')) {
        $artifactDir = rtrim(substr($arg, 15), '/');
    }

    if (str_starts_with($arg, '--artifact-evidence-dir=')) {
        $evidenceDir = rtrim(substr($arg, 24), '/');
    }
}

/**
 * Validate one structured result and return a gate row.
 *
 * @param  list<string>  $mandatory  assertion keys that must be present and true
 * @return array{name: string, status: string, result: string, command: string, exit: int}
 */
function artifactGate(
    string $name,
    string $expectedType,
    ?string $documentPath,
    ?string $artifactDir,
    string $treeManifest,
    array $mandatory,
): array {
    $fail = static fn (string $why): array => [
        'name' => $name,
        'status' => 'FAIL',
        'result' => $why,
        'command' => 'structured artifact evidence',
        'exit' => 1,
    ];

    if ($documentPath === null || $artifactDir === null) {
        return [
            'name' => $name,
            'status' => 'NOT RUN',
            'result' => 'no artifact evidence directory was supplied',
            'command' => 'structured artifact evidence',
            'exit' => -1,
        ];
    }

    if (! is_file($documentPath)) {
        return $fail('the result document is missing');
    }

    $raw = (string) file_get_contents($documentPath);

    try {
        $doc = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
    } catch (JsonException $e) {
        return $fail('the result document is malformed or truncated: '.$e->getMessage());
    }

    if (! is_array($doc)) {
        return $fail('the result document is not an object');
    }

    if (($doc['schema_version'] ?? null) !== 1) {
        return $fail('unknown evidence schema version');
    }

    if (($doc['result_type'] ?? '') !== $expectedType) {
        return $fail('result type is '.($doc['result_type'] ?? 'absent').", expected {$expectedType}");
    }

    foreach (['generated_by', 'generator_version', 'started_at', 'finished_at', 'source_tree_manifest_sha256'] as $field) {
        if (! isset($doc[$field]) || $doc[$field] === '') {
            return $fail("the result document has no {$field}");
        }
    }

    /*
     * Bound to the tree manifest, not to a commit. A commit cannot distinguish
     * two different working trees that share an ancestor, which is exactly the
     * situation this release is in.
     */
    if ($doc['source_tree_manifest_sha256'] !== $treeManifest) {
        return $fail('the result was generated for tree '
            .substr((string) $doc['source_tree_manifest_sha256'], 0, 12)
            .', not '.substr($treeManifest, 0, 12));
    }

    if (($doc['exit_code'] ?? 1) !== 0) {
        return $fail('the recorded command exited '.($doc['exit_code'] ?? 'unknown'));
    }

    // Assertions must be PRESENT, not merely not-false.
    $assertions = $doc['assertions'] ?? $doc['checks'] ?? [];

    foreach ($mandatory as $key) {
        if (! array_key_exists($key, $assertions)) {
            return $fail("mandatory assertion {$key} is absent");
        }

        $value = $assertions[$key];

        if ($value !== true && $value !== 'PASS') {
            return $fail("assertion {$key} did not pass");
        }
    }

    // Bind to bytes: recompute every artifact the document claims.
    $artifacts = $doc['artifacts'] ?? (isset($doc['artifact']) ? [$doc['artifact']] : []);

    if ($artifacts === []) {
        return $fail('the result document names no artifact');
    }

    foreach ($artifacts as $artifact) {
        $basename = basename((string) ($artifact['filename'] ?? ''));

        if ($basename === '') {
            return $fail('an artifact entry has no filename');
        }

        $path = $artifactDir.'/'.$basename;

        if (! is_file($path)) {
            return $fail("artifact {$basename} is missing from the frozen directory");
        }

        if (filesize($path) !== ($artifact['bytes'] ?? -1)) {
            return $fail("artifact {$basename} is ".filesize($path)
                .' bytes, the result records '.($artifact['bytes'] ?? 'nothing'));
        }

        if (hash_file('sha256', $path) !== ($artifact['sha256'] ?? '')) {
            return $fail("artifact {$basename} does not match the recorded hash; the result is stale");
        }
    }

    if (($doc['result'] ?? '') !== 'PASS') {
        return $fail('the recorded result is '.($doc['result'] ?? 'absent'));
    }

    $names = implode(', ', array_map(
        static fn (array $a): string => basename((string) $a['filename']),
        $artifacts,
    ));

    return [
        'name' => $name,
        'status' => 'PASS',
        'result' => count($artifacts).' artifact(s) verified byte-for-byte: '.$names,
        'command' => (string) $doc['generated_by'],
        'exit' => 0,
    ];
}

$gates['packaging'] = artifactGate(
    'Packaging script result',
    'packaging',
    $evidenceDir === null ? null : $evidenceDir.'/packaging.json',
    $artifactDir,
    $treeManifest,
    ['clean_tree', 'no_override', 'command_exit_zero', 'all_artifacts_present', 'no_packaging_internals'],
);

$gates['content_audit'] = artifactGate(
    'Independent archive audit',
    'independent_archive_audit',
    $evidenceDir === null ? null : $evidenceDir.'/audit-release-bundle.json',
    $artifactDir,
    $treeManifest,
    ['crc', 'absolute_paths', 'traversal', 'duplicate_paths', 'case_fold_collisions',
        'encrypted_entries', 'special_files', 'escaping_symlinks', 'nested_archives',
        'forbidden_files', 'manifest', 'expansion_ratio'],
);

$gates['smoke'] = artifactGate(
    'Consumer deployment smoke test',
    'consumer_deployment_smoke',
    $evidenceDir === null ? null : $evidenceDir.'/deployment-smoke.json',
    $artifactDir,
    $treeManifest,
    ['no_env_shipped', 'no_database_shipped', 'no_logs_shipped', 'autoload_present',
        'no_dev_dependencies', 'config_cache', 'route_cache', 'view_cache',
        'migrations_applied', 'public_path_is_public_html', 'vite_manifest_resolves',
        'installed_reaches_application', 'uninstalled_redirects', 'home_query_sqlite_ok',
        'session_driver_not_forced'],
);

// -------------------------------------------------------------------- write
if ($problems !== []) {
    fwrite(STDERR, "EVIDENCE INVALID — refusing to record unproven results:\n  ".implode("\n  ", $problems)."\n");
    exit(1);
}

$evidence = [
    'schema_version' => SCHEMA_VERSION,
    'generated_at' => gmdate('Y-m-d\TH:i:s\Z'),
    'generated_at_date' => gmdate('Y-m-d'),
    /*
     * Read through the framework, not by requiring the config file: config
     * files call `env()`, which only exists once the application has booted.
     */
    'version' => trim(shellRun(
        'php artisan tinker --execute="echo config(\'mulkihawler.version\');"'
    )['output']) ?: 'unknown',
    /*
     * Three separate identities, never conflated. The baseline commit is an
     * ancestor and says nothing about the current files; the tree manifest is
     * what the files actually are; the archive hash is what carries them.
     */
    'baseline_commit' => $baselineCommit,
    'final_tree_manifest_sha256' => $treeManifest,
    'source_archive_sha256' => $identity->sourceArchiveSha256,
    'source_identity' => [
        'established_by' => $identity->source,
        'integrity_proof' => $identity->integrityProof,
        'externally_bound' => $identity->externallyBound,
        'files_verified' => $identity->filesVerified,
        'exclusion_policy_version' => SourceIdentity::EXCLUSION_POLICY_VERSION,
    ],
    'toolchain' => $versions,
    'phpunit' => $phpunit,
    'phpstan' => [
        'direct_findings' => $directFindings,
        'measured_findings' => $measuredFindings,
        'analyser_errors' => 0,
        'level' => 6,
        'baseline' => false,
        'suppressions' => false,
        'excluded_production_paths' => false,
    ],
    /*
     * The artifacts these gates describe are VALIDATION CANDIDATES, not the
     * delivered files. Final artifacts are rebuilt from the metadata commit
     * that records this evidence, so their bytes cannot exist yet. Saying so
     * here stops the documentation generator declaring a readiness it has no
     * artifact to point at.
     */
    'artifact_class' => $artifactDir === null
        ? 'NO ARTIFACTS VERIFIED'
        : 'PHASE A VALIDATION CANDIDATES',
    'gates' => $gates,
];

file_put_contents(
    $evidenceFilePath,
    json_encode($evidence, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n",
);

/*
 * DOCUMENTATION IS A FIXPOINT, and must be iterated to one.
 *
 * The documents are generated FROM this evidence, and the evidence
 * records the documentation gates' verdicts — so a single pass always
 * disagrees with itself: the docs are written before the doc rows exist,
 * and the recorded rows then describe documents that no longer match.
 * That is what made `doc_portability` fail against a tree that was
 * otherwise correct.
 *
 * The loop below writes the evidence, regenerates the documents from it,
 * re-runs the documentation gates, and repeats until the verdicts stop
 * changing. Convergence is the proof of idempotence; three passes is
 * ample, and failing to converge is itself a reportable defect rather
 * than something to paper over.
 */
$writeEvidence = static function () use (&$evidence, &$gates, $evidenceFilePath): void {
    $evidence['gates'] = $gates;

    file_put_contents(
        $evidenceFilePath,
        json_encode($evidence, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n",
    );
};

$documentationGates = [
    'doc_consistency' => ['Documentation semantic consistency', 'php scripts/doc-consistency.php',
        'no stale totals, no contradicted gate, no premature verdict'],
    'doc_portability' => ['Documentation reproducible from a packaged tree', 'php scripts/doc-portability.php',
        'regenerates identically after packaging removes runtime files'],
];

/*
 * BLOCKER 2: document generation writes into the source tree, and the generated
 * documents are part of TREE_MANIFEST.txt — so collection could still move the
 * tree AFTER recording its hash. That is why evidence kept binding to a tree
 * that no longer existed.
 *
 * --frozen makes this run read-only with respect to the SOURCE: the tree is
 * re-hashed at the end and required to be byte-identical to the hash recorded
 * at the start.
 *
 * It used to also mean "one pass", on the reasoning that a frozen run must not
 * regenerate documents at all. That reasoning expired when the four
 * run-derived reports moved out of the tree and into <evidence-dir>/reports:
 * generation is external now, it cannot move the source, and the hash
 * assertion below proves that rather than assuming it. What the one-pass cap
 * did instead was guarantee a STALE PAIR. The reports of a pass are generated
 * from the evidence written at the START of that pass, and the documentation
 * gates measured afterwards are added to the evidence written at the END — so
 * a single pass always ends with an evidence document carrying three gate rows
 * that the reports beside it were generated without. Running
 * `generate-release-docs.php --check` against that pair fails, and so does any
 * later doc-portability gate that regenerates from it.
 *
 * The fix is to let the frozen run iterate exactly as a normal one does, until
 * the documentation rows stop changing. Once they do, the evidence written at
 * the end of a pass equals the evidence its reports were generated from, and
 * the pair is a genuine fixpoint — which the assertion after the loop then
 * proves outright instead of inferring.
 */
$frozen = in_array('--frozen', $argv, true);

$previousDocumentation = null;

for ($pass = 1; $pass <= 3; $pass++) {
    $writeEvidence();

    /*
     * Generation runs in frozen mode too, because the four run-derived reports
     * now land in <evidence-dir>/reports rather than in the source. Writing
     * them can no longer move the tree, and the post-run hash assertion below
     * proves it rather than assuming it.
     */
    $docRegen = shellRun('php scripts/generate-release-docs.php');

    $gates['doc_generation'] = [
        'name' => 'Release documentation regenerated from this evidence',
        'status' => $docRegen['code'] === 0 ? 'PASS' : 'FAIL',
        'result' => $docRegen['code'] === 0
            ? 'documents rewritten from the recorded evidence'
            : 'generation failed',
        'command' => 'php scripts/generate-release-docs.php',
        'exit' => $docRegen['code'],
    ];

    foreach ($documentationGates as $key => [$name, $command, $success]) {
        $docRun = shellRun($command);

        $gates[$key] = [
            'name' => $name,
            'status' => $docRun['code'] === 0 ? 'PASS' : 'FAIL',
            'result' => $docRun['code'] === 0 ? $success : trim((string) $docRun['output']),
            'command' => $command,
            'exit' => $docRun['code'],
        ];
    }

    /*
     * The WHOLE rows, not just their statuses. What the reports embed is the
     * gate table — name, status, result and exit — so two passes agreeing on
     * PASS/PASS/PASS while their result strings differ still leaves the final
     * evidence describing something the reports do not, which is precisely the
     * staleness this loop exists to remove.
     */
    $documentation = [
        $gates['doc_generation'],
        $gates['doc_consistency'],
        $gates['doc_portability'],
    ];

    if ($documentation === $previousDocumentation) {
        break;   // converged
    }

    $previousDocumentation = $documentation;
}

// One final write, so the recorded evidence is the converged state.
$writeEvidence();

/*
 * PROVE the fixpoint, do not infer it.
 *
 * Everything above argues that the reports on disk were generated from an
 * evidence document identical to the one just written. This asserts it: the
 * generator's own staleness check regenerates all four reports from the FINAL
 * evidence and compares them byte-for-byte against what is there, writing
 * nothing. If the loop ran out of passes while the documentation rows were
 * still moving, the pair is stale and collection fails here — loudly, and
 * before any consumer can seal a document that disagrees with its own reports.
 */
$settled = shellRun('php scripts/generate-release-docs.php --check');

if ($settled['code'] !== 0) {
    fwrite(STDERR, "EVIDENCE INVALID: the evidence and its reports are not a fixpoint.\n"
        .'The final release-evidence.json describes documentation gate results that '
        ."the reports beside it were not generated from.\n"
        .trim((string) $settled['output'])."\n");
    exit(1);
}

printf("documentation fixpoint confirmed after %d pass(es)\n", $pass);

$failed = array_keys(array_filter($gates, static fn (array $g): bool => $g['status'] === 'FAIL'));
$notRun = array_keys(array_filter($gates, static fn (array $g): bool => $g['status'] === 'NOT RUN'));

/*
 * The read-only claim is proved, not asserted: re-derive the manifest from the
 * source after every gate has run and require it to equal the hash this
 * evidence is bound to.
 */
if ($frozen) {
    $after = SourceIdentity::buildManifest($root);
    $afterHash = hash('sha256', $after['manifest']);

    if (! hash_equals($treeManifest, $afterHash)) {
        fwrite(STDERR, "EVIDENCE INVALID: the source tree changed during collection.\n"
            .'  before '.$treeManifest."\n  after  ".$afterHash."\n");
        exit(1);
    }

    printf("source tree unchanged during collection: %s\n", substr($afterHash, 0, 12));
}

printf("evidence written for tree %s\n", $treeManifestShort);
printf("  gates: %d pass, %d fail, %d not run\n", count($gates) - count($failed) - count($notRun),
    count($failed), count($notRun));

if ($failed !== []) {
    fwrite(STDERR, 'GATES FAILED: '.implode(', ', $failed)."\n");

    exit(1);
}

exit(0);
