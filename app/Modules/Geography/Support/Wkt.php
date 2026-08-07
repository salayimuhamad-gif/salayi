<?php

declare(strict_types=1);

namespace App\Modules\Geography\Support;

use App\Modules\Geography\ValueObjects\Coordinates;
use InvalidArgumentException;

/**
 * Well-Known Text reader and writer (spec 10.3, Appendix A `polygon_wkt`).
 *
 * WKT is the interchange format between three things that must agree: the
 * admin map editor, the MySQL spatial column, and the Excel import template.
 * Parsing it here rather than relying on MySQL means an import can be validated
 * BEFORE it touches the database, and means the SQLite test engine — which has
 * no spatial support — still exercises the same geometry code.
 *
 * Coordinate order is longitude-first throughout, per the OGC specification.
 * This is the opposite of how humans write coordinates and is the single most
 * common source of silently-wrong geometry, so every conversion in this class
 * goes through Coordinates rather than raw pairs.
 */
final class Wkt
{
    /**
     * Parse a WKT POINT.
     */
    public static function parsePoint(string $wkt): Coordinates
    {
        $body = self::body($wkt, 'POINT');
        $pair = self::parseCoordinate($body);

        return $pair;
    }

    /**
     * Parse a WKT POLYGON into its rings.
     *
     * The first ring is the exterior boundary; any further rings are holes
     * (an interior courtyard, an excluded plot inside a development).
     *
     * @return list<list<Coordinates>>
     */
    public static function parsePolygon(string $wkt): array
    {
        $body = self::body($wkt, 'POLYGON');

        return self::parseRings($body);
    }

    /**
     * Parse a WKT MULTIPOLYGON into polygons, each a list of rings.
     *
     * @return list<list<list<Coordinates>>>
     */
    public static function parseMultiPolygon(string $wkt): array
    {
        $body = self::body($wkt, 'MULTIPOLYGON');

        $polygons = [];

        foreach (self::splitTopLevel($body) as $polygonBody) {
            $polygons[] = self::parseRings(self::unwrap($polygonBody));
        }

        return $polygons;
    }

    /** @return list<Coordinates> */
    public static function parseLineString(string $wkt): array
    {
        $body = self::body($wkt, 'LINESTRING');

        return self::parseCoordinateList($body);
    }

    /** The geometry type of an arbitrary WKT string, uppercased. */
    public static function type(string $wkt): ?string
    {
        if (preg_match('/^\s*([A-Za-z]+)\s*\(/', $wkt, $m) !== 1) {
            return null;
        }

        return strtoupper($m[1]);
    }

    /**
     * Emit a POLYGON from one or more rings.
     *
     * Rings are closed automatically if the caller did not close them — an
     * unclosed ring is rejected by MySQL with an error message that does not
     * name the offending geometry, so closing here saves a genuinely painful
     * debugging session on a 200-row import.
     *
     * @param  list<list<Coordinates>>  $rings
     */
    public static function polygon(array $rings): string
    {
        if ($rings === []) {
            throw new InvalidArgumentException('A polygon needs at least one ring.');
        }

        $parts = [];

        foreach ($rings as $ring) {
            $parts[] = '('.self::ringToString(Polygon::close($ring)).')';
        }

        return 'POLYGON('.implode(', ', $parts).')';
    }

    /** @param list<list<list<Coordinates>>> $polygons */
    public static function multiPolygon(array $polygons): string
    {
        if ($polygons === []) {
            throw new InvalidArgumentException('A multipolygon needs at least one polygon.');
        }

        $parts = [];

        foreach ($polygons as $rings) {
            $ringParts = [];

            foreach ($rings as $ring) {
                $ringParts[] = '('.self::ringToString(Polygon::close($ring)).')';
            }

            $parts[] = '('.implode(', ', $ringParts).')';
        }

        return 'MULTIPOLYGON('.implode(', ', $parts).')';
    }

    /** @param list<Coordinates> $points */
    public static function lineString(array $points): string
    {
        if (count($points) < 2) {
            throw new InvalidArgumentException('A linestring needs at least two points.');
        }

        return 'LINESTRING('.self::ringToString($points).')';
    }

