<?php

declare(strict_types=1);

namespace App\Modules\Geography\ValueObjects;

use App\Modules\Geography\Support\Geodesy;
use InvalidArgumentException;
use JsonSerializable;

/**
 * An axis-aligned lat/lng rectangle (spec 10.3 "bounding box").
 *
 * Two jobs:
 *
 *   1. The coarse pre-filter for nearby-place search. A bounding box comparison
 *      is four indexed range predicates; a great-circle distance is
 *      trigonometry per row. Filtering to the box first and computing exact
 *      distance only on survivors is the difference between an index scan and a
 *      full table scan on the places table.
 *
 *   2. The operating-area sanity check for imports (spec 11.3).
 *
 * Antimeridian crossing is deliberately NOT supported. Erbil is at 44 E; a box
 * that wraps 180 E would be a bug, and silently handling it would hide that.
 */
final class BoundingBox implements JsonSerializable
{
    private function __construct(
        public readonly float $minLatitude,
        public readonly float $minLongitude,
        public readonly float $maxLatitude,
        public readonly float $maxLongitude,
    ) {}

    public static function make(float $minLat, float $minLng, float $maxLat, float $maxLng): self
    {
        if ($minLat > $maxLat) {
            throw new InvalidArgumentException('Bounding box minimum latitude exceeds its maximum.');
        }

        if ($minLng > $maxLng) {
            throw new InvalidArgumentException(
                'Bounding box minimum longitude exceeds its maximum. Antimeridian-crossing boxes are not supported.',
            );
        }

        return new self($minLat, $minLng, $maxLat, $maxLng);
    }

    /** The configured Erbil operating area (config/mulkihawler.php). */
    public static function operatingArea(): self
    {
        /** @var array<string, float|int|string> $bounds */
        $bounds = (array) config('mulkihawler.city.bounds', []);

        return self::make(
            (float) ($bounds['min_lat'] ?? 35.90),
            (float) ($bounds['min_lng'] ?? 43.70),
            (float) ($bounds['max_lat'] ?? 36.50),
            (float) ($bounds['max_lng'] ?? 44.40),
        );
    }

    /**
     * The box enclosing a circle of $radiusMetres around $centre.
     *
     * The longitude span widens by 1/cos(latitude) because meridians converge
     * toward the poles. At Erbil's 36 N that is a ~24% wider box than a naive
     * equal-degree square — omitting it silently drops places on the east and
     * west edges of every radius search.
     */
    public static function aroundRadius(Coordinates $centre, float $radiusMetres): self
    {
        $latDelta = rad2deg($radiusMetres / Geodesy::EARTH_RADIUS_M);

        $cos = cos(deg2rad($centre->latitude));
        // Guard against division by zero at the poles.
        $lngDelta = abs($cos) < 1.0e-9
            ? 180.0
            : rad2deg($radiusMetres / (Geodesy::EARTH_RADIUS_M * $cos));

        return self::make(
            max(-90.0, $centre->latitude - $latDelta),
            max(-180.0, $centre->longitude - $lngDelta),
            min(90.0, $centre->latitude + $latDelta),
            min(180.0, $centre->longitude + $lngDelta),
        );
    }

    /**
     * The box enclosing a set of points.
     *
     * @param  list<Coordinates>  $points
     */
    public static function around(array $points): ?self
    {
        if ($points === []) {
            return null;
        }

        $lats = array_map(static fn (Coordinates $c): float => $c->latitude, $points);
        $lngs = array_map(static fn (Coordinates $c): float => $c->longitude, $points);

        return self::make(min($lats), min($lngs), max($lats), max($lngs));
    }

    public function contains(Coordinates $point): bool
    {
        return $point->latitude >= $this->minLatitude
            && $point->latitude <= $this->maxLatitude
            && $point->longitude >= $this->minLongitude
            && $point->longitude <= $this->maxLongitude;
    }

    public function intersects(self $other): bool
    {
        return $this->minLatitude <= $other->maxLatitude
            && $this->maxLatitude >= $other->minLatitude
            && $this->minLongitude <= $other->maxLongitude
            && $this->maxLongitude >= $other->minLongitude;
    }

    public function centre(): Coordinates
    {
        return Coordinates::make(
            ($this->minLatitude + $this->maxLatitude) / 2.0,
            ($this->minLongitude + $this->maxLongitude) / 2.0,
        );
    }

    /** Grown by a margin in metres, for a tolerant "roughly inside" check. */
    public function padded(float $metres): self
    {
        $latDelta = rad2deg($metres / Geodesy::EARTH_RADIUS_M);
        $cos = cos(deg2rad($this->centre()->latitude));
        $lngDelta = abs($cos) < 1.0e-9 ? 180.0 : rad2deg($metres / (Geodesy::EARTH_RADIUS_M * $cos));

        return self::make(
            max(-90.0, $this->minLatitude - $latDelta),
            max(-180.0, $this->minLongitude - $lngDelta),
            min(90.0, $this->maxLatitude + $latDelta),
            min(180.0, $this->maxLongitude + $lngDelta),
        );
    }

    /** WKT is longitude-first, and a polygon ring must close on its first point. */
    public function toWkt(): string
    {
        return sprintf(
            'POLYGON((%1$s %2$s, %3$s %2$s, %3$s %4$s, %1$s %4$s, %1$s %2$s))',
            $this->minLongitude,
            $this->minLatitude,
            $this->maxLongitude,
            $this->maxLatitude,
        );
    }

    /** @return array{min_lat: float, min_lng: float, max_lat: float, max_lng: float} */
    public function jsonSerialize(): array
    {
        return [
            'min_lat' => $this->minLatitude,
            'min_lng' => $this->minLongitude,
            'max_lat' => $this->maxLatitude,
            'max_lng' => $this->maxLongitude,
        ];
    }
}
