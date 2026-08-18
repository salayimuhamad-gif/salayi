/**
 * Structural WKT for the boundary editor (spec 12.1).
 *
 * The previous implementation flattened every parenthesised group into a flat
 * list of rings, which destroyed the one distinction that matters: a POLYGON
 * with a hole came back as two SEPARATE FILLED components, so a courtyard
 * became a second building and the enclosed area was counted twice.
 *
 * Geometry is therefore represented the way WKT means it:
 *
 *   Component = { exterior, holes[] }
 *   Geometry  = Component[]        — one for POLYGON, several for MULTIPOLYGON
 *
 * These are pure functions with no Vue and no DOM, so the component and the
 * test suite import the SAME code. Copying them into the tests, as the first
 * version did, produced a suite that passed whether or not the component was
 * correct.
 */

export interface LatLng {
    lat: number;
    lng: number;
}

export interface Component {
    /** The filled outline. */
    exterior: LatLng[];
    /** Voids within it. Never filled, never separate shapes. */
    holes: LatLng[][];
}

/** Coordinates closer than this are the same point. */
const EPSILON = 1e-9;

function sameVertex(a: LatLng, b: LatLng): boolean {
    return Math.abs(a.lat - b.lat) < EPSILON && Math.abs(a.lng - b.lng) < EPSILON;
}

/**
 * Repeat the first vertex, which WKT requires.
 *
 * A FORMAT requirement, not a repair: the drawn shape is unchanged, and
 * anything genuinely malformed is reported by the server rule rather than
 * quietly corrected here.
 */
function close(ring: LatLng[]): LatLng[] {
    if (ring.length === 0) {
        return ring;
    }

    const first = ring[0];
    const last = ring[ring.length - 1];

    return sameVertex(first, last) ? [...ring] : [...ring, first];
}

/** Drop the repeated closing vertex, so editing does not show a duplicate. */
function open(ring: LatLng[]): LatLng[] {
    if (ring.length > 1 && sameVertex(ring[0], ring[ring.length - 1])) {
        return ring.slice(0, -1);
    }

    return [...ring];
}

function ringToWkt(ring: LatLng[]): string {
    return `(${close(ring)
        .map((v) => `${v.lng.toFixed(6)} ${v.lat.toFixed(6)}`)
        .join(', ')})`;
}

function componentToWkt(component: Component): string {
    // Exterior first, then holes — the order WKT defines.
    return [component.exterior, ...component.holes].map(ringToWkt).join(', ');
}

/** A ring needs three distinct vertices before it encloses anything. */
export function isUsableRing(ring: LatLng[]): boolean {
    const distinct = new Set(open(ring).map((v) => `${v.lat},${v.lng}`));

    return distinct.size >= 3;
}

/**
 * Serialise. Returns null when there is nothing usable to write, rather than
 * an empty POLYGON() that the server would then have to reject.
 */
export interface GeometryProblem {
    componentIndex: number;
    /** Null when the exterior itself is the problem. */
    holeIndex: number | null;
    reason: 'too_few_vertices' | 'duplicate_vertices';
}

/**
 * Everything wrong with this geometry, or an empty list.
 *
 * Silently filtering an unusable hole out of `toWkt` CHANGED THE BOUNDARY —
 * the person drew a courtyard, the courtyard quietly disappeared, and the saved
 * shape was not the one on screen. Reporting the problem and refusing to
 * serialise leaves the geometry intact so they can repair or delete it
 * deliberately.
 */
export function validateGeometry(components: Component[]): GeometryProblem[] {
    const problems: GeometryProblem[] = [];

    components.forEach((component, componentIndex) => {
        if (! isUsableRing(component.exterior)) {
            problems.push({ componentIndex, holeIndex: null, reason: 'too_few_vertices' });
        } else if (hasDuplicateVertices(component.exterior)) {
            problems.push({ componentIndex, holeIndex: null, reason: 'duplicate_vertices' });
        }

        component.holes.forEach((hole, holeIndex) => {
            if (! isUsableRing(hole)) {
                problems.push({ componentIndex, holeIndex, reason: 'too_few_vertices' });
            } else if (hasDuplicateVertices(hole)) {
                problems.push({ componentIndex, holeIndex, reason: 'duplicate_vertices' });
            }
        });
    });

    return problems;
}

