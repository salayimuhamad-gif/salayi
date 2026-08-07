<?php

declare(strict_types=1);

/*
 * The static-analysis counter must never turn a failed run into "0 findings".
 *
 * It did exactly that once: Larastan aborted during application bootstrap,
 * PHPStan wrote an empty file, and the collector reported zero — which looks
 * like success and would have put a false claim into the release
 * documentation. Every failure shape below must produce MEASUREMENT INVALID.
 */

require __DIR__.'/../../scripts/support/PhpstanResult.php';
require_once __DIR__.'/../../scripts/support/TestTally.php';

use Mulkihawler\Tooling\PhpstanResult;
use Mulkihawler\Tooling\TestTally;

TestTally::reset();

/*
 * A file-local closure, not a global function.
 *
 * Six standalone files each declared a global `ok()` with a DIFFERENT signature
 * — two, three and differently-named parameters. Each script runs in its own
 * process so PHP never saw a redeclaration, but PHPStan analyses them together
 * and resolved whichever declaration it read first against every call site,
 * reporting `arguments.count` against calls that were in fact correct. That was
 * the sole finding standing between this release and a build without
 * RELEASE_ALLOW_STATIC_ANALYSIS_DEBT=1.
 *
 * The closure form is what ArtifactEvidenceTest, DocConsistencyFixturesTest and
 * PackagingHygieneTest already use, so this converges the file on the pattern
 * the suite had already chosen rather than inventing a seventh convention. The
 * assertion behaviour is unchanged.
 */
$ok = static function (string $name, bool $condition, string $detail = ''): void {
    if ($condition) {
        echo "  pass {$name}\n";

        return;
    }

    TestTally::fail();
    echo "  FAIL {$name}".($detail === '' ? '' : " — {$detail}")."\n";
};

/** 1. A successful analysis WITH findings. */
$json = json_encode([
    'totals' => ['errors' => 0, 'file_errors' => 2],
    'files' => [
        'A.php' => ['messages' => [
            ['message' => 'x', 'identifier' => 'missingType.iterableValue'],
            ['message' => 'y', 'identifier' => 'nullsafe.neverNull'],
        ]],
    ],
    'errors' => [],
]);
$r = PhpstanResult::parse(1, $json);
$ok('1 findings are counted', $r->valid && $r->total === 2, $r->reason);
$ok('1 grouped by identifier', $r->byIdentifier['missingType.iterableValue'] === 1);

/** 2. A successful analysis with genuinely zero findings. */
$r = PhpstanResult::parse(0, json_encode(['files' => [], 'errors' => []]));
$ok('2 a real zero is accepted', $r->valid && $r->total === 0, $r->reason);

/** 3. Bootstrap failure — the exact defect that caused the false zero. */
$r = PhpstanResult::parse(1, json_encode([
    'files' => [],
    'errors' => ['Could not resolve APP_KEY. This message is coming from Laravel Framework itself.'],
]));
$ok('3 bootstrap failure is INVALID', ! $r->valid, 'reported '.$r->total);
$ok('3 says measurement invalid', str_contains($r->describe(), 'MEASUREMENT INVALID'));

/** 4. Empty output. */
$r = PhpstanResult::parse(1, '');
$ok('4 empty output is INVALID', ! $r->valid);

/** 5. Malformed JSON. */
$r = PhpstanResult::parse(1, '{"files": {');
$ok('5 malformed JSON is INVALID', ! $r->valid);

/** 6. Non-zero exit with a partially written result file. */
$r = PhpstanResult::parse(255, json_encode(['files' => [], 'errors' => []]));
$ok('6 non-zero exit with no findings is INVALID', ! $r->valid);

/** 7. Missing file entirely. */
$r = PhpstanResult::parse(1, null);
$ok('7 missing result file is INVALID', ! $r->valid);

/** 8. A report that disagrees with a clean exit code. */
$r = PhpstanResult::parse(0, json_encode([
    'files' => ['A.php' => ['messages' => [['message' => 'x', 'identifier' => 'z']]]],
    'errors' => [],
]));
$ok('8 clean exit with findings is INVALID', ! $r->valid);

/** 9. Truncated report with no files key. */
$r = PhpstanResult::parse(1, json_encode(['totals' => ['errors' => 0]]));
$ok('9 report without a files key is INVALID', ! $r->valid);

/** 10. The failure message keeps the command and stderr for diagnosis. */
$r = PhpstanResult::parse(1, '', 'PHP Fatal error: boom', 'composer stan');
$ok('10 failure records the command', str_contains($r->reason, 'composer stan'));
$ok('10 failure records stderr', str_contains($r->reason, 'boom'));

echo TestTally::failures() === 0
    ? "\nALL PHPSTAN MEASUREMENT ASSERTIONS PASSED\n"
    : "\n".TestTally::failures()." PHPSTAN MEASUREMENT FAILURES\n";

exit(TestTally::exitCode());
