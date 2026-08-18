<?php

declare(strict_types=1);

namespace App\Modules\Geography\Support;

use App\Modules\Geography\ValueObjects\Coordinates;

/**
 * Planar topology predicates for boundary geometry.
 *
 * Extracted because the standalone suite had REIMPLEMENTED every one of these
 * as a local closure. That is the worst shape a test can take: it passes
 * whether or not the production code is correct, because it is not testing the
 * production code at all. The MultiPolygon overlap check proved that exactly —
 * the closure worked, ValidWkt never called the real predicate, and the suite
 * was green while the rule accepted overlapping components.
 *
 * Everything here is pure: coordinates in, boolean out. No Laravel, so the
 * framework-free suite can call the SAME functions the rule uses.
 *
 * `Polygon::containsPoint()` is deliberately not used for topology decisions.
 * It treats boundary points as inside, which is right for "which area is this
 * project in" and wrong for "does this hole escape its exterior" — a hole
 * whose edge lies exactly along the exterior is a defect, not containment.
 */
final class Topology
{
    /** Coordinates closer than this are the same point. */
    private const EPSILON = 1e-12;

    /**
     * Orientation of the ordered triple, as a sign.
     *
     * 0 collinear, 1 clockwise, 2 counter-clockwise. The classic formulation,
     * kept because every predicate below reads more clearly in terms of it.
     */
    public static function orientation(Coordinates $p, Coordinates $q, Coordinates $r): int
    {
        $value = ($q->latitude - $p->latitude) * ($r->longitude - $q->longitude)
            - ($q->longitude - $p->longitude) * ($r->latitude - $q->latitude);

        if (abs($value) < self::EPSILON) {
            return 0;
        }

        return $value > 0 ? 1 : 2;
    }

    /**
     * Whether two segments properly cross.
     *
     * Strict: collinear overlap returns FALSE here, because all four
     * orientations are 0. That case is handled by
     * {@see segmentsOverlapCollinearly}, and keeping them separate is what
     * makes each one testable.
     */
    public static function segmentsCross(Coordinates $a, Coordinates $b, Coordinates $c, Coordinates $d): bool
    {
        return self::orientation($a, $b, $c) !== self::orientation($a, $b, $d)
            && self::orientation($c, $d, $a) !== self::orientation($c, $d, $b);
    }

    /**
     * Collinear segments sharing more than a single point.
     *
     * Two holes sharing a border are not disjoint, and a hole edge running
     * along an exterior edge is a topology error a renderer resolves
     * arbitrarily. Touching at exactly one endpoint is permitted — adjacent
     * rings legitimately meet at a corner.
     */
    public static function segmentsOverlapCollinearly(
        Coordinates $a,
        Coordinates $b,
        Coordinates $c,
        Coordinates $d,
    ): bool {
        if (self::orientation($a, $b, $c) !== 0 || self::orientation($a, $b, $d) !== 0) {
            return false;
        }

        // Project onto the dominant axis so a vertical segment is not compared
        // on a longitude span of zero.
        $useLongitude = abs($b->longitude - $a->longitude) >= abs($b->latitude - $a->latitude);

        $value = static fn (Coordinates $p): float => $useLongitude ? $p->longitude : $p->latitude;

        $firstLow = min($value($a), $value($b));
        $firstHigh = max($value($a), $value($b));
        $secondLow = min($value($c), $value($d));
        $secondHigh = max($value($c), $value($d));

        return min($firstHigh, $secondHigh) - max($firstLow, $secondLow) > self::EPSILON;
    }

