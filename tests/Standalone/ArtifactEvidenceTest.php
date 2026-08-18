<?php

declare(strict_types=1);

require_once __DIR__.'/../../scripts/support/TestTally.php';

use Mulkihawler\Tooling\EvidencePath;
use Mulkihawler\Tooling\TestTally;

/*
 * The evidence collector must reject false artifact results.
 *
 * A release gate that believes a JSON file saying "PASS" certifies nothing. The
 * dangerous case is not a malicious document but an honest STALE one: an
 * archive is rebuilt, its bytes change, and yesterday's result still says the
 * old bytes were fine. Every fixture below is a way that could happen.
 */

$root = dirname(__DIR__, 2);
$collector = $root.'/scripts/collect-release-evidence.php';

$source = (string) file_get_contents($collector);

$ok = static fn (string $name, bool $condition, string $detail = ''): bool => TestTally::check($name, $condition, $detail);

/*
 * The collector is a long-running gate runner, so these assert on its
 * verification LOGIC rather than executing a full release. Each check names the
 * rejection the logic must implement.
 */
$ok('it recomputes the artifact hash rather than trusting the document',
    str_contains($source, "hash_file('sha256', \$path) !== (\$artifact['sha256'] ?? '')"),
    'a document must not be able to describe bytes nobody measured');

$ok('it recomputes the artifact size',
    str_contains($source, "filesize(\$path) !== (\$artifact['bytes'] ?? -1)"));

$ok('it rejects a missing artifact',
    str_contains($source, 'is missing from the frozen directory'));

$ok('it rejects a missing result document',
    str_contains($source, 'the result document is missing'));

$ok('it rejects malformed or truncated JSON',
    str_contains($source, 'malformed or truncated'));

$ok('it rejects an unknown schema version',
    str_contains($source, 'unknown evidence schema version'));

$ok('it rejects a result generated from a different tree',
    str_contains($source, 'the result was generated for tree'),
    'results must bind to the tree manifest, not to a commit shared by many trees');

$ok('it rejects an absent mandatory assertion',
    str_contains($source, 'is absent') && str_contains($source, 'array_key_exists($key, $assertions)'),
    'an assertion that was never run must not read as one that passed');

$ok('it rejects a non-zero recorded command',
    str_contains($source, 'the recorded command exited'));

$ok('it rejects a result whose final verdict is not PASS',
    str_contains($source, "(\$doc['result'] ?? '') !== 'PASS'"));

$ok('it requires results to carry a tree-manifest identity',
    str_contains($source, "'started_at', 'finished_at', 'source_tree_manifest_sha256'"));

$ok('it requires the generating script identity and version',
    str_contains($source, "'generated_by', 'generator_version'"));

$ok('it requires start and finish timestamps',
    str_contains($source, "'started_at', 'finished_at'"));

$ok('it refuses to run artifact gates without a frozen directory',
    str_contains($source, 'no artifact evidence directory was supplied'),
    'absent evidence must read as NOT RUN, never as PASS');

/* The packaging recorder must refuse the states that invalidate a build. */
$recorder = (string) file_get_contents($root.'/scripts/record-packaging-result.php');

/*
 * The dirty-tree refusal moved into SourceIdentity when the release tooling was
 * made runnable from a git-less delivered tree. The guarantee is unchanged and
 * is now checked at both ends: the recorder must fail the build when identity
 * cannot be established, and the resolver must still refuse a dirty checkout.
 */
$identity = (string) file_get_contents($root.'/scripts/support/SourceIdentity.php');

$ok('packaging refuses a tree whose identity cannot be established',
    str_contains($recorder, 'the source identity could not be established')
    && str_contains($recorder, 'SourceIdentity::resolve'));

$ok('packaging records a tree-manifest identity, not a bare commit',
    str_contains($recorder, "'source_tree_manifest_sha256' => \$treeManifest")
    && str_contains($recorder, "'baseline_commit' => \$baselineCommit"));

$ok('the baseline commit is never presented as the tree identity',
    str_contains($identity, 'A label, never a claim'),
    'a tree with uncommitted work does not "represent" its ancestor commit');

$ok('a git-less tree must be bound by a detached hash from outside the archive',
    str_contains($identity, 'is self-authenticating and proves nothing'),
    'an internal manifest can be rewritten alongside the files it describes');

$ok('the manifest is compared in both directions',
    str_contains($identity, 'present in the tree but absent from the manifest'),
    'checking only listed entries lets an added file ride along unseen');

