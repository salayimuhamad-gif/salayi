<?php

declare(strict_types=1);

namespace App\Modules\Geography\Support;

use App\Modules\Geography\ValueObjects\Coordinates;

/**
 * Great-circle geometry (spec 10.5 step 2, "calculate straight-line distance").
 *
 * Straight-line distance ONLY. Travel distance and time need a routing provider
 * and are handled separately (spec 10.5 step 3). Conflating them would let a
 * school 400 m away across a motorway with no crossing rank above one 600 m
 * away on the same street, which is exactly the kind of wrong answer a family
 * would notice immediately.
 *
 * Haversine on a spherical earth. Vincenty on the WGS-84 ellipsoid is ~0.3%
 * more accurate; at Erbil city scale that is under two metres per kilometre,
 * well below the positional error of the underlying place data. Not worth the
 * iteration cost or the convergence edge cases near antipodes.
 *
 * PRECISION FLOOR: Coordinates quantises to 7 decimal places (1.11 cm of
 * latitude), so any distance derived from a stored coordinate carries roughly
 * a centimetre of absolute error. That is irrelevant at the scale this product
 * works at — the nearest school is 400 m away give or take 80 m of source
 * error — but it means destination() is not a precise instrument below about a
 * metre. If sub-centimetre work is ever needed, raise Coordinates::PRECISION;
 * do not reach for a different formula.
 *
 * Note also that distanceMetres returns the GREAT CIRCLE, not the arc along a
 * parallel. At 60N those differ by half a metre per degree of longitude. The
 * cos(latitude) approximation in BoundingBox is deliberately the looser of the
 * two, because a bounding box only needs to be a superset.
 */
final class Geodesy
{
    /** IUGG mean earth radius, metres. */
    public const EARTH_RADIUS_M = 6_371_008.8;

    public static function distanceMetres(Coordinates $from, Coordinates $to): float
    {
        $lat1 = deg2rad($from->latitude);
        $lat2 = deg2rad($to->latitude);
        $dLat = $lat2 - $lat1;
        $dLng = deg2rad($to->longitude - $from->longitude);

        $a = sin($dLat / 2) ** 2 + cos($lat1) * cos($lat2) * sin($dLng / 2) ** 2;

        // atan2 form rather than 2*asin(sqrt(a)): numerically stable for
        // near-antipodal points, where asin loses precision as a approaches 1.
        return 2 * self::EARTH_RADIUS_M * atan2(sqrt($a), sqrt(max(0.0, 1.0 - $a)));
    }

    public static function distanceKm(Coordinates $from, Coordinates $to): float
    {
        return self::distanceMetres($from, $to) / 1000.0;
    }

    /** Initial bearing in degrees. 0 = north, increasing clockwise. */
    public static function bearingDegrees(Coordinates $from, Coordinates $to): float
    {
        $lat1 = deg2rad($from->latitude);
        $lat2 = deg2rad($to->latitude);
        $dLng = deg2rad($to->longitude - $from->longitude);

        $y = sin($dLng) * cos($lat2);
        $x = cos($lat1) * sin($lat2) - sin($lat1) * cos($lat2) * cos($dLng);

        return fmod(rad2deg(atan2($y, $x)) + 360.0, 360.0);
    }

    /** Eight-point compass label, for "north of the city centre" style copy. */
    public static function compassPoint(Coordinates $from, Coordinates $to): string
    {
        $points = ['N', 'NE', 'E', 'SE', 'S', 'SW', 'W', 'NW'];

        return $points[((int) round(self::bearingDegrees($from, $to) / 45.0)) % 8];
    }

    /** Project a point a given distance along a bearing. Used to build radius boxes. */
    public static function destination(Coordinates $from, float $distanceMetres, float $bearingDegrees): Coordinates
    {
        $angular = $distanceMetres / self::EARTH_RADIUS_M;
        $bearing = deg2rad($bearingDegrees);
        $lat1 = deg2rad($from->latitude);
        $lng1 = deg2rad($from->longitude);

        $lat2 = asin(sin($lat1) * cos($angular) + cos($lat1) * sin($angular) * cos($bearing));
        $lng2 = $lng1 + atan2(
            sin($bearing) * sin($angular) * cos($lat1),
            cos($angular) - sin($lat1) * sin($lat2),
        );

        // Normalise longitude into -180..180.
        $lng2 = fmod($lng2 + 3 * M_PI, 2 * M_PI) - M_PI;

        return Coordinates::make(rad2deg($lat2), rad2deg($lng2));
    }

    /**
     * Human-readable distance.
     *
     * Under a kilometre rounds to 10 m. A "437 m" derived from source data with
     * +/- 80 m of positional error implies a precision the data does not have,
     * and users reasonably treat a precise-looking number as a reliable one.
     */
    public static function humanise(float $metres): string
    {
        if ($metres < 1000.0) {
            return ((int) (round($metres / 10.0) * 10)).' m';
        }

        return number_format($metres / 1000.0, $metres < 10000.0 ? 1 : 0, '.', ',').' km';
    }
}