    /**
     * Whether a ring crosses or overlaps itself.
     *
     * Adjacent edges share a vertex by definition and are skipped. Collinear
     * overlap counts: a ring that doubles back along its own edge encloses an
     * ambiguous area.
     *
     * @param  list<Coordinates>  $ring
     */
    public static function ringSelfIntersects(array $ring): bool
    {
        $count = count($ring) - 1;

        for ($i = 0; $i < $count; $i++) {
            for ($j = $i + 1; $j < $count; $j++) {
                if ($j === $i + 1 || ($i === 0 && $j === $count - 1)) {
                    continue;
                }

                if (self::segmentsCross($ring[$i], $ring[$i + 1], $ring[$j], $ring[$j + 1])
                    || self::segmentsOverlapCollinearly($ring[$i], $ring[$i + 1], $ring[$j], $ring[$j + 1])) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Whether any edge of one ring meets any edge of another.
     *
     * @param  list<Coordinates>  $a
     * @param  list<Coordinates>  $b
     */
    public static function ringsShareAnEdge(array $a, array $b): bool
    {
        for ($i = 0; $i < count($a) - 1; $i++) {
            for ($j = 0; $j < count($b) - 1; $j++) {
                if (self::segmentsCross($a[$i], $a[$i + 1], $b[$j], $b[$j + 1])
                    || self::segmentsOverlapCollinearly($a[$i], $a[$i + 1], $b[$j], $b[$j + 1])) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Strict point-in-ring, EXCLUDING the boundary.
     *
     * Distinct from Polygon::containsPoint(), which counts boundary points as
     * inside. For "which area contains this project" that is correct; for "is
     * this hole strictly inside its exterior" it is not, because a hole
     * touching the exterior is a defect that the inclusive test calls valid.
     *
     * @param  list<Coordinates>  $ring
     */
    public static function strictlyContains(array $ring, Coordinates $point): bool
    {
        $count = count($ring);
        $inside = false;

        for ($i = 0, $j = $count - 1; $i < $count; $j = $i++) {
            $yi = $ring[$i]->latitude;
            $xi = $ring[$i]->longitude;
            $yj = $ring[$j]->latitude;
            $xj = $ring[$j]->longitude;

            // On the edge: not strictly inside.
            if (self::orientation($ring[$j], $ring[$i], $point) === 0
                && min($xi, $xj) - self::EPSILON <= $point->longitude
                && $point->longitude <= max($xi, $xj) + self::EPSILON
                && min($yi, $yj) - self::EPSILON <= $point->latitude
                && $point->latitude <= max($yi, $yj) + self::EPSILON) {
                return false;
            }

            if (($yi > $point->latitude) !== ($yj > $point->latitude)
                && $point->longitude < ($xj - $xi) * ($point->latitude - $yi) / ($yj - $yi) + $xi) {
                $inside = ! $inside;
            }
        }

        return $inside;
    }

    /**
     * Whether an interior ring lies wholly and strictly inside an exterior.
     *
     * Vertices AND edges. On a concave exterior a hole can have every vertex
     * inside while an edge sweeps out through the notch.
     *
     * @param  list<Coordinates>  $inner
     * @param  list<Coordinates>  $outer
     */
    public static function ringInside(array $inner, array $outer): bool
    {
        foreach ($inner as $point) {
            if (! self::strictlyContains($outer, $point)) {
                return false;
            }
        }

        return ! self::ringsShareAnEdge($inner, $outer);
    }

    /**
     * Whether two rings overlap.
     *
     * Containment first, because it catches nesting cheaply; then edges,
     * because two rectangles can form a cross where every vertex of each lies
     * outside the other and their edges still intersect.
     *
     * @param  list<Coordinates>  $a
     * @param  list<Coordinates>  $b
     */
    public static function ringsOverlap(array $a, array $b): bool
    {
        foreach ($a as $point) {
            if (self::strictlyContains($b, $point)) {
                return true;
            }
        }

        foreach ($b as $point) {
            if (self::strictlyContains($a, $point)) {
                return true;
            }
        }

        return self::ringsShareAnEdge($a, $b);
    }

    /**
     * Whether two polygon components share FILLED area.
     *
     * SYMMETRIC by construction. The previous version tested one direction
     * before the other and returned early, so `componentsOverlap(A, B)` and
     * `componentsOverlap(B, A)` could disagree for a component crossing a
     * concave hole — and which answer you got depended on the order the
     * components happened to appear in the WKT string.
     *
     * The test is now stated once and applied to both directions before any
     * decision:
     *
     *   1. Exteriors that do not interact at all: disjoint, done.
     *   2. A component wholly inside the other's hole: an ENCLAVE, which is
     *      valid geometry — Erbil's administrative boundaries contain them.
     *   3. Otherwise: any point filled in both, or any boundary crossing
     *      between filled regions, means shared area.
     *
     * @param  list<list<Coordinates>>  $a  exterior first, then holes
     * @param  list<list<Coordinates>>  $b
     */
    public static function componentsOverlap(array $a, array $b): bool
    {
        $exteriorA = $a[0] ?? null;
        $exteriorB = $b[0] ?? null;

        if ($exteriorA === null || $exteriorB === null) {
            return false;
        }

        // 1. No exterior interaction at all.
        if (! self::ringsOverlap($exteriorA, $exteriorB)) {
            return false;
        }

        // 2. Enclave, checked BOTH ways before anything else so the answer
        //    cannot depend on argument order.
        if (self::componentNestedInHole($a, $b) || self::componentNestedInHole($b, $a)) {
            return false;
        }

        // 3. Filled-region intersection, both directions.
        foreach ([[$a, $b], [$b, $a]] as [$first, $second]) {
            foreach ($first[0] as $point) {
                if (self::filledContains($first, $point) && self::filledContains($second, $point)) {
                    return true;
                }
            }
        }

        /*
         * Exteriors that cross without either vertex set landing in shared
         * fill still share a region along the crossing — unless every
         * crossing is with a hole boundary, which the enclave check above has
         * already excluded.
         */
        return self::ringsShareAnEdge($exteriorA, $exteriorB);
    }

    /**
     * Whether one component sits wholly inside a hole of the other.
     *
     * Uses {@see ringInside}, which tests EDGES as well as vertices. Vertex
     * containment alone accepted a component whose corners sat in the hole
     * while an edge swept out across the hole's boundary into filled area —
     * that is an overlap, not an enclave, and calling it an enclave suppressed
     * a real error.
     *
     * @param  list<list<Coordinates>>  $inner
     * @param  list<list<Coordinates>>  $outer
     */
    public static function componentNestedInHole(array $inner, array $outer): bool
    {
        $exterior = $inner[0] ?? null;

        if ($exterior === null || count($outer) < 2) {
            return false;
        }

        for ($hole = 1; $hole < count($outer); $hole++) {
            if (self::ringInside($exterior, $outer[$hole])) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether a point lies in a component's FILLED area — inside the exterior
     * and outside every hole.
     *
     * @param  list<list<Coordinates>>  $component
     */
    public static function filledContains(array $component, Coordinates $point): bool
    {
        $exterior = $component[0] ?? null;

        if ($exterior === null || ! self::strictlyContains($exterior, $point)) {
            return false;
        }

        for ($i = 1; $i < count($component); $i++) {
            if (self::strictlyContains($component[$i], $point)) {
                return false;   // in a hole: not filled
            }
        }

        return true;
    }

    /**
     * Signed area of a ring, in squared degrees. Only the sign and magnitude
     * relative to zero are meaningful; no projection is applied.
     *
     * @param  list<Coordinates>  $ring
     */
    public static function signedArea(array $ring): float
    {
        $total = 0.0;

        for ($i = 0; $i < count($ring) - 1; $i++) {
            $total += $ring[$i]->longitude * $ring[$i + 1]->latitude
                - $ring[$i + 1]->longitude * $ring[$i]->latitude;
        }

        return $total / 2.0;
    }

    /** @param  list<Coordinates>  $ring */
    public static function isClosed(array $ring): bool
    {
        $first = $ring[0] ?? null;
        $last = $ring[count($ring) - 1] ?? null;

        return $first !== null
            && $last !== null
            && abs($first->latitude - $last->latitude) < 1e-9
            && abs($first->longitude - $last->longitude) < 1e-9;
    }

    /**
     * Distinct vertex count, ignoring the closing repeat.
     *
     * @param  list<Coordinates>  $ring
     */
    public static function distinctVertexCount(array $ring): int
    {
        $seen = [];

        foreach ($ring as $point) {
            $seen[$point->latitude.','.$point->longitude] = true;
        }

        return count($seen);
    }
}
