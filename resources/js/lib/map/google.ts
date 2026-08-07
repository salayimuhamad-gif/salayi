import { toGooglePolygons } from './geojson';
import type {
    AdapterOptions,
    BoundaryCollection,
    LatLng,
    MapAdapter,
    MapBounds,
    PointFeature,
} from './types';

/*
 * Minimal ambient typing for the Google Maps JS API.
 *
 * `@types/google.maps` is not a dependency and adding it would pull a large
 * type package for one optional provider. Only the surface this adapter
 * actually touches is declared, which also keeps the adapter honest: anything
 * not declared here is not used.
 */
interface GLatLngLiteral { lat: number; lng: number }
interface GLatLng { lat(): number; lng(): number }
interface GBounds {
    getNorthEast(): GLatLng;
    getSouthWest(): GLatLng;
}
interface GMarker { setMap(map: unknown): void }
interface GPolygon { setMap(map: unknown): void }
/** Google returns a handle from every listener registration; .remove() detaches. */
interface GMapsEventListener { remove(): void }
interface GMap {
    getBounds(): GBounds | undefined;
    getZoom(): number | undefined;
    panTo(point: GLatLngLiteral): void;
    setZoom(zoom: number): void;
    addListener(event: string, handler: (payload?: { latLng?: GLatLng }) => void): GMapsEventListener;
}
interface GoogleMapsApi {
    maps: {
        Map: new (container: HTMLElement, options: Record<string, unknown>) => GMap;
        Marker: new (options: Record<string, unknown>) => GMarker;
        Polygon: new (options: Record<string, unknown>) => GPolygon;
        event: {
            addListenerOnce(instance: unknown, event: string, handler: () => void): GMapsEventListener;
        };
    };
}

declare global {
    interface Window {
        google?: GoogleMapsApi;
        /*
         * Google calls this global on authentication failure — an invalid key,
         * a referrer restriction, a disabled billing account. It is the ONLY
         * signal for those cases: the script loads with HTTP 200, the Map
         * constructor succeeds, and the canvas then renders a grey
         * "for development purposes only" overlay. Constructor success proves
         * nothing, which is why readiness cannot be assumed from it.
         */
        gm_authFailure?: () => void;
    }
}

const SCRIPT_ID = 'mulkihawler-google-maps';
const LOAD_TIMEOUT_MS = 10_000;

/**
 * How long to wait for the first tile.
 *
 * Longer than script load because tiles cross the network after the API
 * initialises, and Erbil mobile data is not fast. Short enough that a visitor
 * is not left watching a grey rectangle.
 */
const TILE_TIMEOUT_MS = 12_000;

/**
 * Google Maps adapter (§10.1, optional provider).
 *
 * Used only when Google is BOTH requested and given a key — the server decides
 * that and sends `provider`. This class never second-guesses it; it either
 * renders Google or throws, and the factory converts a throw into a MapLibre
 * fallback with a stated reason.
 *
 * Throwing rather than silently degrading is the point. The previous
 * behaviour reported `provider: "google"` while rendering MapLibre, so a
 * misconfigured key was indistinguishable from a working one.
 *
 * Points are clustered by a small built-in grid clusterer rather than by
 * Google's MarkerClusterer, which is a separate CDN script. Behaviour matches
 * the MapLibre path, so switching provider does not change how much the map
 * can show before it stutters. See setPoints().
 */
export class GoogleMapsAdapter implements MapAdapter {
    readonly provider = 'google' as const;

    private map: GMap | null = null;
    private markers: GMarker[] = [];
    private polygons: GPolygon[] = [];
    private loadedResolve: (() => void) | null = null;
    private loaded: Promise<void>;
    /** True once a tile has actually arrived — the only proof Google works. */
    private tilesLoaded = false;

    /**
     * Where a failure goes, which changes once the map is live.
     *
     * Before `tilesloaded` a failure must reject construction so the factory
     * can fall back to MapLibre. After it, the explorer already holds this
     * adapter, so the failure is an event rather than a rejection.
     */
    private failed: (reason: string) => void = () => {};

    /** Whatever owned window.gm_authFailure before this adapter existed. */
    private previousAuthHandler: (() => void) | undefined;

    /** This instance's handler, kept so destroy() can identify and remove it. */
    private authHandler: (() => void) | null = null;

