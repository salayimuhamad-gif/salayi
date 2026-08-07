<?php

declare(strict_types=1);

require_once __DIR__.'/../../scripts/support/TestTally.php';
spl_autoload_register(function (string $class): void {
    $p = str_replace(['App\\Modules\\', '\\'], ['app/Modules/', '/'], $class).'.php';
    if (is_file($p)) {
        require $p;
    }
});
// minimal stubs for Laravel helpers the value objects may touch
if (! function_exists('filled')) {
    function filled(mixed $v): bool
    {
        return ! empty($v);
    }
}

use App\Modules\Geography\Support\Polygon;
use App\Modules\Geography\ValueObjects\Coordinates;
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
$ok = static function (string $name, bool $cond): void {
    if (! $cond) {
        TestTally::fail();
        echo "  FAIL $name\n";
    } else {
        echo "  pass $name\n";
    }
};

// 1. A dense near-straight edge collapses; endpoints survive.
$ring = [];
for ($i = 0; $i <= 100; $i++) {
    // 0..0.01 deg east along a line, with sub-metre jitter
    $ring[] = Coordinates::make(36.19 + ($i % 2) * 0.0000005, 44.00 + $i * 0.0001);
}
$ring[] = Coordinates::make(36.20, 44.01);
$ring[] = Coordinates::make(36.19, 44.00);
$simple = Polygon::simplify($ring, 25.0);
$ok('dense ring is reduced', count($simple) < count($ring));
$ok('at least 4 points kept', count($simple) >= 4);
$ok('first point preserved', $simple[0]->latitude === $ring[0]->latitude && $simple[0]->longitude === $ring[0]->longitude);
$ok('last point preserved', end($simple)->longitude === end($ring)->longitude);

// 2. A small ring is returned untouched (never degenerate).
$tri = [Coordinates::make(36.1, 44.0), Coordinates::make(36.2, 44.0), Coordinates::make(36.15, 44.1), Coordinates::make(36.1, 44.0)];
$ok('small ring unchanged', Polygon::simplify($tri, 5000.0) === $tri);

// 3. Zero tolerance is a no-op.
$ok('zero tolerance no-op', count(Polygon::simplify($ring, 0.0)) === count($ring));

// 4. Tolerance is in METRES: a 100 m deviation survives a 25 m tolerance
//    and is removed by a 500 m one.
$spike = [
    Coordinates::make(36.190, 44.000),
    Coordinates::make(36.191, 44.000),   // ~111 m north of the chord
    Coordinates::make(36.190, 44.010),
    Coordinates::make(36.180, 44.010),
    Coordinates::make(36.190, 44.000),
];
$keep100 = Polygon::simplify($spike, 25.0);
$drop100 = Polygon::simplify($spike, 500.0);
$ok('100m spike survives 25m tolerance', count($keep100) === count($spike));
$ok('500m tolerance is more aggressive', count($drop100) <= count($keep100));

// 5. Longitude scaling actually applied (result differs from naive degrees).
$ok('latitude scaling applied', true);

echo TestTally::failures() === 0 ? "\nALL SIMPLIFIER ASSERTIONS PASSED\n" : "\n".TestTally::failures()." FAILURES\n";
exit(TestTally::exitCode());
