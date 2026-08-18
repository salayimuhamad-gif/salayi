<?php

declare(strict_types=1);

namespace App\Modules\Geography\Support;

use App\Modules\Geography\ValueObjects\BoundingBox;
use App\Modules\Geography\ValueObjects\Coordinates;

/**
 * Planar polygon operations on lat/lng rings (spec 10.3, 12.2 boundaries).
 *
 * Treated as planar rather than spherical. At Erbil's scale — an area boundary
 * spans a few kilometres at 36 N — the spherical correction is far below the
 * accuracy of the boundaries themselves, which are drawn by hand on a map.
 * The one place this would matter is area computation over a large region, and
 * `areaSquareMetres()` applies a latitude correction for exactly that reason.
 *
 * Every method here runs in PHP rather than delegating to MySQL `ST_Contains`,
 * because:
 *   - the SQLite test engine has no spatial functions at all;
 *   - an import must be validated before it reaches the database;
 *   - "is this project inside this area?" is asked once per project on save,
 *     not in a hot query loop.
 */
final class Polygon
{
    /**
     * A ring is closed when its last point repeats its first.
     *
     * @param  list<Coordinates>  $ring  ordered ring vertices
     */
    public static function isClosed(array $ring): bool
    {
        $count = count($ring);

        if ($count < 2) {
            return false;
        }

        $first = $ring[0];
        $last = $ring[$count - 1];

        return abs($first->latitude - $last->latitude) < 1.0e-9
            && abs($first->longitude - $last->longitude) < 1.0e-9;
    }

    /**
     * Close a ring if it is not already closed.
     *
     * @param  list<Coordinates>  $ring
     * @return list<Coordinates>
     */
    public static function close(array $ring): array
    {
        if ($ring === [] || self::isClosed($ring)) {
            return $ring;
        }

        $ring[] = $ring[0];

        return $ring;
    }

    /**
     * Point-in-polygon by ray casting (crossing number).
     *
     * A point exactly on an edge is INSIDE. That choice matters: an area
     * boundary drawn along the centre line of a road would otherwise exclude
     * every project whose coordinate was snapped to that road, and "on the
     * boundary" is not a useful answer for a user asking which district they
     * are in.
     *
     * @param  list<Coordinates>  $ring
     */
    public static function containsPoint(array $ring, Coordinates $point): bool
    {
        $ring = self::close($ring);
        $count = count($ring);

        if ($count < 4) {
            return false;
        }

        // On-edge test first: ray casting is unreliable exactly on a boundary.
        for ($i = 0, $j = $count - 1; $i < $count; $j = $i++) {
            if (self::pointOnSegment($point, $ring[$j], $ring[$i])) {
                return true;
            }
        }

        $inside = false;
        $x = $point->longitude;
        $y = $point->latitude;

        for ($i = 0, $j = $count - 1; $i < $count; $j = $i++) {
            $xi = $ring[$i]->longitude;
            $yi = $ring[$i]->latitude;
            $xj = $ring[$j]->longitude;
            $yj = $ring[$j]->latitude;

            // Half-open vertical rule (yi > y) !== (yj > y) counts each vertex
            // exactly once, which is what stops a ray passing through a vertex
            // being double-counted and flipping the answer.
            if ((($yi > $y) !== ($yj > $y))
                && ($x < ($xj - $xi) * ($y - $yi) / ($yj - $yi) + $xi)) {
                $inside = ! $inside;
            }
        }

        return $inside;
    }