    /** Once true, every late callback is ignored. */
    private destroyed = false;

    /**
     * Every listener handle this adapter registered.
     *
     * Google listeners outlive the object that created them: they are held by
     * the Map instance, not by this class, so dropping the reference detaches
     * nothing. An `idle` handler surviving destroy() means a torn-down adapter
     * still calls onMoveEnd and the explorer refetches for a map that is gone.
     */
    private listeners: GMapsEventListener[] = [];

    /** The element this adapter was given, so it only cleans up its own. */
    private container: HTMLElement | null = null;

    private constructor(private readonly options: AdapterOptions) {
        this.loaded = new Promise((resolve) => {
            this.loadedResolve = resolve;
        });
    }

    static async create(options: AdapterOptions): Promise<GoogleMapsAdapter> {
        if (!options.googleKey) {
            throw new Error('google-maps-missing-key');
        }

        const adapter = new GoogleMapsAdapter(options);

        try {
            await adapter.initialise();
        } catch (error) {
            /*
             * A half-built adapter must leave nothing behind. Initialisation
             * can fail at four different points — script load, missing API,
             * authentication, tile timeout — and by the third of those the
             * gm_authFailure global has already been taken over and a Map
             * object may exist. Without this, a failed Google attempt left the
             * global owned by an object nobody holds a reference to, so every
             * later authentication failure was routed into a dead adapter and
             * the previous owner never heard about it again.
             */
            adapter.destroy();

            throw error;
        }

        return adapter;
    }

    private async initialise(): Promise<void> {
        await this.loadScript();

        const api = window.google;

        if (!api) {
            throw new Error('google-maps-unavailable');
        }

        /*
         * Authentication failures arrive AFTER construction, via a global —
         * an invalid key, a referrer restriction, disabled billing.
         *
         * Three things this has to get right, and the previous version got
         * none of them:
         *
         *   1. The previous handler is STORED so destroy() can put it back.
         *      Overwriting a global and never restoring it means the last
         *      adapter to exist owns it forever.
         *   2. A DESTROYED adapter ignores the callback. Without the guard, a
         *      torn-down instance still fires onError and drags the explorer
         *      into a fallback it has already completed.
         *   3. The handler is installed ONCE per instance and removed on
         *      destroy, so repeated initialisation cannot chain duplicates
         *      and trigger one fallback per past adapter.
         */
        this.previousAuthHandler = window.gm_authFailure;
        this.authHandler = () => {
            /*
             * The destroyed check comes FIRST, before the chained call.
             *
             * It used to run after, so a stale reference held past destroy()
             * still forwarded to the previous owner — and since destroy() has
             * already restored that owner as the live global, the owner was
             * notified twice for one failure. A stale handler must do NOTHING:
             * the live global is the only thing entitled to react.
             */
            if (this.destroyed) {
                return;
            }

            /*
             * The previous owner's handler is not ours and may throw. Its
             * failure must not stop this adapter falling back to MapLibre —
             * otherwise a third-party error handler bug leaves the visitor
             * staring at a dead grey canvas. try/finally rather than a bare
             * catch so the fallback runs on every path, and the exception is
             * swallowed rather than surfaced: it is not the visitor's problem
             * and there is nothing they could do about it.
             */
            try {
                this.previousAuthHandler?.();
            } catch {
                // Deliberately ignored. See above.
            } finally {
                this.fail('google-maps-auth-failed');
            }
        };
        window.gm_authFailure = this.authHandler;

        const map = new api.maps.Map(this.options.container, {
            center: { lat: this.options.centre.lat, lng: this.options.centre.lng },
            zoom: this.options.zoom,
            mapTypeControl: false,
            streetViewControl: false,
            fullscreenControl: false,
        });

        // Handles kept so destroy() can detach them. Each callback is also
        // guarded: a handle can only be removed once the adapter reaches
        // destroy(), and an event already queued in the browser's task queue
        // will still fire after that.
        this.listeners.push(
            map.addListener('idle', () => {
                if (this.destroyed) {
                    return;
                }

                this.options.events.onMoveEnd();
            }),
        );

        this.listeners.push(
            map.addListener('click', (payload) => {
                if (this.destroyed) {
                    return;
                }

                const latLng = payload?.latLng;

                if (latLng) {
                    this.options.events.onClick({ lat: latLng.lat(), lng: latLng.lng() });
                }
            }),
        );

        this.map = map;
        this.container = this.options.container;

        /*
         * Readiness is `tilesloaded`, not construction. A Map object exists
         * immediately even when the key is rejected and no tile will ever
         * arrive; resolving here was the defect — it reported a working Google
         * map to the explorer while the canvas stayed grey.
         *
         * The race is deliberate: whichever comes first wins. A timeout means
         * tiles never arrived, which is a failure regardless of the reason.
         */
        await new Promise<void>((resolve, reject) => {
            const timer = setTimeout(() => reject(new Error('google-maps-tiles-timeout')), TILE_TIMEOUT_MS);

            this.failed = (reason: string) => {
                clearTimeout(timer);
                reject(new Error(reason));
            };

            const tilesHandle = api.maps.event.addListenerOnce(map, 'tilesloaded', () => {
                clearTimeout(timer);

                /*
                 * A tilesloaded that arrives after destroy() must change
                 * nothing: not tilesLoaded, not the failure handler, and it
                 * must not resolve a readiness promise for an adapter the
                 * explorer has already discarded.
                 */
                if (this.destroyed) {
                    return;
                }

                // From here on a failure is POST-construction: the explorer is
                // already using this adapter, so it is reported through
                // onError rather than by rejecting a settled promise.
                this.failed = () => this.options.events.onError();
                this.tilesLoaded = true;
                resolve();
            });

            this.listeners.push(tilesHandle);
        });

        this.loadedResolve?.();
    }