    /**
     * Validate without throwing, for import row checks.
     *
     * @return array{valid: bool, type: string|null, reason: string|null}
     */
    public static function validate(?string $wkt): array
    {
        if ($wkt === null || trim($wkt) === '') {
            return ['valid' => false, 'type' => null, 'reason' => 'empty'];
        }

        $type = self::type($wkt);

        if ($type === null) {
            return ['valid' => false, 'type' => null, 'reason' => 'unrecognised geometry syntax'];
        }

        try {
            match ($type) {
                'POINT' => self::parsePoint($wkt),
                'POLYGON' => self::parsePolygon($wkt),
                'MULTIPOLYGON' => self::parseMultiPolygon($wkt),
                'LINESTRING' => self::parseLineString($wkt),
                default => throw new InvalidArgumentException(sprintf('Unsupported geometry type "%s".', $type)),
            };
        } catch (InvalidArgumentException $e) {
            return ['valid' => false, 'type' => $type, 'reason' => $e->getMessage()];
        }

        return ['valid' => true, 'type' => $type, 'reason' => null];
    }

    // -----------------------------------------------------------------

    private static function body(string $wkt, string $expectedType): string
    {
        $trimmed = trim($wkt);

        // Tolerate an SRID prefix, which some exports emit: SRID=4326;POINT(...)
        if (preg_match('/^SRID\s*=\s*\d+\s*;\s*(.+)$/is', $trimmed, $m) === 1) {
            $trimmed = trim($m[1]);
        }

        $pattern = '/^'.$expectedType.'\s*\((.*)\)$/is';

        if (preg_match($pattern, $trimmed, $m) !== 1) {
            throw new InvalidArgumentException(sprintf('Expected a %s geometry.', $expectedType));
        }

        return trim($m[1]);
    }

    private static function unwrap(string $value): string
    {
        $trimmed = trim($value);

        if (str_starts_with($trimmed, '(') && str_ends_with($trimmed, ')')) {
            return trim(substr($trimmed, 1, -1));
        }

        return $trimmed;
    }

    /** @return list<list<Coordinates>> */
    private static function parseRings(string $body): array
    {
        $rings = [];

        foreach (self::splitTopLevel($body) as $ringBody) {
            $ring = self::parseCoordinateList(self::unwrap($ringBody));

            if (count($ring) < 4) {
                throw new InvalidArgumentException(
                    'A polygon ring needs at least four points, the last repeating the first.',
                );
            }

            if (! Polygon::isClosed($ring)) {
                throw new InvalidArgumentException('Polygon ring is not closed.');
            }

            $rings[] = $ring;
        }

        if ($rings === []) {
            throw new InvalidArgumentException('Polygon contains no rings.');
        }

        return $rings;
    }

    /** @return list<Coordinates> */
    private static function parseCoordinateList(string $body): array
    {
        $points = [];

        foreach (explode(',', $body) as $pair) {
            $pair = trim($pair);

            if ($pair === '') {
                continue;
            }

            $points[] = self::parseCoordinate($pair);
        }

        if ($points === []) {
            throw new InvalidArgumentException('Geometry contains no coordinates.');
        }

        return $points;
    }

    /** WKT order is "longitude latitude". */
    private static function parseCoordinate(string $pair): Coordinates
    {
        $parts = preg_split('/\s+/', trim($pair)) ?: [];

        if (count($parts) < 2) {
            throw new InvalidArgumentException(sprintf('Malformed coordinate pair "%s".', $pair));
        }

        // Extra ordinates (Z, M) are accepted and discarded — this product is
        // strictly 2D, and rejecting a 3D export outright would be unhelpful.
        return Coordinates::make($parts[1], $parts[0]);
    }

    /**
     * Split a WKT body on commas that are NOT inside parentheses.
     *
     * @return list<string>
     */
    private static function splitTopLevel(string $body): array
    {
        $parts = [];
        $depth = 0;
        $current = '';

        foreach (str_split($body) as $char) {
            if ($char === '(') {
                $depth++;
            } elseif ($char === ')') {
                $depth--;
            }

            if ($char === ',' && $depth === 0) {
                $parts[] = trim($current);
                $current = '';

                continue;
            }

            $current .= $char;
        }

        if (trim($current) !== '') {
            $parts[] = trim($current);
        }

        return $parts;
    }

    /** @param list<Coordinates> $points */
    private static function ringToString(array $points): string
    {
        return implode(', ', array_map(
            static fn (Coordinates $c): string => self::num($c->longitude).' '.self::num($c->latitude),
            $points,
        ));
    }

    private static function num(float $value): string
    {
        $formatted = rtrim(rtrim(number_format($value, Coordinates::PRECISION, '.', ''), '0'), '.');

        return $formatted === '' || $formatted === '-' ? '0' : $formatted;
    }
}
