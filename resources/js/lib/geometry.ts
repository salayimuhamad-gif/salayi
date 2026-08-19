/**
 * WKT read/write for the map picker.
 *
 * A deliberate second implementation of what App\Modules\Geography\Support\Wkt
 * does in PHP. The alternative — round-tripping every drag through the server —
 * would make drawing a boundary unusable on an Erbil mobile connection.
 *
 * The two implementations must agree, so this one is intentionally the
 * narrower of the pair: it reads and writes only POLYGON, refuses everything
 * else, and leaves the server as the authority. Anything this accepts, the PHP
 * validator re-checks on submit; anything it produces is re-parsed there. It
 * can be permissive about what it *displays* but never about what it *emits*.
 *
 * Coordinate order is longitude-first, per the OGC specification and the
 * opposite of how the values read on screen.
 */

import { toRenderedFeatures, type Component } from './wizard/geometry';
import type { BoundaryCollection } from './map';

export interface LngLat {
    lng: number;
    lat: number;
}

const PRECISION = 7;

function round(value: number): number {
    return Number(value.toFixed(PRECISION));
}

function format(value: number): string {
    return String(round(value)).replace(/\.?0+$/, '') || '0';
}

/** Parse a WKT POLYGON's exterior ring. Returns null for anything else. */
export function parsePolygonRing(wkt: string | null | undefined): LngLat[] | null {
    if (!wkt) return null;

    const trimmed = wkt.trim().replace(/^SRID\s*=\s*\d+\s*;\s*/i, '');
    const match = /^POLYGON\s*\(\s*\((.+?)\)/is.exec(trimmed);

    if (!match) return null;

    const points: LngLat[] = [];

    for (const pair of match[1].split(',')) {
        const parts = pair.trim().split(/\s+/);
        if (parts.length < 2) return null;

        const lng = Number(parts[0]);
        const lat = Number(parts[1]);

        if (!Number.isFinite(lng) || !Number.isFinite(lat)) return null;

        points.push({ lng, lat });
    }

    return points.length >= 4 ? points : null;
}

/**
 * Emit a WKT POLYGON, closing the ring if the caller has not.
 *
 * MySQL rejects an unclosed ring with an error that does not name the offending
 * geometry, so closing here saves a genuinely painful debugging session.
 */
export function polygonToWkt(ring: LngLat[]): string | null {
    if (ring.length < 3) return null;

    const closed = [...ring];
    const first = closed[0];
    const last = closed[closed.length - 1];

    if (Math.abs(first.lng - last.lng) > 1e-9 || Math.abs(first.lat - last.lat) > 1e-9) {
        closed.push({ ...first });
    }

    if (closed.length < 4) return null;

    return `POLYGON((${closed.map((p) => `${format(p.lng)} ${format(p.lat)}`).join(', ')}))`;
}

/**
 * Shoelace centroid, matching Polygon::centroid() in PHP.
 *
 * The area-weighted centroid, not the mean of the vertices: an editor who
 * clicks densely along one edge would otherwise drag the pin toward it.
 */
export function ringCentroid(ring: LngLat[]): LngLat | null {
    if (ring.length < 3) return null;

    const closed = [...ring];
    const first = closed[0];
    const last = closed[closed.length - 1];

    if (first.lng !== last.lng || first.lat !== last.lat) closed.push({ ...first });

    let twiceArea = 0;
    let cx = 0;
    let cy = 0;

    for (let i = 0; i < closed.length - 1; i++) {
        const cross = closed[i].lng * closed[i + 1].lat - closed[i + 1].lng * closed[i].lat;
        twiceArea += cross;
        cx += (closed[i].lng + closed[i + 1].lng) * cross;
        cy += (closed[i].lat + closed[i + 1].lat) * cross;
    }

    if (Math.abs(twiceArea) < 1e-12) {
        const n = closed.length - 1;
        return {
            lng: round(closed.slice(0, n).reduce((s, p) => s + p.lng, 0) / n),
            lat: round(closed.slice(0, n).reduce((s, p) => s + p.lat, 0) / n),
        };
    }

    return { lng: round(cx / (3 * twiceArea)), lat: round(cy / (3 * twiceArea)) };
}

/*
 * Boundary complexity, decided on ALREADY-PARSED geometry.
 *
 * The picker edits exactly one hole-free ring; anything richer — a hole, a
 * second polygon — exists in the stored WKT but not in this editor's model,
 * and pretending otherwise is how a MultiPolygon gets silently replaced by a
 * crude single ring. Classification therefore runs on the wizard parser's
 * Component[] output (`fromWkt`, the one full-fidelity client parser this
 * codebase allows) — never by re-reading the WKT text with a fourth parser.
 */
export type BoundaryComplexity = 'none' | 'simple' | 'complex';

export function classifyBoundaryComponents(components: Component[]): BoundaryComplexity {
    if (components.length === 0) {
        return 'none';
    }

    if (components.length > 1) {
        return 'complex';
    }

    return components[0].holes.length > 0 ? 'complex' : 'simple';
}

/**
 * Full-fidelity, read-only rendering of a stored boundary.
 *
 * One component becomes ONE Polygon feature whose ring list is exterior
 * first, then holes — `toRenderedFeatures` is the wizard picker's own
 * conversion, reused verbatim so the two pickers cannot drift. The renderer
 * punches the holes out; a MultiPolygon renders as one feature per part.
 */
export function boundaryDisplayCollection(components: Component[]): BoundaryCollection {
    return {
        type: 'FeatureCollection',
        features: toRenderedFeatures(components, () => '').map((feature, index) => ({
            type: 'Feature',
            properties: { slug: `boundary-existing-${index}`, name: '', type: null },
            geometry: { type: 'Polygon', coordinates: feature.rings },
        })),
    };
}

/** Bounds across every vertex of every ring, for fitting the viewport. */
export function componentsBounds(components: Component[]): [[number, number], [number, number]] | null {
    const vertices = components.flatMap((component) => [component.exterior, ...component.holes].flat());

    return ringBounds(vertices.map((v) => ({ lng: v.lng, lat: v.lat })));
}

/** Bounds of a ring, for fitting the viewport. */
export function ringBounds(ring: LngLat[]): [[number, number], [number, number]] | null {
    if (ring.length === 0) return null;

    const lngs = ring.map((p) => p.lng);
    const lats = ring.map((p) => p.lat);

    return [
        [Math.min(...lngs), Math.min(...lats)],
        [Math.max(...lngs), Math.max(...lats)],
    ];
}
