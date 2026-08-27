/**
 * Pure TypeScript tests — no browser, no bundler, no test framework.
 *
 * Compiled by tests/js/run.mjs and executed with node. A framework would be
 * one more dependency that fails to install where Packagist and half the
 * network are blocked; these need only tsc, which is already a devDependency.
 *
 * Two groups:
 *
 *   1. GeoJSON conversion, which is pure data and needs nothing stubbed.
 *   2. The Google adapter's script and authentication lifecycles, exercised
 *      against a minimal document/window stub. Those paths are where the
 *      hangs and the leaked globals live, and they are unreachable by any
 *      check that does not actually run them.
 */

import {
    boundaryBounds,
    isClockwise,
    normaliseComponent,
    ringToPath,
    signedArea,
    toGooglePolygons,
    toPolygonComponents,
    type BoundaryGeometry,
    type LinearRing,
} from '../../resources/js/lib/map/geojson';

let failures = 0;

function ok(name: string, condition: boolean): void {
    if (condition) {
        console.log(`  pass ${name}`);
    } else {
        failures += 1;
        console.log(`  FAIL ${name}`);
    }
}

function equal(name: string, actual: unknown, expected: unknown): void {
    ok(name, JSON.stringify(actual) === JSON.stringify(expected));
}

/* ------------------------------------------------------ GeoJSON geometry */

console.log('\n=== GeoJSON conversion ===');

const square: BoundaryGeometry = {
    type: 'Polygon',
    coordinates: [
        [
            [44.0, 36.18],
            [44.02, 36.18],
            [44.02, 36.2],
            [44.0, 36.2],
            [44.0, 36.18],
        ],
    ],
};

ok('a simple polygon is one component', toPolygonComponents(square).length === 1);

// The order flip is the whole reason ringToPath exists as a named unit.
equal('ring converts [lng, lat] -> {lat, lng}', ringToPath(square.coordinates[0])[0], {
    lat: 36.18,
    lng: 44.0,
});

ok(
    'longitude is not mistaken for latitude',
    ringToPath(square.coordinates[0]).every((point) => point.lng > 43 && point.lat < 37),
);

/* --------------------------------------------------------------- winding */

const ccw: LinearRing = [
    [0, 0],
    [1, 0],
    [1, 1],
    [0, 1],
    [0, 0],
];
const cw: LinearRing = [...ccw].reverse();

ok('signedArea is positive for counter-clockwise', signedArea(ccw) > 0);
ok('isClockwise detects clockwise', isClockwise(cw));
ok('isClockwise rejects counter-clockwise', !isClockwise(ccw));

/*
 * The dangerous case: a hole exported with the SAME winding as its exterior.
 * Google infers holes from winding, so an unnormalised same-winding hole
 * renders as a second filled shape rather than a void — the map then claims
 * territory the area does not have, and looks entirely correct doing it.
 */
const sameWinding: LinearRing[] = [ccw, [...ccw]];
const normalisedSame = normaliseComponent(sameWinding);

ok('exterior is normalised counter-clockwise', !isClockwise(normalisedSame[0]));
ok('a same-winding hole is reversed to clockwise', isClockwise(normalisedSame[1]));

// Already-correct data must pass through untouched.
const alreadyCorrect: LinearRing[] = [ccw, cw];
const normalisedCorrect = normaliseComponent(alreadyCorrect);

equal('a correctly wound exterior is unchanged', normalisedCorrect[0], ccw);
equal('a correctly wound hole is unchanged', normalisedCorrect[1], cw);

/* ----------------------------------------------------------- holes/parts */

const withHole: BoundaryGeometry = {
    type: 'Polygon',
    coordinates: [
        square.coordinates[0],
        [
            [44.005, 36.185],
            [44.01, 36.185],
            [44.01, 36.19],
            [44.005, 36.19],
            [44.005, 36.185],
        ],
    ],
};

ok('a polygon with a hole is still ONE component', toPolygonComponents(withHole).length === 1);
ok('that component carries exterior + hole', toGooglePolygons(withHole)[0].length === 2);

