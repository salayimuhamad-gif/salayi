<?php

declare(strict_types=1);

require_once __DIR__.'/../../scripts/support/TestTally.php';

spl_autoload_register(function (string $class): void {
    $path = str_replace(['App\\Modules\\', '\\'], ['app/Modules/', '/'], $class).'.php';
    if (is_file($path)) {
        require $path;
    }
});
if (! function_exists('filled')) {
    function filled(mixed $v): bool
    {
        return ! empty($v);
    }
}

use App\Modules\Geography\Support\Polygon;
use App\Modules\Geography\Support\Topology;
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
$ok = static function (string $name, bool $condition): void {
    if ($condition) {
        echo "  pass {$name}\n";
    } else {
        TestTally::fail();
        echo "  FAIL {$name}\n";
    }
};

/*
 * The geometry predicates behind ValidWkt, exercised directly.
 *
 * ValidWkt itself needs Laravel's validator contract; these are the pure
 * functions it composes, so the rules can be proven without a framework.
 */
/**
 * @param  list<array{0: float, 1: float}>  $pairs  longitude/latitude pairs
 * @return list<Coordinates>
 */
function ring(array $pairs): array
{
    return array_map(static fn (array $p): Coordinates => Coordinates::make($p[0], $p[1]), $pairs);
}

$square = ring([[36.18, 44.00], [36.18, 44.02], [36.20, 44.02], [36.20, 44.00], [36.18, 44.00]]);
$open = ring([[36.18, 44.00], [36.18, 44.02], [36.20, 44.02], [36.20, 44.00]]);

/*
 * PRODUCTION helpers, not local reimplementations.
 *
 * Every predicate below used to be a closure defined in this file. That made
 * the suite pass whether or not ValidWkt was correct — and it was green while
 * the rule never compared MultiPolygon components at all.
 */

$ok('a closed ring is detected as closed', Topology::isClosed($square));
$ok('an open ring is detected as open', ! Topology::isClosed($open));

$ok('a square has four distinct vertices', Topology::distinctVertexCount($square) === 4);
$ok('a two-point ring has too few vertices', Topology::distinctVertexCount(ring([[1, 1], [2, 2], [1, 1]])) < 3);

$ok('a square encloses area', abs(Topology::signedArea($square)) > 1e-12);
// Three distinct points on one line: passes a vertex count, encloses nothing.
$ok('a collinear ring encloses none', abs(Topology::signedArea(ring([[0, 0], [0, 1], [0, 2], [0, 0]]))) < 1e-12);

// Bow tie: a self-intersecting ring that is closed and has four vertices.
$bowtie = ring([[0, 0], [1, 1], [0, 1], [1, 0], [0, 0]]);
$ok('a bow-tie self-intersects', Topology::ringSelfIntersects($bowtie));
$ok('a square does not self-intersect', ! Topology::ringSelfIntersects($square));

// Hole containment.
$inside = ring([[36.185, 44.005], [36.185, 44.010], [36.190, 44.010], [36.190, 44.005], [36.185, 44.005]]);
$outside = ring([[36.30, 44.30], [36.30, 44.31], [36.31, 44.31], [36.31, 44.30], [36.30, 44.30]]);

$ok('a hole inside the exterior is accepted', Topology::ringInside($inside, $square));
$ok('a hole outside the exterior is rejected', ! Topology::ringInside($outside, $square));

// Structural parsing still holds.
$ok('POLYGON parses to rings', count(Wkt::parsePolygon(
    'POLYGON((44.00 36.18, 44.02 36.18, 44.02 36.20, 44.00 36.20, 44.00 36.18))'
)) === 1);
$ok('MULTIPOLYGON parses to polygons', count(Wkt::parseMultiPolygon(
    'MULTIPOLYGON(((44.00 36.18, 44.01 36.18, 44.01 36.19, 44.00 36.18)),'
    .'((44.02 36.20, 44.03 36.20, 44.03 36.21, 44.02 36.20)))'
)) === 2);

$threw = false;
try {
    Wkt::parsePolygon('POLYGON(((((broken');
} catch (Throwable) {
    $threw = true;
}
$ok('malformed WKT throws', $threw);

/* --------------------------------------------- topology beyond vertices */

/*
 * Every case below defeats a vertex-only test. That was the previous
 * implementation, and each of these renders as a plausible boundary while
 * being topologically wrong.
 */

// A plus sign: two rectangles whose vertices all lie outside each other,
// whose edges cross. Vertex containment reports "disjoint".
$horizontal = ring([[4, 0], [4, 10], [6, 10], [6, 0], [4, 0]]);
$vertical = ring([[0, 4], [10, 4], [10, 6], [0, 6], [0, 4]]);

$noVertexInside = true;
foreach ($horizontal as $p) {
    if (Topology::strictlyContains($vertical, $p)) {
        $noVertexInside = false;
    }
}
foreach ($vertical as $p) {
    if (Topology::strictlyContains($horizontal, $p)) {
        $noVertexInside = false;
    }
}

$ok('cross shape defeats vertex-only containment', $noVertexInside);
$ok('cross shape IS caught by edge crossing', Topology::ringsShareAnEdge($horizontal, $vertical));

// Collinear overlap: two segments on the same line sharing a span. Strict
// orientation tests report 0 for all four points and find no crossing.
$s1 = Coordinates::make(0, 0);
$s2 = Coordinates::make(0, 10);
$s3 = Coordinates::make(0, 5);
$s4 = Coordinates::make(0, 15);
$s5 = Coordinates::make(0, 20);