    /**
     * Route a failure to whichever handler is currently correct.
     *
     * Guarded and one-shot. A destroyed adapter reports nothing, and a single
     * failure cannot be delivered twice — two deliveries would mean two
     * fallbacks, and the second would tear down the MapLibre map the first
     * one just built.
     */
    private fail(reason: string): void {
        if (this.destroyed || this.hasFailed) {
            return;
        }

        this.hasFailed = true;
        this.failed(reason);
    }

    private hasFailed = false;

    /**
     * Inject the Google script once, with a timeout.
     *
     * A revoked or referrer-restricted key does not fire `onerror` — the
     * script loads with HTTP 200 and the API then refuses to render. The
     * timeout catches the network case; `gm_authFailure` catches the
     * authentication case; `tilesloaded` catches everything else.
     */
    private loadScript(): Promise<void> {
        // Already loaded and initialised: nothing to wait for. This is the
        // check the previous version lacked — it attached a `load` listener to
        // an existing script element and waited forever for an event that had
        // already fired, so a second visit to the map hung indefinitely.
        if (window.google?.maps) {
            return Promise.resolve();
        }

        return new Promise((resolve, reject) => {
            const existing = document.getElementById(SCRIPT_ID) as HTMLScriptElement | null;

            /*
             * An element exists but the API is not initialised. Two cases, and
             * they are indistinguishable from the element alone:
             *
             *   - still in flight — attach and wait, with a timeout;
             *   - previously FAILED — it will never fire anything again, so
             *     waiting is a permanent hang. It is removed and re-requested.
             *
             * `data-failed` records the second case, because the DOM offers no
             * other way to tell them apart.
             */
            if (existing) {
                if (existing.dataset.failed === 'true') {
                    existing.remove();
                } else {
                    this.attach(existing, resolve, reject);

                    return;
                }
            }

            const script = document.createElement('script');
            script.id = SCRIPT_ID;
            script.async = true;
            script.defer = true;
            script.src = `https://maps.googleapis.com/maps/api/js?key=${encodeURIComponent(
                this.options.googleKey ?? '',
            )}&libraries=geometry`;

            this.attach(script, resolve, reject);

            document.head.appendChild(script);
        });
    }

