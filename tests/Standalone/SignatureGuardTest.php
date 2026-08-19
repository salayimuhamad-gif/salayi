<?php

declare(strict_types=1);
use Mulkihawler\Tooling\TestTally;

/*
 * The structural guard, and proof that it can actually fail.
 *
 * A guard nobody has seen fail is not evidence. The fixtures below each contain
 * exactly one defect the previous member-name-only check waved through, and the
 * self-tests assert that this guard reports each of them. Only then is it run
 * against the real tree.
 */

require_once __DIR__.'/SignatureGuard.php';
require_once __DIR__.'/../../scripts/support/TestTally.php';

$root = dirname(__DIR__, 2);
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
$ok = static function (string $name, bool $condition): void {
    if ($condition) {
        echo "  pass {$name}\n";
    } else {
        TestTally::fail();
        echo "  FAIL {$name}\n";
    }
};

/* ------------------------------------------------- self-tests on fixtures */

$fixtures = $root.'/tests/Standalone/fixtures/signature-guard';

/** Run the guard over one fixture file and return its violations. */
$analyse = static function (string $file) use ($fixtures): array {
    $guard = new SignatureGuard;
    $guard->indexDirectory($fixtures.'/subject');
    $guard->analyseFile($file);

    return $guard->violations;
};

/*
 * The `bad/` fixtures are stored as `.php.txt`.
 *
 * Each one deliberately contains a defect the guard must detect — a missing
 * argument, a protected call, an unknown option. Naming them `.php` invited
 * every other tool in the repository to analyse them as source and report the
 * very defects they exist to carry. The content is unchanged; only the
 * extension now says "sample text, not code".
 */
$expectations = [
    'MissingArgument.php' => 'needs at least 4 argument',
    'TooManyArguments.php' => 'accepts at most 2 argument',
    'StaticCallToInstanceMethod.php' => 'instance method called statically',
    'NonPublicMember.php' => 'is protected and cannot be called',
    'UnknownMember.php' => 'does not exist',
    'UnknownNamedArgument.php' => 'has no parameter $nope',
    'UnknownCommandOption.php' => 'has no option --nonexistent',
    'MissingOptionValue.php' => 'requires a value',
    'UnknownCommand.php' => 'is not declared by any command class',
    'SwitchGivenValue.php' => 'is a switch and takes no value',
];

foreach ($expectations as $file => $needle) {
    $violations = $analyse($fixtures.'/bad/'.$file.'.txt');
    $matched = false;

    foreach ($violations as $violation) {
        if (str_contains($violation, $needle)) {
            $matched = true;
            break;
        }
    }

    $ok("the guard rejects {$file}", $matched);

    if (! $matched) {
        echo '        expected a violation containing: '.$needle."\n";
        echo '        got: '.(implode(' | ', $violations) ?: '(none)')."\n";
    }
}

// And it must NOT cry wolf on correct usage.
$cleanViolations = $analyse($fixtures.'/good/CorrectUsage.php');
$ok('the guard accepts correct usage', $cleanViolations === []);

foreach ($cleanViolations as $violation) {
    echo "        false positive: {$violation}\n";
}

/* ------------------------------------------------- the real working tree */

echo "\n";

$guard = new SignatureGuard;
$guard->indexDirectory($root.'/app');
$guard->indexDirectory($root.'/tests');
$guard->analyseDirectory($root.'/tests/Feature');
$guard->analyseDirectory($root.'/app');

echo '  NOTE  '.count($guard->classes).' class(es) indexed, '
    .count($guard->commands)." artisan command(s) declared\n";
echo '  NOTE  '.$guard->checked." resolvable call site(s) verified\n";
echo '  NOTE  '.count($guard->unprovable)." call site(s) reported as not statically checkable\n";

foreach (array_slice(array_unique($guard->unprovable), 0, 10) as $entry) {
    echo "        unprovable: {$entry}\n";
}

$ok('every statically resolvable call matches its signature', $guard->violations === []);

foreach (array_unique($guard->violations) as $violation) {
    echo "        {$violation}\n";
}

echo TestTally::failures() === 0
    ? "\nALL SIGNATURE ASSERTIONS PASSED\n"
    : "\n".TestTally::failures()." SIGNATURE FAILURES\n";

exit(TestTally::exitCode());