const multi: BoundaryGeometry = {
    type: 'MultiPolygon',
    coordinates: [
        square.coordinates,
        [
            [
                [44.03, 36.21],
                [44.04, 36.21],
                [44.04, 36.22],
                [44.03, 36.22],
                [44.03, 36.21],
            ],
        ],
    ],
};

ok('a multipolygon yields one component per part', toGooglePolygons(multi).length === 2);
equal('the second part keeps its own coordinates', toGooglePolygons(multi)[1][0][0], {
    lat: 36.21,
    lng: 44.03,
});

/*
 * Both components carry holes. Flattening these into one Google Polygon let a
 * hole from one component be punched out of the other; each must stay with its
 * own exterior.
 */
const multiWithHoles: BoundaryGeometry = {
    type: 'MultiPolygon',
    coordinates: [withHole.coordinates, withHole.coordinates],
};

const components = toGooglePolygons(multiWithHoles);

ok('a multipolygon with holes yields two components', components.length === 2);
ok('component one keeps exactly its own hole', components[0].length === 2);
ok('component two keeps exactly its own hole', components[1].length === 2);
ok(
    'holes are not pooled across components',
    components.every((component) => component.length === 2),
);

/* -------------------------------------------------------- boundaryBounds */

/*
 * The camera-fit extent (Map Phase 3). Order-of-coordinates bugs here send
 * fitBounds to the Indian Ocean, and a degenerate geometry must yield null —
 * the explorer then falls back to the area's centroid instead of fitting a
 * box made of Infinities.
 */

equal('a polygon yields its own extent', boundaryBounds(square), {
    north: 36.2,
    south: 36.18,
    east: 44.02,
    west: 44.0,
});

// The hole lies inside the exterior, so the extent must not change.
equal('holes never widen the extent', boundaryBounds(withHole), boundaryBounds(square));

equal('a multipolygon spans every part', boundaryBounds(multi), {
    north: 36.22,
    south: 36.18,
    east: 44.04,
    west: 44.0,
});

equal('an empty polygon yields null', boundaryBounds({ type: 'Polygon', coordinates: [] }), null);

equal(
    'a ring of non-finite points yields null',
    boundaryBounds({ type: 'Polygon', coordinates: [[[Number.NaN, Number.NaN], [Infinity, 36.2]]] }),
    null,
);

equal(
    'non-finite points are skipped, finite ones still count',
    boundaryBounds({
        type: 'Polygon',
        coordinates: [[[44.0, 36.18], [Number.NaN, 99], [44.02, 36.2]]],
    }),
    { north: 36.2, south: 36.18, east: 44.02, west: 44.0 },
);

/* -------------------------------------------- Google adapter lifecycles */

console.log('\n=== Google adapter lifecycle ===');

interface StubScript {
    id: string;
    src: string;
    async: boolean;
    defer: boolean;
    dataset: Record<string, string>;
    listeners: Record<string, Array<() => void>>;
    addEventListener(type: string, handler: () => void): void;
    removeEventListener(type: string, handler: () => void): void;
    remove(): void;
    fire(type: string): void;
    listenerCount(): number;
}

function makeScript(id = ''): StubScript {
    return {
        id,
        src: '',
        async: false,
        defer: false,
        dataset: {},
        listeners: { load: [], error: [] },
        addEventListener(type, handler) {
            (this.listeners[type] ??= []).push(handler);
        },
        removeEventListener(type, handler) {
            this.listeners[type] = (this.listeners[type] ?? []).filter((entry) => entry !== handler);
        },
        remove() {
            removed.push(this.id);
        },
        fire(type) {
            [...(this.listeners[type] ?? [])].forEach((handler) => handler());
        },
        listenerCount() {
            return Object.values(this.listeners).reduce((total, list) => total + list.length, 0);
        },
    };
}

const removed: string[] = [];
let domScript: StubScript | null = null;

interface TestGlobal {
    document: unknown;
    window: unknown;
    google?: unknown;
    gm_authFailure?: (() => void) | undefined;
}

const globalObject = globalThis as unknown as TestGlobal;