    /**
     * Wait on a script element, with a timeout, cleaning up after settlement.
     *
     * Every path removes both listeners and clears the timer. Leaving them
     * attached is what makes repeated adapter creation leak handlers — and a
     * stale `load` handler resolving a promise belonging to a destroyed
     * adapter is how a torn-down instance comes back to life.
     */
    private attach(
        script: HTMLScriptElement,
        resolve: () => void,
        reject: (error: Error) => void,
    ): void {
        let settled = false;

        const cleanup = (): void => {
            clearTimeout(timer);
            script.removeEventListener('load', onLoad);
            script.removeEventListener('error', onError);
        };

        const onLoad = (): void => {
            if (settled) return;
            settled = true;
            cleanup();

            // The element fired `load`, but the API object is what matters.
            if (window.google?.maps) {
                resolve();
            } else {
                script.dataset.failed = 'true';
                reject(new Error('google-maps-api-missing-after-load'));
            }
        };

        const onError = (): void => {
            if (settled) return;
            settled = true;
            cleanup();
            script.dataset.failed = 'true';
            reject(new Error('google-maps-script-failed'));
        };

        const timer = setTimeout(() => {
            if (settled) return;
            settled = true;
            cleanup();
            // Not marked failed: a slow network may still deliver it, and
            // discarding a script that is merely late would re-request it on
            // every retry.
            reject(new Error('google-maps-timeout'));
        }, LOAD_TIMEOUT_MS);

        script.addEventListener('load', onLoad);
        script.addEventListener('error', onError);
    }

    ready(): Promise<void> {
        return this.loaded;
    }

    getBounds(): MapBounds | null {
        const bounds = this.map?.getBounds();

        if (!bounds) {
            return null;
        }

        const ne = bounds.getNorthEast();
        const sw = bounds.getSouthWest();

        return { north: ne.lat(), south: sw.lat(), east: ne.lng(), west: sw.lng() };
    }

    /** Whether a tile ever arrived. Construction success does not imply it. */
    hasRendered(): boolean {
        return this.tilesLoaded;
    }

    getZoom(): number | null {
        return this.map?.getZoom() ?? null;
    }

    /**
     * Render points, clustered, so the Google path matches MapLibre.
     *
     * MapLibre clusters natively; Google does not, and the official
     * MarkerClusterer is a separate CDN script that can fail to load on Erbil
     * mobile data. Shipping Google WITHOUT clustering would mean the provider
     * setting silently changed how much the map could show before it
     * stuttered — a performance cliff a visitor would read as "the site is
     * slow", not "the administrator chose Google".
     *
     * So a built-in grid clusterer, deliberately simple: bucket by a lat/lng cell
     * sized to roughly 50 screen pixels at the current zoom, which is the same
     * clusterRadius MapLibre uses. It is not a k-means and does not need to
     * be — at the server's 300-feature ceiling the visual result is
     * indistinguishable, and it adds no runtime dependency that could fail to
     * load on Erbil mobile data.
     */
    setPoints(points: PointFeature[]): void {
        const api = window.google;

        if (!api || !this.map) {
            return;
        }

        this.markers.forEach((marker) => marker.setMap(null));

        const zoom = this.map.getZoom() ?? this.options.zoom;
        const clusters = this.cluster(points, zoom);

        this.markers = clusters.map((cluster) =>
            new api.maps.Marker({
                position: { lat: cluster.lat, lng: cluster.lng },
                map: this.map,
                title: cluster.count > 1 ? `${cluster.count}` : cluster.title,
                label: cluster.count > 1 ? { text: String(cluster.count), color: '#ffffff' } : undefined,
            }),
        );
    }

    /**
     * Grid-bucket points into clusters at a given zoom.
     *
     * Cell size halves per zoom level, matching how tile resolution doubles.
     * At zoom 20 the cell is sub-metre, so nothing clusters — which is the
     * correct behaviour when a visitor has zoomed to a single building.
     */
    private cluster(
        points: PointFeature[],
        zoom: number,
    ): Array<{ lat: number; lng: number; count: number; title: string }> {
        // ~50 px at zoom 11, halving each level up.
        const cell = 0.02 / Math.pow(2, Math.max(0, zoom - 11));
        const buckets = new Map<string, { lat: number; lng: number; count: number; title: string }>();

        for (const point of points) {
            const key = `${Math.floor(point.lat / cell)}:${Math.floor(point.lng / cell)}`;
            const existing = buckets.get(key);

            if (existing) {
                // Running mean, so the cluster marker sits at the centroid of
                // its members rather than on whichever point arrived first.
                existing.lat = (existing.lat * existing.count + point.lat) / (existing.count + 1);
                existing.lng = (existing.lng * existing.count + point.lng) / (existing.count + 1);
                existing.count += 1;
            } else {
                buckets.set(key, { lat: point.lat, lng: point.lng, count: 1, title: point.title });
            }
        }

        return [...buckets.values()];
    }