$ok('collinear overlap is missed by strict crossing', ! Topology::segmentsCross($s1, $s2, $s3, $s4));
$ok('collinear overlap IS caught by the span test', Topology::segmentsOverlapCollinearly($s1, $s2, $s3, $s4));
$ok('collinear but disjoint segments do not overlap', ! Topology::segmentsOverlapCollinearly($s1, $s2, $s5, Coordinates::make(0, 25)));
// Touching at exactly one point is legitimate adjacency, not overlap.
$ok('segments touching at one endpoint are not overlapping',
    ! Topology::segmentsOverlapCollinearly($s1, $s2, $s2, $s5));

/*
 * A concave (L-shaped) exterior. A hole with both vertices inside the polygon
 * can still have its connecting edge sweep out through the notch.
 */
$lShape = ring([[0, 0], [0, 10], [4, 10], [4, 4], [10, 4], [10, 0], [0, 0]]);
/*
 * The notch is lat>4 AND lng>4 — outside the L. A hole with one vertex in the
 * left arm and one in the bottom arm has both INSIDE, while the edge joining
 * them passes straight through the notch. My first attempt at this fixture put
 * both vertices in the same arm, so the hole really was contained and the test
 * failed for being wrong rather than the code being right.
 */
$holeAcrossNotch = ring([[2, 9], [8, 2], [8, 1], [2, 8], [2, 9]]);

$verticesInside = true;
foreach ($holeAcrossNotch as $p) {
    if (! Topology::strictlyContains($lShape, $p)) {
        $verticesInside = false;
    }
}

$ok('L-shaped exterior: sampled hole vertices appear inside', $verticesInside);
$ok('hole edge crossing the concave notch IS detected',
    ! Topology::ringInside($holeAcrossNotch, $lShape));

// MultiPolygon components must be disjoint.
$partA = ring([[0, 0], [0, 5], [5, 5], [5, 0], [0, 0]]);
$partB = ring([[3, 3], [3, 8], [8, 8], [8, 3], [3, 3]]);
$partC = ring([[20, 20], [20, 25], [25, 25], [25, 20], [20, 20]]);

$ok('overlapping multipolygon components are detected', Topology::ringsOverlap($partA, $partB));
$ok('disjoint multipolygon components are accepted', ! Topology::ringsOverlap($partA, $partC));

/* ------------------------- complete component comparison (holes included) */

/*
 * Exterior-only comparison is wrong in BOTH directions: it rejects a valid
 * enclave and it can miss shared fill. These call the production helper.
 */

// A big square with a hole, and a small square sitting inside that hole.
$donutExterior = ring([[0, 0], [0, 20], [20, 20], [20, 0], [0, 0]]);
$donutHole = ring([[5, 5], [5, 15], [15, 15], [15, 5], [5, 5]]);
$enclave = ring([[7, 7], [7, 13], [13, 13], [13, 7], [7, 7]]);

$donut = [$donutExterior, $donutHole];
$island = [$enclave];

$ok('an enclave sits inside the other component\'s hole',
    Topology::componentNestedInHole($island, $donut));
$ok('an enclave is NOT a filled overlap',
    ! Topology::componentsOverlap($donut, $island));
$ok('exterior-only comparison would wrongly reject it',
    Topology::ringsOverlap($donutExterior, $enclave));

// Genuine overlap: two plain squares sharing area.
$squareA = [ring([[0, 0], [0, 10], [10, 10], [10, 0], [0, 0]])];
$squareB = [ring([[5, 5], [5, 15], [15, 15], [15, 5], [5, 5]])];

$ok('genuinely overlapping components are rejected',
    Topology::componentsOverlap($squareA, $squareB));

// Disjoint stays disjoint.
$squareFar = [ring([[50, 50], [50, 60], [60, 60], [60, 50], [50, 50]])];

$ok('disjoint components are accepted', ! Topology::componentsOverlap($squareA, $squareFar));

// filledContains: inside the hole is NOT filled.
$ok('a point in the hole is not filled', ! Topology::filledContains($donut, Coordinates::make(10, 10)));
$ok('a point in the ring wall is filled', Topology::filledContains($donut, Coordinates::make(2, 2)));

/* ------------------------------------------- symmetry and edge awareness */

/*
 * Order-independence is a property, not a coincidence. The previous
 * implementation tested one direction first and returned early, so the answer
 * depended on the order components appeared in the WKT string.
 */
$ok('overlap is symmetric for a genuine overlap',
    Topology::componentsOverlap($squareA, $squareB) === Topology::componentsOverlap($squareB, $squareA));
$ok('overlap is symmetric for an enclave',
    Topology::componentsOverlap($donut, $island) === Topology::componentsOverlap($island, $donut));
$ok('overlap is symmetric for disjoint components',
    Topology::componentsOverlap($squareA, $squareFar) === Topology::componentsOverlap($squareFar, $squareA));

/*
 * Vertices inside the hole, edge crossing out of it. Vertex-only containment
 * calls this an enclave and suppresses a real overlap.
 */
$escapingExterior = ring([[7, 7], [7, 13], [25, 13], [25, 7], [7, 7]]);   // extends past the hole
$escaping = [$escapingExterior];

$ok('a component escaping the hole is not treated as an enclave',
    ! Topology::componentNestedInHole($escaping, $donut));
$ok('an escaping component overlaps, both ways',
    Topology::componentsOverlap($donut, $escaping) && Topology::componentsOverlap($escaping, $donut));

// A true enclave still passes, both ways.
$ok('a true enclave is nested, and not an overlap, both ways',
    Topology::componentNestedInHole($island, $donut)
    && ! Topology::componentsOverlap($donut, $island)
    && ! Topology::componentsOverlap($island, $donut));

echo TestTally::failures() === 0 ? "\nALL WKT VALIDATION ASSERTIONS PASSED\n" : "\n".TestTally::failures()." FAILURES\n";
exit(TestTally::exitCode());