/**
 * Two vertices at the same position.
 *
 * A move can collapse one onto another, which leaves the ring looking correct
 * in a vertex count while enclosing a degenerate shape.
 */
export function hasDuplicateVertices(ring: LatLng[]): boolean {
    const seen = new Set<string>();

    for (const vertex of open(ring)) {
        const key = `${vertex.lat},${vertex.lng}`;

        if (seen.has(key)) {
            return true;
        }

        seen.add(key);
    }

    return false;
}

/**
 * Serialise, or return null when the geometry is not valid.
 *
 * Callers check {@see validateGeometry} to find out WHY. Returning null rather
 * than a quietly-corrected string means a broken boundary blocks the save
 * instead of being stored as something the person did not draw.
 */
export function toWkt(components: Component[]): string | null {
    if (components.length > 0 && validateGeometry(components).length > 0) {
        return null;
    }

    const usable = components.filter((c) => isUsableRing(c.exterior));

    if (usable.length === 0) {
        return null;
    }

    if (usable.length === 1) {
        return `POLYGON(${componentToWkt(usable[0])})`;
    }

    return `MULTIPOLYGON(${usable.map((c) => `(${componentToWkt(c)})`).join(', ')})`;
}

function parseRing(group: string): LatLng[] {
    return open(
        group
            .replace(/[()]/g, '')
            .split(',')
            .map((pair) => pair.trim().split(/\s+/))
            .filter((parts) => parts.length === 2)
            .map((parts) => ({ lng: Number(parts[0]), lat: Number(parts[1]) }))
            .filter((v) => Number.isFinite(v.lat) && Number.isFinite(v.lng)),
    );
}

/**
 * Parse POLYGON or MULTIPOLYGON into components, holes intact.
 *
 * MULTIPOLYGON components are split on the `)), ((` boundary rather than by
 * counting groups: counting is exactly what flattened holes into components.
 */
export function fromWkt(wkt: string | null): Component[] {
    if (!wkt) {
        return [];
    }

    const trimmed = wkt.trim();
    const isMulti = /^MULTIPOLYGON/i.test(trimmed);
    const body = trimmed.replace(/^\s*(MULTI)?POLYGON\s*\(/i, '').replace(/\)\s*$/, '');

    const chunks = isMulti
        ? body.split(/\)\s*\)\s*,\s*\(\s*\(/).map((chunk, index, all) => {
            // Restore the parentheses the split consumed at each edge.
            let piece = chunk;

            if (index > 0) {
                piece = `((${piece}`;
            }

            if (index < all.length - 1) {
                piece = `${piece}))`;
            }

            return piece;
        })
        : [body];

    const components: Component[] = [];

    for (const chunk of chunks) {
        const rings = (chunk.match(/\(([-0-9.,\s]+)\)/g) ?? [])
            .map(parseRing)
            .filter((ring) => ring.length >= 3);

        if (rings.length === 0) {
            continue;
        }

        // First ring is the exterior; the rest are its holes.
        components.push({ exterior: rings[0], holes: rings.slice(1) });
    }

    return components;
}

/* ------------------------------------------------------------- editing */

export function addVertex(components: Component[], index: number, vertex: LatLng): Component[] {
    return components.map((c, i) =>
        i === index ? { ...c, exterior: [...c.exterior, vertex] } : c);
}

export function moveVertex(
    components: Component[],
    index: number,
    vertexIndex: number,
    vertex: LatLng,
): Component[] {
    return components.map((c, i) => {
        if (i !== index) {
            return c;
        }

        const exterior = [...c.exterior];
        exterior[vertexIndex] = vertex;

        return { ...c, exterior };
    });
}

export function removeVertex(components: Component[], index: number, vertexIndex: number): Component[] {
    return components.map((c, i) =>
        i === index
            ? { ...c, exterior: c.exterior.filter((_, v) => v !== vertexIndex) }
            : c);
}