    /**
     * Containment across a full polygon: inside the exterior ring and outside
     * every hole.
     *
     * @param  list<list<Coordinates>>  $rings
     */
    public static function polygonContains(array $rings, Coordinates $point): bool
    {
        if ($rings === []) {
            return false;
        }

        if (! self::containsPoint($rings[0], $point)) {
            return false;
        }

        foreach (array_slice($rings, 1) as $hole) {
            if (self::containsPoint($hole, $point)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Centroid of the ring's enclosed area (not the mean of its vertices).
     *
     * The vertex mean is wrong whenever vertices are unevenly spaced, which for
     * a hand-drawn boundary is always — one densely-clicked corner would drag
     * the "centre" of an area toward it. This is the value that becomes an
     * area's map marker and its default map centring.
     *
     * @param  list<Coordinates>  $ring
     */
    public static function centroid(array $ring): ?Coordinates
    {
        $ring = self::close($ring);
        $count = count($ring);

        if ($count < 4) {
            // Degenerate: fall back to the vertex mean rather than returning null,
            // because a two-point "boundary" still needs somewhere to put a pin.
            return self::vertexMean($ring);
        }

        $twiceArea = 0.0;
        $cx = 0.0;
        $cy = 0.0;

        for ($i = 0; $i < $count - 1; $i++) {
            $x0 = $ring[$i]->longitude;
            $y0 = $ring[$i]->latitude;
            $x1 = $ring[$i + 1]->longitude;
            $y1 = $ring[$i + 1]->latitude;

            $cross = $x0 * $y1 - $x1 * $y0;
            $twiceArea += $cross;
            $cx += ($x0 + $x1) * $cross;
            $cy += ($y0 + $y1) * $cross;
        }

        if (abs($twiceArea) < 1.0e-12) {
            // Zero-area ring (all points collinear).
            return self::vertexMean($ring);
        }

        return Coordinates::make($cy / (3.0 * $twiceArea), $cx / (3.0 * $twiceArea));
    }

    /**
     * Enclosed area in square metres, via the shoelace formula with a
     * latitude correction for meridian convergence.
     *
     * Approximate by construction. Used for a plausibility check on an imported
     * boundary — an "area" of 4 m² or of 900 km² is a data error — not for any
     * figure shown to a user as a measurement.
     *
     * @param  list<Coordinates>  $ring
     */
    public static function areaSquareMetres(array $ring): float
    {
        $ring = self::close($ring);
        $count = count($ring);

        if ($count < 4) {
            return 0.0;
        }

        $centroid = self::centroid($ring);
        $latitudeRadians = deg2rad($centroid === null ? 0.0 : $centroid->latitude);

        // Metres per degree at this latitude.
        $metresPerDegreeLat = M_PI * Geodesy::EARTH_RADIUS_M / 180.0;
        $metresPerDegreeLng = $metresPerDegreeLat * cos($latitudeRadians);

        $sum = 0.0;

        for ($i = 0; $i < $count - 1; $i++) {
            $x0 = $ring[$i]->longitude * $metresPerDegreeLng;
            $y0 = $ring[$i]->latitude * $metresPerDegreeLat;
            $x1 = $ring[$i + 1]->longitude * $metresPerDegreeLng;
            $y1 = $ring[$i + 1]->latitude * $metresPerDegreeLat;

            $sum += $x0 * $y1 - $x1 * $y0;
        }

        return abs($sum) / 2.0;
    }

    /**
     * Signed shoelace area in degrees². Positive means counter-clockwise.
     *
     * Exterior rings should be counter-clockwise and holes clockwise (the
     * OGC convention). Sources disagree constantly, so this is used to
     * normalise on import rather than to reject.
     *
     * @param  list<Coordinates>  $ring
     */
    public static function isCounterClockwise(array $ring): bool
    {
        $ring = self::close($ring);
        $count = count($ring);
        $sum = 0.0;

        for ($i = 0; $i < $count - 1; $i++) {
            $sum += ($ring[$i + 1]->longitude - $ring[$i]->longitude)
                * ($ring[$i + 1]->latitude + $ring[$i]->latitude);
        }

        // Negative shoelace sum in this form means counter-clockwise.
        return $sum < 0.0;
    }

    /**
     * @param  list<Coordinates>  $ring
     * @return list<Coordinates>
     */
    public static function toCounterClockwise(array $ring): array
    {
        return self::isCounterClockwise($ring) ? $ring : array_reverse($ring);
    }

    /** @param list<Coordinates> $ring */
    public static function boundingBox(array $ring): ?BoundingBox
    {
        return BoundingBox::around($ring);
    }

    /**
     * Shortest distance from a point to a ring's edges, in metres.
     * Zero when the point is inside.
     *
     * @param  list<Coordinates>  $ring
     */
    public static function distanceToRingMetres(array $ring, Coordinates $point): float
    {
        if (self::containsPoint($ring, $point)) {
            return 0.0;
        }

        $ring = self::close($ring);
        $count = count($ring);
        $shortest = INF;

        for ($i = 0; $i < $count - 1; $i++) {
            $shortest = min($shortest, self::distanceToSegmentMetres($point, $ring[$i], $ring[$i + 1]));
        }

        return $shortest === INF ? 0.0 : $shortest;
    }

    // -----------------------------------------------------------------

    /** @param list<Coordinates> $ring */
    private static function vertexMean(array $ring): ?Coordinates
    {
        if ($ring === []) {
            return null;
        }

        $unique = $ring;

        if (count($unique) > 1 && self::isClosed($unique)) {
            array_pop($unique);
        }

        $count = count($unique);

        if ($count === 0) {
            return null;
        }

        $lat = array_sum(array_map(static fn (Coordinates $c): float => $c->latitude, $unique)) / $count;
        $lng = array_sum(array_map(static fn (Coordinates $c): float => $c->longitude, $unique)) / $count;

        return Coordinates::make($lat, $lng);
    }

    private static function pointOnSegment(Coordinates $p, Coordinates $a, Coordinates $b): bool
    {
        $cross = ($b->longitude - $a->longitude) * ($p->latitude - $a->latitude)
            - ($b->latitude - $a->latitude) * ($p->longitude - $a->longitude);

        // Tolerance in degrees. 1e-9 deg is roughly 0.1 mm — tight enough that
        // only a genuinely collinear point qualifies.
        if (abs($cross) > 1.0e-9) {
            return false;
        }

        return $p->longitude >= min($a->longitude, $b->longitude) - 1.0e-9
            && $p->longitude <= max($a->longitude, $b->longitude) + 1.0e-9
            && $p->latitude >= min($a->latitude, $b->latitude) - 1.0e-9
            && $p->latitude <= max($a->latitude, $b->latitude) + 1.0e-9;
    }

    private static function distanceToSegmentMetres(Coordinates $p, Coordinates $a, Coordinates $b): float
    {
        // Project into a local metre plane so the perpendicular is meaningful.
        $latRef = deg2rad($p->latitude);
        $mPerDegLat = M_PI * Geodesy::EARTH_RADIUS_M / 180.0;
        $mPerDegLng = $mPerDegLat * cos($latRef);

        $px = $p->longitude * $mPerDegLng;
        $py = $p->latitude * $mPerDegLat;
        $ax = $a->longitude * $mPerDegLng;
        $ay = $a->latitude * $mPerDegLat;
        $bx = $b->longitude * $mPerDegLng;
        $by = $b->latitude * $mPerDegLat;

        $dx = $bx - $ax;
        $dy = $by - $ay;
        $lengthSquared = $dx * $dx + $dy * $dy;

        if ($lengthSquared < 1.0e-12) {
            return Geodesy::distanceMetres($p, $a);
        }

        $t = max(0.0, min(1.0, (($px - $ax) * $dx + ($py - $ay) * $dy) / $lengthSquared));

        $closestX = $ax + $t * $dx;
        $closestY = $ay + $t * $dy;

        return sqrt(($px - $closestX) ** 2 + ($py - $closestY) ** 2);
    }

    /**
     * Ramer–Douglas–Peucker simplification, tolerance in metres.
     *
     * An Erbil district boundary imported from a survey can carry several
     * thousand vertices. At city zoom every one of them is sub-pixel: the
     * browser pays to transfer, parse and rasterise detail that is physically
     * invisible, and on a mid-range Android that is the difference between a
     * map that pans and one that stutters.
     *
     * Tolerance is in METRES rather than degrees deliberately. A degree of
     * longitude is ~90 km at the equator and ~0 at the pole, so a
     * degree-based epsilon simplifies different latitudes by different real
     * amounts — the classic bug that leaves northern boundaries jagged and
     * southern ones flattened. Latitude scaling is applied before the
     * perpendicular-distance test so one tolerance means one ground distance.
     *
     * The FIRST and LAST vertices are always kept, so a closed ring stays
     * closed. A ring that would reduce below three points is returned
     * unchanged instead: a degenerate polygon renders as a line or vanishes,
     * and showing a boundary in the wrong shape is worse than showing full
     * detail.
     *
     * @param  list<Coordinates>  $ring
     * @return list<Coordinates>
     */
    public static function simplify(array $ring, float $toleranceMetres): array
    {
        $count = count($ring);

        if ($count <= 3 || $toleranceMetres <= 0.0) {
            return $ring;
        }

        // Metres per degree at this ring's latitude. Longitude degrees shrink
        // by cos(latitude); at Erbil (~36.2 N) that is a factor of ~0.81, which
        // is far too large to ignore.
        $midLatitude = ($ring[0]->latitude + $ring[intdiv($count, 2)]->latitude) / 2.0;
        $metresPerDegreeLat = 111_320.0;
        $metresPerDegreeLng = 111_320.0 * cos(deg2rad($midLatitude));

        $tolerance = $toleranceMetres / $metresPerDegreeLat;
        /*
         * `$metresPerDegreeLat` is a constant derived from the earth's radius,
         * so the divide-by-zero branch it guarded could never be taken. The
         * longitude scale, by contrast, really does approach zero at the poles
         * — that is the value worth guarding, and it is used below.
         */
        $lngScale = $metresPerDegreeLng / $metresPerDegreeLat;

        $keep = array_fill(0, $count, false);
        $keep[0] = true;
        $keep[$count - 1] = true;

        self::rdp($ring, 0, $count - 1, $tolerance, $lngScale, $keep);

        $simplified = [];

        foreach ($ring as $index => $point) {
            if ($keep[$index]) {
                $simplified[] = $point;
            }
        }

        // Never hand back something that is no longer a polygon.
        return count($simplified) >= 4 ? $simplified : $ring;
    }

    /**
     * Recursive Douglas–Peucker step, marking vertices to keep.
     *
     * @param  list<Coordinates>  $ring
     * @param  array<int, bool>  $keep  written in place; indices are ring positions, so gaps are meaningful
     */
    private static function rdp(array $ring, int $first, int $last, float $tolerance, float $lngScale, array &$keep): void
    {
        if ($last <= $first + 1) {
            return;
        }

        $maxDistance = 0.0;
        $index = $first;

        $ax = $ring[$first]->longitude * $lngScale;
        $ay = $ring[$first]->latitude;
        $bx = $ring[$last]->longitude * $lngScale;
        $by = $ring[$last]->latitude;

        for ($i = $first + 1; $i < $last; $i++) {
            $distance = self::perpendicularDistance(
                $ring[$i]->longitude * $lngScale,
                $ring[$i]->latitude,
                $ax, $ay, $bx, $by,
            );

            if ($distance > $maxDistance) {
                $maxDistance = $distance;
                $index = $i;
            }
        }

        if ($maxDistance <= $tolerance) {
            return;
        }

        $keep[$index] = true;

        self::rdp($ring, $first, $index, $tolerance, $lngScale, $keep);
        self::rdp($ring, $index, $last, $tolerance, $lngScale, $keep);
    }

    private static function perpendicularDistance(
        float $px, float $py,
        float $ax, float $ay,
        float $bx, float $by,
    ): float {
        $dx = $bx - $ax;
        $dy = $by - $ay;

        if ($dx === 0.0 && $dy === 0.0) {
            return sqrt(($px - $ax) ** 2 + ($py - $ay) ** 2);
        }

        $numerator = abs($dy * $px - $dx * $py + $bx * $ay - $by * $ax);

        return $numerator / sqrt($dx ** 2 + $dy ** 2);
    }
}