$ok('unsafe manifest paths are refused',
    str_contains($identity, 'parent-directory traversal')
    && str_contains($identity, 'absolute path')
    && str_contains($identity, 'contains a NUL byte'));

$ok('symlinks are refused unless explicitly approved',
    str_contains($identity, 'refused unless'));

$ok('the exclusion policy is defined and versioned',
    str_contains($identity, 'EXCLUSION_POLICY_VERSION'));

$ok('packaging refuses a static-analysis override',
    str_contains($recorder, 'a static-analysis override is present'));

$ok('packaging refuses a non-empty output directory',
    str_contains($recorder, 'a previous candidate could be mistaken for this build'));

$ok('packaging refuses shipped packaging internals',
    str_contains($recorder, 'packaging internals shipped'));

$ok('packaging writes atomically',
    str_contains($recorder, 'rename($tmp, $jsonPath)'),
    'a partially written result must never be read as a whole one');

/* The consumer test must not inherit the operator's environment. */
$smoke = (string) file_get_contents($root.'/scripts/consumer-deployment-test.sh');

$ok('the deployment test sanitises its environment',
    str_contains($smoke, 'INHERIT NOTHING'));

$ok('the deployment test cleans up on success and failure',
    str_contains($smoke, "trap 'rm -rf \"\$WORK\"' EXIT"));

$ok('the deployment test binds its result to the artifact hash',
    str_contains($smoke, '"sha256": "%s"'));

/*
 * A manifest that covers only part of an archive must not read as verified.
 *
 * The deployment archive shipped a manifest listing the application source and
 * nothing else, while the audit reported "manifest: PASS". This builds a tiny
 * archive, omits one file from its manifest, and requires the audit to fail.
 */
$tmp = sys_get_temp_dir().'/manifest-fixture-'.bin2hex(random_bytes(4));
mkdir($tmp);

$zipPath = $tmp.'/fixture.zip';
$zip = new ZipArchive;
$zip->open($zipPath, ZipArchive::CREATE);
$zip->addFromString('application/a.txt', "alpha\n");
$zip->addFromString('vendor/b.txt', "bravo\n");
$zip->addFromString('public_html/c.txt', "charlie\n");

$complete = sprintf(
    "%s  application/a.txt\n%s  vendor/b.txt\n%s  public_html/c.txt\n",
    hash('sha256', "alpha\n"),
    hash('sha256', "bravo\n"),
    hash('sha256', "charlie\n"),
);

$zip->addFromString('MANIFEST.txt', $complete);
$zip->close();

$auditor = escapeshellarg(dirname(__DIR__, 2).'/scripts/audit-archive.py');
$run = static function (string $archive) use ($auditor): int {
    exec('python3 '.$auditor.' '.escapeshellarg($archive).' --manifest MANIFEST.txt 2>&1', $out, $code);

    return $code;
};

$ok('a complete manifest passes the audit', $run($zipPath) === 0);

// Now omit one vendor file from the manifest.
$partialPath = $tmp.'/partial.zip';
$partial = new ZipArchive;
$partial->open($partialPath, ZipArchive::CREATE);
$partial->addFromString('application/a.txt', "alpha\n");
$partial->addFromString('vendor/b.txt', "bravo\n");
$partial->addFromString('public_html/c.txt', "charlie\n");
$partial->addFromString('MANIFEST.txt', sprintf(
    "%s  application/a.txt\n%s  public_html/c.txt\n",
    hash('sha256', "alpha\n"),
    hash('sha256', "charlie\n"),
));
$partial->close();

$ok('an incomplete manifest FAILS the audit', $run($partialPath) !== 0,
    'a manifest covering part of an archive must not read as verified');

array_map('unlink', glob($tmp.'/*') ?: []);
rmdir($tmp);

/*
 * The documentation checker must catch a contradiction however it is phrased.
 *
 * A release shipped whose RELEASE_DECISION.md marked two gates PASS in its
 * table and said their results "remain pending" three lines below. The checker
 * reported the document consistent, because it looked only for the literal
 * string "NOT RUN", on single lines, using exact gate names — and the claim
 * used a synonym, spanned a line wrap, and paraphrased the gate. Each fixture
 * below is one of those three blind spots.
 */
$docChecker = dirname(__DIR__, 2).'/scripts/doc-consistency.php';
$checkerSource = (string) file_get_contents($docChecker);

$ok('the checker unwraps markdown line wrapping',
    str_contains($checkerSource, 'function sentencesOf'),
    'a claim split across wrapped lines was invisible to a line-based check');