/**
 * Read the current authentication handler without narrowing.
 *
 * Assigning `undefined` to an optional property narrows it to `never` for the
 * rest of the scope, so a later `authHandler()?.()` is a type
 * error even though it is correct at runtime. Vite transpiles without
 * checking types, so this was invisible until the suite gained its own
 * `tsc --noEmit` pass.
 */
function authHandler(): (() => void) | undefined {
    return globalObject.gm_authFailure;
}

globalObject.document = {
    getElementById: (id: string) => (domScript && domScript.id === id ? domScript : null),
    createElement: () => makeScript(),
    head: { appendChild: (node: StubScript) => { domScript = node; } },
};
globalObject.window = globalObject;

const { GoogleMapsAdapter } = await import('../../resources/js/lib/map/google');

function options(container: unknown = {}) {
    return {
        container: container as HTMLElement,
        styleUrl: null,
        googleKey: 'test-key',
        centre: { lat: 36.19, lng: 44.009 },
        zoom: 11,
        events: { onMoveEnd: () => {}, onClick: () => {}, onError: () => {} },
    };
}

/*
 * A script that has ALREADY loaded successfully. The previous implementation
 * attached a `load` listener to it and waited for an event that had already
 * fired — a permanent hang on the second visit to the map.
 */
{
    domScript = makeScript('mulkihawler-google-maps');
    globalObject.google = { maps: { /* present = already initialised */ } };

    let settled = false;
    // Construction fails later (no real Map), but loadScript must not hang.
    void GoogleMapsAdapter.create(options()).catch(() => {}).finally(() => {
        settled = true;
    });

    await new Promise((resolve) => setTimeout(resolve, 20));
    ok('an already-loaded script does not hang', settled);
}

/*
 * A script marked failed. It will never fire another event, so waiting on it
 * is a permanent hang; it must be discarded and re-requested.
 */
{
    removed.length = 0;
    globalObject.google = undefined;
    domScript = makeScript('mulkihawler-google-maps');
    domScript.dataset.failed = 'true';

    void GoogleMapsAdapter.create(options()).catch(() => {});
    await new Promise((resolve) => setTimeout(resolve, 20));

    ok('a previously failed script is removed', removed.includes('mulkihawler-google-maps'));
    ok('a replacement script is created', domScript?.dataset.failed !== 'true');
}

/*
 * A stalled in-flight script: listeners attach, and the error path cleans up
 * after itself rather than leaving handlers behind.
 */
{
    globalObject.google = undefined;
    domScript = makeScript('mulkihawler-google-maps');
    const stalled = domScript;

    const attempt = GoogleMapsAdapter.create(options()).catch((error: Error) => error.message);
    await new Promise((resolve) => setTimeout(resolve, 10));

    ok('listeners are attached to an in-flight script', stalled.listenerCount() > 0);

    stalled.fire('error');
    const message = await attempt;

    ok('a script error rejects', message === 'google-maps-script-failed');
    ok('listeners are removed after settlement', stalled.listenerCount() === 0);
    ok('the failed script is marked', stalled.dataset.failed === 'true');
}

/*
 * A full successful lifecycle, so the authentication handler is actually
 * installed and can be checked on destroy. The Google API is stubbed far
 * enough for construction and a `tilesloaded` event.
 */
interface OverlayRecord { paths: unknown; attached: boolean }

interface ListenerRecord { event: string; removed: boolean; fire: () => void }

/** Registered Google listeners, so removal can be asserted. */
const listeners: ListenerRecord[] = [];

/** A container stub that records what a provider injected into it. */
function makeContainer(): { innerHTML: string; children: number } {
    return { innerHTML: 'GOOGLE-DOM', children: 1 };
}

const overlays: { polygons: OverlayRecord[]; markers: OverlayRecord[] } = {
    polygons: [],
    markers: [],
};

/**
 * @param fireTiles false to simulate a map that constructs but never renders
 *                  a tile — the shape of an authentication failure.
 */
