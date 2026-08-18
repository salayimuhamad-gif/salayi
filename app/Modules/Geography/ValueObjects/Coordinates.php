<?php

declare(strict_types=1);

namespace App\Modules\Geography\ValueObjects;

use App\Modules\Geography\Support\Geodesy;
use InvalidArgumentException;
use JsonSerializable;
use Stringable;

/**
 * A WGS-84 latitude/longitude pair (spec 10.3).
 *
 * Immutable, validated on construction, and deliberately strict about the two
 * mistakes that actually happen in this product's data pipeline:
 *
 *   1. Swapped lat/lng. Erbil sits at roughly 36.19 N, 44.01 E — both numbers
 *      are plausible latitudes AND plausible longitudes, so a swap passes every
 *      range check and silently relocates the project several hundred
 *      kilometres. `looksSwapped()` exists because a range check cannot catch
 *      it.
 *
 *   2. Coordinates outside the Erbil operating area. A Baghdad coordinate in an
 *      Erbil import is almost always a copy-paste from the wrong row, not a
 *      genuine expansion. Spec 11.3 requires the importer to "detect coordinate
 *      anomalies"; this is where that lives.
 *
 * Precision is capped at 7 decimal places (~1.1 cm), far beyond what any source
 * in this product provides, which stops float noise producing two "different"
 * coordinates for the same place.
 */
final class Coordinates implements JsonSerializable, Stringable
{
    public const PRECISION = 7;

    private function __construct(
        public readonly float $latitude,
        public readonly float $longitude,
    ) {}

    public static function make(float|string $latitude, float|string $longitude): self
    {
        $lat = self::normalize($latitude, 'latitude');
        $lng = self::normalize($longitude, 'longitude');

        if ($lat < -90.0 || $lat > 90.0) {
            throw new InvalidArgumentException(sprintf('Latitude %s is outside the range -90..90.', $lat));
        }

        if ($lng < -180.0 || $lng > 180.0) {
            throw new InvalidArgumentException(sprintf('Longitude %s is outside the range -180..180.', $lng));
        }

        return new self(round($lat, self::PRECISION), round($lng, self::PRECISION));
    }

    /**
     * Lenient parse for imports. Returns null instead of throwing so a bad
     * spreadsheet cell becomes a reviewable row error, not a failed job.
     */
    public static function tryMake(float|string|null $latitude, float|string|null $longitude): ?self
    {
        if ($latitude === null || $longitude === null || $latitude === '' || $longitude === '') {
            return null;
        }

        try {
            return self::make($latitude, $longitude);
        } catch (InvalidArgumentException) {
            return null;
        }
    }

    /** (0, 0) — the classic "the geocoder returned nothing and nobody checked". */
    public function isNullIsland(): bool
    {
        return abs($this->latitude) < 1.0e-9 && abs($this->longitude) < 1.0e-9;
    }

    /**
     * Heuristic swap detection.
     *
     * True when the pair falls OUTSIDE the operating area but the swapped pair
     * falls INSIDE it. That is far stronger evidence than either check alone:
     * it means there is a specific, reversible mistake rather than merely an
     * unfamiliar location.
     */
    public function looksSwapped(?BoundingBox $operatingArea = null): bool
    {
        $area = $operatingArea ?? BoundingBox::operatingArea();

        if ($area->contains($this)) {
            return false;
        }

        $swapped = self::tryMake($this->longitude, $this->latitude);

        return $swapped !== null && $area->contains($swapped);
    }

    public function swapped(): ?self
    {
        return self::tryMake($this->longitude, $this->latitude);
    }

    public function withinOperatingArea(?BoundingBox $operatingArea = null): bool
    {
        return ($operatingArea ?? BoundingBox::operatingArea())->contains($this);
    }

    /**
     * Equality with a tolerance, because two sources describing the same
     * building will never agree to the last decimal. Half a metre is tight
     * enough to distinguish neighbouring shopfronts.
     */
    public function equals(self $other, float $toleranceMetres = 0.5): bool
    {
        return Geodesy::distanceMetres($this, $other) <= $toleranceMetres;
    }

    /** WKT is longitude-FIRST. Getting this backwards is the classic GIS bug. */
    public function toWkt(): string
    {
        return sprintf('POINT(%s %s)', $this->formatted($this->longitude), $this->formatted($this->latitude));
    }

    /**
     * GeoJSON is also longitude-first (RFC 7946 §3.1.1).
     *
     * @return array{type: string, coordinates: array{float, float}}
     */
    public function toGeoJson(): array
    {
        return ['type' => 'Point', 'coordinates' => [$this->longitude, $this->latitude]];
    }

    /** Display order is latitude-first, matching what a user copies from a map. */
    public function __toString(): string
    {
        return $this->formatted($this->latitude).','.$this->formatted($this->longitude);
    }

    /** @return array{lat: float, lng: float} */
    public function jsonSerialize(): array
    {
        return ['lat' => $this->latitude, 'lng' => $this->longitude];
    }

    private function formatted(float $value): string
    {
        $formatted = rtrim(rtrim(number_format($value, self::PRECISION, '.', ''), '0'), '.');

        return $formatted === '' || $formatted === '-' ? '0' : $formatted;
    }

    private static function normalize(float|string $value, string $label): float
    {
        if (is_float($value)) {
            if (! is_finite($value)) {
                throw new InvalidArgumentException(sprintf('%s must be a finite number.', ucfirst($label)));
            }

            return $value;
        }

        // Arabic-Indic digits and separators reach this path from Excel imports.
        $candidate = strtr(trim($value), [
            '٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4',
            '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9',
            '۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4',
            '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9',
            '٫' => '.', '،' => '', ',' => '', '°' => '',
        ]);

        $candidate = ltrim($candidate, '+');

        if (preg_match('/^-?\d+(\.\d+)?$/', $candidate) !== 1) {
            throw new InvalidArgumentException(sprintf('Value "%s" is not a valid %s.', $value, $label));
        }

        return (float) $candidate;
    }
}
