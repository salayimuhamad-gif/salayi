/**
 * GeoJSON boundary geometry — types and conversion.
 *
 * Deliberately free of any browser or map-library import. Everything here is
 * pure data in, pure data out, which is what makes it testable with `node`
 * alone. The two boundary defects that reached a delivered archive were both
 * in exactly this kind of coordinate handling, and both were invisible to
 * every check that did not execute the conversion against real geometry.
 *
 * The type was previously `{ type: 'Polygon'; coordinates: number[][][] }`,
 * which is wrong in two directions at once: the server also emits
 * MultiPolygon, and `number[][][]` cannot express one. A MultiPolygon assigned
 * to it type-checks only because both collapse to nested number arrays — so
 * TypeScript reported no error while the Google adapter silently rendered the
 * first ring of the first polygon and dropped everything else.
 */

/** A single [longitude, latitude] pair. GeoJSON order, never [lat, lng]. */
export type Position = [number, number];

/** A linear ring: exterior first in a polygon, then any holes. */
export type LinearRing = Position[];

export interface PolygonGeometry {
    type: 'Polygon';
    /** Ring 0 is the exterior; rings 1..n are holes. */
    coordinates: LinearRing[];
}

export interface MultiPolygonGeometry {
    type: 'MultiPolygon';
    /** One entry per polygon, each its own list of rings. */
    coordinates: LinearRing[][];
}

export type BoundaryGeometry = PolygonGeometry | MultiPolygonGeometry;

export interface BoundaryFeature {
    type: 'Feature';
    geometry: BoundaryGeometry;
    properties: { slug: string; name: string; type: string | null };
}

export interface BoundaryCollection {
    type: 'FeatureCollection';
    features: BoundaryFeature[];
}

/** Google's coordinate shape. Note the order flip from GeoJSON. */
export interface LatLngLiteral {
    lat: number;
    lng: number;
}

/**
 * Signed area of a ring, in squared degrees (the shoelace formula).
 *
 * Only the SIGN is used, so no projection is needed: winding direction is
 * preserved under any affine transform. Positive is counter-clockwise in
 * GeoJSON's [longitude, latitude] order.
 */
export function signedArea(ring: LinearRing): number {
    let total = 0;

    for (let i = 0; i < ring.length - 1; i++) {
        const [x1, y1] = ring[i];
        const [x2, y2] = ring[i + 1];

        total += x1 * y2 - x2 * y1;
    }

    return total / 2;
}

export function isClockwise(ring: LinearRing): boolean {
    return signedArea(ring) < 0;
}

/** The same ring, wound the other way. */
export function reverseRing(ring: LinearRing): LinearRing {
    return [...ring].reverse();
}

/**
 * Split a geometry into its POLYGON COMPONENTS, each keeping its own holes.
 *
 * This replaced a flatten. Flattening a MultiPolygon into one ring list and
 * handing it to a single `google.maps.Polygon` loses the association between
 * an exterior and its holes: Google then decides what is a hole purely from
 * winding across the whole undifferentiated set, so a hole belonging to
 * component A can be punched out of component B, and two unrelated districts
 * can end up sharing a fill. The shape still renders, which is exactly what
 * makes it dangerous.
 *
 * One component per exterior ring. The caller builds one Google Polygon each.
 */
export function toPolygonComponents(geometry: BoundaryGeometry): LinearRing[][] {
    return geometry.type === 'MultiPolygon' ? geometry.coordinates : [geometry.coordinates];
}

/**
 * Normalise one component's winding for Google's even-odd renderer.
 *
 * `google.maps.Polygon` determines holes by winding: an interior ring must be
 * wound OPPOSITE to its exterior. GeoJSON (RFC 7946) specifies exterior
 * counter-clockwise and holes clockwise, but real imported data frequently
 * ignores that — a hole exported with the same winding as its exterior is
 * rendered as a second filled shape rather than a void, so the map claims
 * territory the area does not have and looks entirely correct doing it.
 *
 * Exterior is forced counter-clockwise, every hole clockwise. Data that was
 * already correct passes through unchanged.
 */
export function normaliseComponent(rings: LinearRing[]): LinearRing[] {
    if (rings.length === 0) {
        return rings;
    }

    const [exterior, ...holes] = rings;

    const normalisedExterior = isClockwise(exterior) ? reverseRing(exterior) : exterior;
    const normalisedHoles = holes.map((hole) => (isClockwise(hole) ? hole : reverseRing(hole)));

    return [normalisedExterior, ...normalisedHoles];
}

/**
 * Convert one GeoJSON ring to Google's {lat, lng} path.
 *
 * The order flip is the whole reason this exists as a named, tested unit:
 * GeoJSON is [longitude, latitude] and Google is {lat, lng}. Reversed, Erbil
 * renders in the Indian Ocean and the map still draws a perfectly valid
 * polygon, so nothing downstream can catch the mistake.
 */
export function ringToPath(ring: LinearRing): LatLngLiteral[] {
    return ring.map((position) => ({ lat: position[1], lng: position[0] }));
}

/**
 * Google paths for every component of a geometry, winding normalised.
 *
 * Returns one entry per polygon component; each entry is that component's
 * exterior followed by its own holes. The caller builds one
 * `google.maps.Polygon` per entry, which is what keeps holes attached to the
 * correct exterior.
 */
export function toGooglePolygons(geometry: BoundaryGeometry): LatLngLiteral[][][] {
    return toPolygonComponents(geometry)
        .filter((rings) => rings.length > 0)
        .map((rings) => normaliseComponent(rings).map(ringToPath));
}

/** An empty collection, for initial state and for clearing. */
export function emptyBoundaryCollection(): BoundaryCollection {
    return { type: 'FeatureCollection', features: [] };
}