function installWorkingGoogleStub(fireTiles = true): void {
    overlays.polygons = [];
    overlays.markers = [];
    listeners.length = 0;

    globalObject.google = {
        maps: {
            Map: class {
                getBounds() { return undefined; }
                getZoom() { return 11; }
                panTo() {}
                setZoom() {}
                addListener(event: string, handler: () => void) {
                    const record: ListenerRecord = { event, removed: false, fire: handler };
                    listeners.push(record);

                    return { remove() { record.removed = true; } };
                }
            },
            Marker: class {
                record: OverlayRecord;
                constructor(opts: { position: unknown }) {
                    this.record = { paths: opts.position, attached: true };
                    overlays.markers.push(this.record);
                }
                setMap(map: unknown) { this.record.attached = map !== null; }
            },
            Polygon: class {
                record: OverlayRecord;
                constructor(opts: { paths: unknown }) {
                    this.record = { paths: opts.paths, attached: true };
                    overlays.polygons.push(this.record);
                }
                setMap(map: unknown) { this.record.attached = map !== null; }
            },
            event: {
                addListenerOnce(_instance: unknown, event: string, handler: () => void) {
                    const record: ListenerRecord = { event, removed: false, fire: handler };
                    listeners.push(record);

                    if (fireTiles) {
                        setTimeout(handler, 0);
                    }

                    return { remove() { record.removed = true; } };
                },
            },
        },
    };
}

{
    installWorkingGoogleStub();
    domScript = makeScript('mulkihawler-google-maps');

    let sentinelCalls = 0;
    const sentinel = (): void => { sentinelCalls += 1; };
    globalObject.gm_authFailure = sentinel;

    const adapter = await GoogleMapsAdapter.create(options());

    ok('a live adapter owns the auth handler', authHandler() !== sentinel);

    adapter.destroy();

    ok(
        'destroy restores the previous auth handler',
        authHandler() === sentinel,
    );

    /*
     * A late authentication failure must not reach a destroyed adapter, and
     * must still reach whoever owned the global before it.
     *
     * The stale handler is invoked directly on purpose — a real caller would
     * go through window.gm_authFailure, which destroy() has already pointed
     * back at the sentinel, so this is the stricter of the two checks: even a
     * dangling reference must be inert.
     */
    let errorsAfterDestroy = 0;
    const destroyedAdapter = await GoogleMapsAdapter.create({
        ...options(),
        events: { onMoveEnd: () => {}, onClick: () => {}, onError: () => { errorsAfterDestroy += 1; } },
    });
    const staleHandler = authHandler();
    destroyedAdapter.destroy();
    staleHandler?.();

    ok('a stale handler from a destroyed adapter is inert', errorsAfterDestroy === 0);

    // The live path: a real failure now reaches the sentinel directly.
    authHandler()?.();

    ok('the previous owner receives failures after destroy', sentinelCalls === 1);

    globalObject.gm_authFailure = undefined;
}

/*
 * Repeated creation and destruction. The global must end where it started, and
 * a single authentication failure must produce exactly one fallback — not one
 * per adapter that has ever existed.
 */
{
    installWorkingGoogleStub();
    domScript = makeScript('mulkihawler-google-maps');

    const before = authHandler();
    let fallbacks = 0;

    for (let i = 0; i < 3; i++) {
        const adapter = await GoogleMapsAdapter.create({
            ...options(),
            events: { onMoveEnd: () => {}, onClick: () => {}, onError: () => { fallbacks += 1; } },
        });
        adapter.destroy();
    }

    ok('repeated create/destroy leaves the global as it started', authHandler() === before);

    // One live adapter, one failure, one fallback.
    const live = await GoogleMapsAdapter.create({
        ...options(),
        events: { onMoveEnd: () => {}, onClick: () => {}, onError: () => { fallbacks += 1; } },
    });

    // Read through a local: TypeScript narrows the global to `never` after the
    // loop above, which the transpile-only path never noticed.
    const liveHandler = authHandler();

    liveHandler?.();
    liveHandler?.();

    ok('one auth failure triggers exactly one fallback', fallbacks === 1);

    live.destroy();
    globalObject.gm_authFailure = undefined;
}

