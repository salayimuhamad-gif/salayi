<?php

declare(strict_types=1);

/*
 * Execute the documentation checker against real documents.
 *
 * Three consecutive releases shipped a decision document whose prose
 * contradicted its own gate table, and each repair taught the checker one more
 * English phrase: first "NOT RUN", then "remain pending", then "do not exist
 * yet". Fixtures that only inspected the checker's source for function names
 * could not have caught any of them.
 *
 * Every case below writes a complete temporary tree, runs the checker against
 * it, and asserts the exit code. The last case is the exact text that shipped.
 */

require_once __DIR__.'/../../scripts/support/TestTally.php';

use Mulkihawler\Tooling\TestTally;

$root = dirname(__DIR__, 2);
$checker = $root.'/scripts/doc-consistency.php';

$ok = static fn (string $name, bool $condition, string $detail = ''): bool => TestTally::check($name, $condition, $detail);

/**
 * Build a throwaway documentation tree and run the checker over it.
 *
 * @param  array<string, string>  $gateStatuses  gate key => PASS|FAIL|NOT RUN
 */
function runChecker(
    string $checker,
    array $gateStatuses,
    string $markerState,
    string $currentStateProse,
    string $lineEnding = "\n",
): int {
    $dir = sys_get_temp_dir().'/doc-fixture-'.bin2hex(random_bytes(5));
    mkdir($dir.'/docs', 0o777, true);

    $names = [
        'packaging' => 'Packaging script result',
        'content_audit' => 'Independent archive audit',
        'smoke' => 'Consumer deployment smoke test',
    ];

    $gates = [];
    $rows = '';

    foreach ($names as $key => $name) {
        $status = $gateStatuses[$key] ?? 'PASS';
        $gates[$key] = [
            'name' => $name,
            'status' => $status,
            'result' => 'fixture',
            'command' => 'fixture',
            'exit' => $status === 'PASS' ? 0 : 1,
        ];
        $rows .= "| {$name} | {$status} | fixture | 0 |\n";
    }

    /*
     * Evidence now lives OUTSIDE the source tree, so the fixture writes it to
     * its own evidence directory and tells the checker where to look. Keeping
     * it under docs/ would reintroduce exactly the self-reference the boundary
     * removes.
     */
    @mkdir($dir.'/evidence', 0o777, true);

    file_put_contents($dir.'/evidence/release-evidence.json', json_encode([
        'schema_version' => 2,
        'commit' => str_repeat('a', 40),
        'artifact_class' => 'PHASE A VALIDATION CANDIDATES',
        'phpunit' => ['tests' => 1, 'assertions' => 1],
        'gates' => $gates,
    ], JSON_PRETTY_PRINT));

    $document = "# Release decision -- fixture\n\n"
        ."<!-- RELEASE_ARTIFACT_STATE: {$markerState} -->\n\n"
        ."**Verdict: READY TO BUILD FINAL RELEASE ARTIFACTS**\n\n"
        ."<!-- CURRENT_RELEASE_STATE_START -->\n\n"
        ."## Gates\n\n| Gate | Status | Result | Exit |\n| --- | --- | --- | --- |\n"
        .$rows
        ."\n## What this means for the archives\n\n"
        .$currentStateProse."\n\n"
        ."<!-- CURRENT_RELEASE_STATE_END -->\n";

    if ($lineEnding !== "\n") {
        $document = str_replace("\n", $lineEnding, $document);
    }

    file_put_contents($dir.'/docs/RELEASE_DECISION.md', $document);

    exec(
        'php '.escapeshellarg($checker).' --doc-root='.escapeshellarg($dir)
            .' --evidence-dir='.escapeshellarg($dir.'/evidence').' 2>&1',
        $output,
        $code,
    );

    exec('rm -rf '.escapeshellarg($dir));

    return $code;
}

$allPass = ['packaging' => 'PASS', 'content_audit' => 'PASS', 'smoke' => 'PASS'];
$allNotRun = ['packaging' => 'NOT RUN', 'content_audit' => 'NOT RUN', 'smoke' => 'NOT RUN'];

$goodPhaseA = 'The Phase A packaging, independent audit and consumer deployment gates passed for '
    .'the exact validation-candidate hashes recorded in the evidence. Those candidates are not the '
    .'final deliverables.';

// ---- must FAIL -----------------------------------------------------------
$ok('PASS table + "until the packaging gates report PASS" is rejected',
    runChecker($checker, $allPass, 'PHASE_A_PASS',
        'Until the packaging gates above report PASS, no document may describe an archive as a release.') !== 0);

$ok('PASS table + "results do not exist yet" is rejected',
    runChecker($checker, $allPass, 'PHASE_A_PASS',
        'The packaging, content-audit and checksum results do not exist yet.') !== 0);

$ok('PASS table + wrapped "remain pending" is rejected',
    runChecker($checker, $allPass, 'PHASE_A_PASS',
        "Final artifacts must be rebuilt from this metadata\ncommit; their independent audit,"
        ." consumer deployment test and external\nchecksums remain pending.") !== 0);

$ok('PASS table + paraphrased gate name is rejected',
    runChecker($checker, $allPass, 'PHASE_A_PASS',
        'The independent audit is still pending for this commit.') !== 0);

$ok('markers say PASS but the evidence says NOT RUN is rejected',
    runChecker($checker, $allNotRun, 'PHASE_A_PASS', $goodPhaseA) !== 0);

$ok('the table disagreeing with the evidence is rejected',
    runChecker($checker, ['packaging' => 'NOT RUN', 'content_audit' => 'PASS', 'smoke' => 'PASS'],
        'PHASE_A_PASS', $goodPhaseA) !== 0,
    'a NOT RUN gate cannot sit under a PHASE_A_PASS marker');

$ok('the exact shipped contradiction is rejected (CRLF)',
    runChecker($checker, $allPass, 'PHASE_A_PASS',
        "Archives are built from the same commit these gates ran against. Until the\npackaging "
        ."gates above report PASS, no document in this tree may describe an\narchive as a "
        ."production release -- the packaging, content-audit and checksum\nresults do not exist "
        .'yet, and claiming them would be circular.', "\r\n") !== 0,
    'this is the text that shipped, wrapped exactly as it shipped');

// ---- must PASS -----------------------------------------------------------
$ok('NOT RUN table + pending wording is accepted',
    runChecker($checker, $allNotRun, 'ARTIFACT_NOT_RUN',
        'Packaging, independent archive inspection and consumer deployment verification have not '
        .'yet been executed for validation candidates from this commit.') === 0);

$ok('Phase A PASS + correct candidate wording is accepted',
    runChecker($checker, $allPass, 'PHASE_A_PASS', $goodPhaseA) === 0);

$ok('a failed artifact gate with a blocker statement is accepted',
    runChecker($checker, ['packaging' => 'FAIL', 'content_audit' => 'PASS', 'smoke' => 'PASS'],
        'ARTIFACT_FAILED',
        'Final artifact construction is blocked. The following gate failed: Packaging script '
        .'result.') === 0);

$ok('Phase A PASS wording survives CRLF line endings',
    runChecker($checker, $allPass, 'PHASE_A_PASS', $goodPhaseA, "\r\n") === 0);

echo TestTally::failures() === 0
    ? "\nALL DOCUMENTATION CONSISTENCY FIXTURES PASSED\n"
    : "\n".TestTally::failures()." DOCUMENTATION CONSISTENCY FIXTURE FAILURES\n";

exit(TestTally::exitCode());