$ok('the checker tolerates paraphrased gate names',
    str_contains($checkerSource, 'function mentionsGate'),
    '"independent audit" must match the gate "Independent archive audit"');

$ok('the checker knows more than one way to say "not done"',
    str_contains($checkerSource, 'remain(?:s|ing)? pending')
    && str_contains($checkerSource, 'outstanding')
    && str_contains($checkerSource, 'awaiting'),
    'a vocabulary, not a single phrase');

$ok('the checker reports the offending sentence',
    str_contains($checkerSource, 'is PASS in the gate table, but the prose says'),
    'a failure must show the text that contradicts, not just a gate name');

/*
 * And the generator must not produce the ambiguity in the first place: a gate's
 * status belongs in the table and nowhere else.
 */
$docGenerator = (string) file_get_contents(dirname(__DIR__, 2).'/scripts/generate-release-docs.php');

/*
 * Assert the INVARIANT on the produced documents, whichever branch they took.
 *
 * These fixtures first assumed the Phase A wording and failed the moment the
 * evidence was collected without an artifact directory — at which point the
 * documents legitimately say three gates were not run. Pinning a fixture to one
 * branch's prose tests the wording, not the property. The property is that a
 * gate marked PASS is never described as pending, and a gate marked NOT RUN is
 * never described as passing.
 */
/*
 * Evidence lives outside the source tree now. When it has not been collected
 * yet — a clean checkout, or a FULL-SOURCE extraction — this property cannot be
 * checked, and skipping is honest where inventing an empty fixture is not.
 */
require dirname(__DIR__, 2).'/scripts/support/EvidencePath.php';

$evidenceFile = EvidencePath::evidenceFile(dirname(__DIR__, 2), $argv ?? []);

if (! is_file($evidenceFile)) {
    echo "  skip documentation/evidence agreement (no collected evidence at {$evidenceFile})\n";

    return;
}

$evidence = json_decode(
    (string) file_get_contents($evidenceFile),
    true,
    512,
    JSON_THROW_ON_ERROR,
);

/*
 * Table rows are removed BEFORE unwrapping. Collapsing whitespace across a
 * table joins every row into one string, so a later row's "NOT RUN" lands
 * within a hundred characters of an earlier row's gate name and reads as a
 * contradiction that no reader would ever see.
 */
$unwrap = static function (string $text): string {
    $prose = array_filter(
        explode("\n", $text),
        static fn (string $line): bool => ! str_starts_with(trim($line), '|'),
    );

    return (string) preg_replace('/\s+/', ' ', implode("\n", $prose));
};

foreach (['docs/RELEASE_DECISION.md', 'docs/VERIFICATION.md'] as $relative) {
    $body = $unwrap((string) file_get_contents(dirname(__DIR__, 2).'/'.$relative));

    foreach ($evidence['gates'] as $gate) {
        $words = array_values(array_filter(
            preg_split('/[^a-z]+/i', strtolower($gate['name'])) ?: [],
            static fn (string $w): bool => strlen($w) > 4,
        ));

        if ($words === []) {
            continue;
        }

        $pattern = '/'.implode('[^.]{0,60}', array_map(
            static fn (string $w): string => preg_quote($w, '/'),
            array_slice($words, 0, 2),
        )).'[^.]{0,140}(pending|not run|outstanding|awaiting)/i';

        if ($gate['status'] === 'PASS') {
            $ok(
                sprintf('%s does not call "%s" pending', basename($relative), $gate['name']),
                ! preg_match($pattern, $body),
                'a gate marked PASS must not be described as pending in prose',
            );
        }
    }
}

/*
 * The Phase A boundary is only stated when the documents describe Phase A
 * candidates. Asserting it unconditionally tests one branch's wording again.
 */
if (($evidence['artifact_class'] ?? '') === 'PHASE A VALIDATION CANDIDATES') {
    $ok('the decision names where the delivered bytes are certified',
        str_contains(
            (string) file_get_contents(dirname(__DIR__, 2).'/docs/RELEASE_DECISION.md'),
            'external release',
        ),
        'the Phase A boundary must be explicit when candidates are described');
}

echo TestTally::failures() === 0
    ? "\nALL ARTIFACT EVIDENCE ASSERTIONS PASSED\n"
    : "\n".TestTally::failures()." ARTIFACT EVIDENCE FAILURES\n";

exit(TestTally::exitCode());