/*
 * Authentication fails BEFORE tilesloaded — the commonest real Google failure
 * (invalid key, referrer restriction, disabled billing). Construction must
 * reject, and the failed attempt must leave the previous global handler in
 * place rather than owned by a half-built adapter nobody holds.
 */
{
    installWorkingGoogleStub(false);
    domScript = makeScript('mulkihawler-google-maps');

    let sentinelCalls = 0;
    const sentinel = (): void => { sentinelCalls += 1; };
    globalObject.gm_authFailure = sentinel;

    let rejected: string | null = null;
    const attempt = GoogleMapsAdapter.create(options()).then(
        () => null,
        (error: Error) => { rejected = error.message; return null; },
    );

    // Trigger the authentication failure while construction is still pending.
    await new Promise((resolve) => setTimeout(resolve, 5));
    authHandler()?.();
    await attempt;

    ok('auth failure before tilesloaded rejects construction', rejected === 'google-maps-auth-failed');
    ok(
        'a failed construction restores the previous global handler',
        authHandler() === sentinel,
    );
    ok('the previous owner was notified once', sentinelCalls === 1);

    globalObject.gm_authFailure = undefined;
}

/*
 * A stale handler must do NOTHING — neither notify the adapter nor forward to
 * the previous owner. Forwarding would double-notify, because destroy() has
 * already restored that owner as the live global.
 */
{
    installWorkingGoogleStub();
    domScript = makeScript('mulkihawler-google-maps');

    let previousCalls = 0;
    const previous = (): void => { previousCalls += 1; };
    globalObject.gm_authFailure = previous;

    let adapterErrors = 0;
    const adapter = await GoogleMapsAdapter.create({
        ...options(),
        events: { onMoveEnd: () => {}, onClick: () => {}, onError: () => { adapterErrors += 1; } },
    });

    const stale = authHandler();
    adapter.destroy();

    stale?.();

    ok('a stale handler does not notify the adapter', adapterErrors === 0);
    ok('a stale handler does not forward to the previous owner', previousCalls === 0);

    // The restored live global still works normally.
    authHandler()?.();

    ok('the restored global calls the previous owner', previousCalls === 1);

    globalObject.gm_authFailure = undefined;
}

/*
 * Overlay replacement and cleanup. Every previous polygon must be detached
 * with setMap(null) before the next set is created, or the map accumulates one
 * set per viewport change.
 */
{
    installWorkingGoogleStub();
    domScript = makeScript('mulkihawler-google-maps');

    const adapter = await GoogleMapsAdapter.create(options());

    const collection = {
        type: 'FeatureCollection' as const,
        features: [
            { type: 'Feature' as const, geometry: multiWithHoles, properties: { slug: 'a', name: 'A', type: null } },
        ],
    };

    adapter.setBoundaries(collection);

    ok('one overlay per multipolygon component', overlays.polygons.length === 2);
    ok('all overlays are attached', overlays.polygons.every((o) => o.attached));

    adapter.setBoundaries(collection);

    ok('replacement creates a fresh set', overlays.polygons.length === 4);
    ok('the first set was detached', overlays.polygons.slice(0, 2).every((o) => !o.attached));
    ok('the second set is attached', overlays.polygons.slice(2).every((o) => o.attached));

    adapter.setPoints([{ lat: 36.19, lng: 44.0, title: 'x', colour: '#000' }]);

    ok('markers are created', overlays.markers.length === 1);

    adapter.destroy();

    ok('destroy detaches every polygon', overlays.polygons.every((o) => !o.attached));
    ok('destroy detaches every marker', overlays.markers.every((o) => !o.attached));

    // Idempotent: a second destroy must not throw or restore twice.
    adapter.destroy();

    ok('destroy is idempotent', true);
}

/* ------------------------------------------- listener and DOM lifecycle */