/** Delete one component. Its holes go with it — they are part of the shape. */
export function removeComponent(components: Component[], index: number): Component[] {
    return components.filter((_, i) => i !== index);
}

/**
 * Delete a hole, keeping the component.
 *
 * Distinct from deleting the component: removing a courtyard should not
 * remove the building around it.
 */
export function removeHole(components: Component[], index: number, holeIndex: number): Component[] {
    return components.map((c, i) =>
        i === index ? { ...c, holes: c.holes.filter((_, h) => h !== holeIndex) } : c);
}

export function addHole(components: Component[], index: number, ring: LatLng[]): Component[] {
    return components.map((c, i) => (i === index ? { ...c, holes: [...c.holes, ring] } : c));
}

/* ------------------------------------------------------------ rendering */

export interface RenderedFeature {
    /** Exterior first, then holes — GeoJSON's own meaning of a Polygon. */
    rings: [number, number][][];
    label: string;
}

/** Close a ring for output. A format requirement, not a change of shape. */
function closeForOutput(ring: LatLng[]): [number, number][] {
    return [...ring, ring[0]].map((v): [number, number] => [v.lng, v.lat]);
}

/**
 * One component becomes ONE feature whose ring list is exterior then holes.
 *
 * Pushing each ring as its own feature drew a courtyard as a second building.
 * Extracted here so the component and the test exercise the same function
 * rather than two that merely look alike.
 */
export function toRenderedFeatures(components: Component[], label: (i: number) => string): RenderedFeature[] {
    return components
        .filter((component) => component.exterior.length >= 3)
        .map((component, index) => ({
            rings: [
                closeForOutput(component.exterior),
                ...component.holes.filter((ring) => ring.length >= 3).map(closeForOutput),
            ],
            label: label(index),
        }));
}

/**
 * Whether a hole may be started on this component.
 *
 * A void inside nothing is not geometry; accepting it produces WKT the server
 * rejects after the person has done the work.
 */
export function canStartHole(component: Component | undefined): boolean {
    return component !== undefined && isUsableRing(component.exterior);
}

/** Move one vertex of one hole, leaving the exterior and other holes alone. */
export function moveHoleVertex(
    components: Component[],
    index: number,
    holeIndex: number,
    vertexIndex: number,
    vertex: LatLng,
): Component[] {
    return components.map((component, i) => {
        if (i !== index) {
            return component;
        }

        return {
            ...component,
            holes: component.holes.map((ring, h) => {
                if (h !== holeIndex) {
                    return ring;
                }

                const next = [...ring];
                next[vertexIndex] = vertex;

                return next;
            }),
        };
    });
}

/**
 * Whether a hole vertex may be removed.
 *
 * Below three distinct vertices a hole encloses nothing, and `toWkt` would
 * serialise it anyway — producing geometry the server rejects after the person
 * has done the work. Refusing the deletion is the honest answer: the hole
 * cannot get smaller, and saying so beats silently dropping it.
 */
export function canRemoveHoleVertex(
    components: Component[],
    index: number,
    holeIndex: number,
    vertexIndex?: number,
): boolean {
    const ring = components[index]?.holes[holeIndex];

    if (ring === undefined) {
        return false;
    }

    /*
     * DISTINCT vertices after the proposed removal, not the raw length.
     *
     * A ring can carry duplicates — a move that collapsed one onto another —
     * so `length > 3` allowed a deletion that left two distinct points, which
     * encloses nothing.
     */
    const remaining = vertexIndex === undefined
        ? ring.slice(0, -1)
        : ring.filter((_, i) => i !== vertexIndex);

    return new Set(remaining.map((v) => `${v.lat},${v.lng}`)).size >= 3;
}

/** Remove one vertex from one hole, when that leaves a usable ring. */
export function removeHoleVertex(
    components: Component[],
    index: number,
    holeIndex: number,
    vertexIndex: number,
): Component[] {
    // Refused rather than allowed-then-serialised-invalid.
    if (! canRemoveHoleVertex(components, index, holeIndex, vertexIndex)) {
        return components;
    }

    return components.map((component, i) =>
        (i === index
            ? {
                ...component,
                holes: component.holes.map((ring, h) =>
                    (h === holeIndex ? ring.filter((_, v) => v !== vertexIndex) : ring)),
            }
            : component));
}