    setBoundaries(collection: BoundaryCollection): void {
        const api = window.google;

        if (!api || !this.map) {
            return;
        }

        this.clearPolygons();

        const created: GPolygon[] = [];

        for (const feature of collection.features) {
            /*
             * ONE google.maps.Polygon PER COMPONENT, each carrying its own
             * exterior and its own holes, with winding normalised so Google's
             * even-odd renderer punches the right voids.
             *
             * The previous version flattened every ring of every component
             * into a single Polygon. Google then inferred holes from winding
             * across the whole undifferentiated set, so a hole belonging to
             * one component could be cut out of another and two unrelated
             * districts could share a fill. It rendered cleanly, which is what
             * made it hard to see.
             */
            for (const paths of toGooglePolygons(feature.geometry)) {
                created.push(
                    new api.maps.Polygon({
                        paths,
                        map: this.map,
                        strokeColor: '#1f6feb',
                        strokeOpacity: 0.6,
                        strokeWeight: 1.5,
                        fillColor: '#1f6feb',
                        fillOpacity: 0.08,
                    }),
                );
            }
        }

        this.polygons = created;
    }

    /**
     * Detach and drop every polygon.
     *
     * `setMap(null)` is what actually removes a Google overlay; dropping the
     * array alone leaves the shapes on the map forever and leaks one set per
     * viewport change. The array is emptied in the same step so a later
     * clear cannot double-detach a stale reference.
     */
    private clearPolygons(): void {
        this.polygons.forEach((polygon) => polygon.setMap(null));
        this.polygons = [];
    }

    flyTo(point: LatLng, zoom?: number): void {
        this.map?.panTo({ lat: point.lat, lng: point.lng });

        if (zoom !== undefined) {
            this.map?.setZoom(zoom);
        }
    }

    destroy(): void {
        // Idempotent: create() calls this on a failed attempt, and the
        // explorer calls it again on unmount. A second pass must not restore
        // a handler it has already restored.
        if (this.destroyed) {
            return;
        }

        this.destroyed = true;

        /*
         * Neutralise the internal callbacks before anything else. `failed` may
         * still hold the constructor's reject, and `loadedResolve` the
         * readiness resolve; leaving either live means a late tile event or
         * timeout can settle a promise belonging to an adapter that no longer
         * exists.
         */
        this.failed = () => {};
        this.loadedResolve = null;

        /*
         * Restore the global only if this instance still owns it. If a newer
         * adapter has already replaced it, overwriting would silently disable
         * the live adapter's authentication detection — the exact class of
         * bug that made this lifecycle worth fixing.
         */
        if (this.authHandler !== null && window.gm_authFailure === this.authHandler) {
            window.gm_authFailure = this.previousAuthHandler;
        }

        this.authHandler = null;
        this.previousAuthHandler = undefined;

        // Detach every listener. Google holds these on the Map instance, so
        // dropping our reference alone leaves them live.
        this.listeners.forEach((listener) => {
            try {
                listener.remove();
            } catch {
                // A handle from a partially-built map may already be dead.
            }
        });
        this.listeners = [];

        this.markers.forEach((marker) => marker.setMap(null));
        this.markers = [];
        this.clearPolygons();
        this.map = null;

        this.clearContainer();
    }

    /**
     * Remove the DOM Google injected into the container.
     *
     * Google builds its entire map inside the element it is given and does not
     * clean up — there is no `map.destroy()`. Handing that element to MapLibre
     * afterwards stacks a second canvas on top of a dead Google map: both sets
     * of tiles paint, the visitor sees the failed provider through the working
     * one, and every fallback leaves another layer behind.
     *
     * Guarded on ownership. If a newer adapter has already been given this
     * element, emptying it would destroy a live map — so a teardown that
     * arrives late does nothing rather than something harmful.
     */
    private clearContainer(): void {
        const container = this.container;

        this.container = null;

        if (container === null) {
            return;
        }

        // innerHTML rather than removeChild in a loop: Google's tree is deep
        // and this drops it in one operation with no partial state.
        container.innerHTML = '';
    }
}