/*
 * Events already queued in the browser's task queue still fire after destroy().
 * Removing the handle is necessary but not sufficient — every callback also
 * has to check `destroyed`, or a torn-down adapter drives the explorer.
 */
{
    installWorkingGoogleStub();
    domScript = makeScript('mulkihawler-google-maps');
    const container = makeContainer();

    let moves = 0;
    let clicks = 0;

    const adapter = await GoogleMapsAdapter.create({
        ...options(container),
        events: {
            onMoveEnd: () => { moves += 1; },
            onClick: () => { clicks += 1; },
            onError: () => {},
        },
    });

    listeners.filter((entry) => entry.event === 'idle').forEach((entry) => entry.fire());
    listeners.filter((entry) => entry.event === 'click').forEach((entry) => entry.fire());
    ok('a live adapter receives idle', moves === 1);

    adapter.destroy();

    ok('every listener handle is removed on destroy', listeners.every((entry) => entry.removed));

    const movesBefore = moves;
    const clicksBefore = clicks;

    listeners.filter((entry) => entry.event === 'idle').forEach((entry) => entry.fire());
    listeners.filter((entry) => entry.event === 'click').forEach((entry) => entry.fire());
    ok('idle after destroy does nothing', moves === movesBefore);
    ok('click after destroy does nothing', clicks === clicksBefore);

    ok('destroy empties the Google container', container.innerHTML === '');
}

/*
 * A construction that never renders a tile must leave a clean container, so
 * the MapLibre fallback is not stacked on top of a dead Google map.
 */
{
    installWorkingGoogleStub(false);
    domScript = makeScript('mulkihawler-google-maps');
    const container = makeContainer();

    let errors = 0;
    const attempt = GoogleMapsAdapter.create({
        ...options(container),
        events: { onMoveEnd: () => {}, onClick: () => {}, onError: () => { errors += 1; } },
    }).catch(() => null);

    await new Promise((resolve) => setTimeout(resolve, 5));
    authHandler()?.();
    await attempt;

    ok('a failed construction leaves the container clean', container.innerHTML === '');
    ok('a failed construction removes its listeners', listeners.every((entry) => entry.removed));

    // A tilesloaded arriving after the failed construction must change nothing.
    listeners.filter((entry) => entry.event === 'tilesloaded').forEach((entry) => entry.fire());
    ok('tilesloaded after failed construction does nothing', errors === 0);

    globalObject.gm_authFailure = undefined;
}

/*
 * A previous authentication handler that throws must not prevent the fallback.
 * A third-party error handler's bug should not leave the visitor on a dead
 * grey canvas.
 */
{
    installWorkingGoogleStub();
    domScript = makeScript('mulkihawler-google-maps');

    globalObject.gm_authFailure = () => { throw new Error('previous handler exploded'); };

    let fallbacks = 0;
    const adapter = await GoogleMapsAdapter.create({
        ...options(makeContainer()),
        events: { onMoveEnd: () => {}, onClick: () => {}, onError: () => { fallbacks += 1; } },
    });

    let threw = false;

    try {
        authHandler()?.();
    } catch {
        threw = true;
    }

    ok('a throwing previous handler still triggers the fallback', fallbacks === 1);
    ok("the previous handler's exception is not surfaced", !threw);

    adapter.destroy();
    globalObject.gm_authFailure = undefined;
}

/*
 * Repeated fallback must not accumulate DOM or listeners.
 */
{
    let liveListeners = 0;

    for (let i = 0; i < 3; i++) {
        installWorkingGoogleStub();
        domScript = makeScript('mulkihawler-google-maps');
        const container = makeContainer();

        const adapter = await GoogleMapsAdapter.create(options(container));
        adapter.destroy();

        liveListeners += listeners.filter((entry) => !entry.removed).length;

        if (container.innerHTML !== '') {
            failures += 1;
        }
    }

    ok('repeated fallback leaves no live listeners', liveListeners === 0);
    ok('repeated fallback leaves no container DOM', true);

    globalObject.gm_authFailure = undefined;
}

console.log(
    failures === 0
        ? '\nALL TYPESCRIPT ASSERTIONS PASSED\n'
        : `\n${failures} TYPESCRIPT FAILURES\n`,
);

process.exit(failures === 0 ? 0 : 1);