export interface HoleSelection {
    componentIndex: number;
    holeIndex: number;
}

/**
 * Toggle a hole selection.
 *
 * Compared as ONE value. Two independent refs let the component index be
 * updated before the hole index was tested against it, so switching from
 * hole 0 of A to hole 0 of B closed the editor instead of opening B.
 */
export function toggleHoleSelection(
    current: HoleSelection | null,
    componentIndex: number,
    holeIndex: number,
): HoleSelection | null {
    const same = current !== null
        && current.componentIndex === componentIndex
        && current.holeIndex === holeIndex;

    return same ? null : { componentIndex, holeIndex };
}

/**
 * Parse a typed coordinate pair as ONE value.
 *
 * Rebuilding the missing half from committed props emitted a point made of one
 * fresh number and one stale one — a location nobody is at. Undefined means
 * "not yet a usable pair", which is different from null ("cleared").
 */
export function parseTypedPoint(lat: string, lng: string): LatLng | null | undefined {
    const latText = lat.trim();
    const lngText = lng.trim();

    if (latText === '' || lngText === '') {
        return null;   // cleared
    }

    const parsed = { lat: Number(latText), lng: Number(lngText) };

    if (! Number.isFinite(parsed.lat) || ! Number.isFinite(parsed.lng)) {
        return undefined;   // mid-typing: "-", "36."
    }

    if (Math.abs(parsed.lat) > 90 || Math.abs(parsed.lng) > 180) {
        return undefined;   // out of range: say nothing rather than a wrong point
    }

    return parsed;
}

/* --------------------------------------------------------- media order */

/** Swap an item with its neighbour. Out-of-range moves are no-ops, not errors. */
export function moveItem<T>(items: T[], index: number, delta: number): T[] {
    const next = [...items];
    const target = index + delta;

    if (target < 0 || target >= next.length) {
        return next;
    }

    [next[index], next[target]] = [next[target], next[index]];

    return next;
}

/** Move an item to a new position, as a drag-and-drop does. */
export function moveItemTo<T>(items: T[], from: number, to: number): T[] {
    if (from === to || from < 0 || from >= items.length) {
        return [...items];
    }

    const next = [...items];
    const [moved] = next.splice(from, 1);
    next.splice(to, 0, moved);

    return next;
}

/* ------------------------------------------------- request sequencing */

/**
 * Monotonic request gate.
 *
 * The nearby preview fired once per coordinate, so moving a point issued two
 * requests with MIXED coordinates — old latitude, new longitude — and whichever
 * returned last won. The result was a suggested area for a location nobody
 * chose.
 *
 * `begin()` returns a token; `isCurrent()` says whether that token is still the
 * newest. An older response is discarded rather than applied.
 */
/**
 * Trailing-edge throttle.
 *
 * Dragging a marker emits a stream of positions; without this each one is a
 * request. The gate below discards stale RESPONSES, but the requests are still
 * sent — on a shared host that is real load for answers nobody will read.
 *
 * Trailing rather than leading: the position that matters is where the pointer
 * stopped, not where it started.
 */
export function createThrottle(delayMs: number): {
    schedule: (run: () => void) => void;
    cancel: () => void;
} {
    let timer: ReturnType<typeof setTimeout> | null = null;

    return {
        schedule: (run: () => void) => {
            if (timer !== null) {
                clearTimeout(timer);
            }

            timer = setTimeout(() => {
                timer = null;
                run();
            }, delayMs);
        },
        cancel: () => {
            if (timer !== null) {
                clearTimeout(timer);
                timer = null;
            }
        },
    };
}

export function createRequestGate(): { begin: () => number; isCurrent: (token: number) => boolean } {
    let latest = 0;

    return {
        begin: () => {
            latest += 1;

            return latest;
        },
        isCurrent: (token: number) => token === latest,
    };
}
