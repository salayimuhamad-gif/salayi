<?php

declare(strict_types=1);

require_once __DIR__.'/../../scripts/support/TestTally.php';
spl_autoload_register(function (string $c): void {
    $p = str_replace(['App\\Modules\\', '\\'], ['app/Modules/', '/'], $c).'.php';
    if (is_file($p)) {
        require $p;
    }
});
if (! function_exists('filled')) {
    function filled(mixed $v): bool
    {
        return ! empty($v);
    }
}

use App\Modules\Geography\Support\Polygon;
use App\Modules\Geography\Support\Wkt;
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
$ok = static function (string $n, bool $c): void {
    if (! $c) {
        TestTally::fail();
        echo "  FAIL $n\n";
    } else {
        echo "  pass $n\n";
    }
};

$simple = 'POLYGON((44.000 36.180, 44.020 36.180, 44.020 36.200, 44.000 36.200, 44.000 36.180))';

// --- 1. Prove the ROOT CAUSE: parsePolygon returns RINGS, not one ring.
$rings = Wkt::parsePolygon($simple);
// The declared return type already says "list of lists"; what the ROOT CAUSE
// test has to prove is the NESTING — that the outer value is rings and the
// inner one is vertices, not that both happen to be arrays.
$ok('parsePolygon returns a list of rings', $rings !== [] && count($rings[0]) >= 4);
$ok('a simple polygon has exactly ONE ring', count($rings) === 1);
// The declared return type already guarantees Coordinates, so what is worth
// asserting is that the VALUES survived parsing in the right order and the ring
// closes — a transposed pair or a dropped closing vertex is the real risk.
$ok(
    'the first vertex keeps its longitude/latitude order',
    abs($rings[0][0]->longitude - 44.000) < 1.0e-9
    && abs($rings[0][0]->latitude - 36.180) < 1.0e-9,
);
$ok('the ring closes on its first vertex', Polygon::isClosed($rings[0]));

// --- 2. Prove the OLD guard skipped every ordinary polygon.
$oldGuardSkips = count($rings) < 4;         // the shipped condition
$ok('OLD code: count($rings) < 4 skipped this polygon', $oldGuardSkips === true);

// --- 3. Prove the NEW logic produces valid GeoJSON.
/**
 * @param  list<list<Coordinates>>  $rings  outer ring first, then holes
 * @return list<list<array{0: float, 1: float}>>|null
 */
function ringsToCoordinates(array $rings, float $tol): ?array
{
    if ($rings === []) {
        return null;
    }
    $out = [];
    foreach ($rings as $i => $ring) {
        $closed = Polygon::close(Polygon::simplify($ring, $tol));
        if (count($closed) < 4) {
            if ($i === 0) {
                return null;
            }

            continue;
        }
        $out[] = array_map(fn (Coordinates $p): array => [$p->longitude, $p->latitude], $closed);
    }

    return $out === [] ? null : $out;
}
$geo = ringsToCoordinates($rings, 40.0);
$ok('NEW code: simple polygon yields geometry', $geo !== null);
$ok('one exterior ring', count($geo) === 1);
$ok('ring is closed', $geo[0][0] === $geo[0][count($geo[0]) - 1]);
$ok('at least 4 positions', count($geo[0]) >= 4);
$ok('order is [lng, lat] not [lat, lng]', $geo[0][0][0] > 43.0 && $geo[0][0][1] < 37.0);

// --- 4. Holes preserved.
$withHole = 'POLYGON((44.000 36.180, 44.040 36.180, 44.040 36.220, 44.000 36.220, 44.000 36.180),'
          .'(44.010 36.190, 44.020 36.190, 44.020 36.200, 44.010 36.200, 44.010 36.190))';
$hg = ringsToCoordinates(Wkt::parsePolygon($withHole), 5.0);
$ok('hole preserved as a second ring', $hg !== null && count($hg) === 2);

// --- 5. MULTIPOLYGON parses into polygons of rings.
$multi = 'MULTIPOLYGON(((44.000 36.180, 44.010 36.180, 44.010 36.190, 44.000 36.190, 44.000 36.180)),'
       .'((44.020 36.200, 44.030 36.200, 44.030 36.210, 44.020 36.210, 44.020 36.200)))';
$polys = Wkt::parseMultiPolygon($multi);
$ok('multipolygon yields 2 polygons', count($polys) === 2);
$converted = array_values(array_filter(array_map(fn (array $r) => ringsToCoordinates($r, 5.0), $polys)));
$ok('both multipolygon parts convert', count($converted) === 2);
$ok('Wkt::type detects MULTIPOLYGON', Wkt::type($multi) === 'MULTIPOLYGON');
$ok('Wkt::type detects POLYGON', Wkt::type($simple) === 'POLYGON');

// --- 6. Malformed WKT throws (so the controller can skip it).
$threw = false;
try {
    Wkt::parsePolygon('POLYGON(((((nonsense');
} catch (Throwable) {
    $threw = true;
}
$ok('malformed WKT throws rather than returning junk', $threw);

echo TestTally::failures() === 0 ? "\nALL WKT/GEOMETRY ASSERTIONS PASSED\n" : "\n".TestTally::failures()." FAILURES\n";
exit(TestTally::exitCode());
