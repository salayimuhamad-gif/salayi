import type {
    AdapterOptions,
    BoundaryCollection,
    LatLng,
    MapAdapter,
    MapBounds,
    PointFeature,
    PoiFeature,
} from './types';
import { trendColour, trendIconName } from './trend';

/*
 * THE WORKER, resolved by the bundler instead of guessed at runtime.
 *
 * maplibre-gl v6 computes its worker location as
 * `new URL('./maplibre-gl-worker.mjs', import.meta.url)` — relative to
 * whatever chunk the bundler emitted. Vite cannot see through that dynamic
 * expression, so the worker file was never copied into the build and every
 * deployment requested `/build/assets/maplibre-gl-worker.mjs` and got a 404.
 * The map still fired `load` — which is exactly why this survived: pages
 * looked "ready" while every GeoJSON/vector source, which is processed IN
 * the worker, stayed empty forever. No workers, no markers, no tiles.
 *
 * `?worker&url` — not plain `?url` — because the dist worker is itself one
 * half of a split build: it statically imports `./maplibre-gl-shared.mjs`,
 * so a verbatim copy still dies on its own 404 one request later. The
 * worker query makes Vite BUNDLE the file self-contained and emit it as a
 * real hashed asset; setWorkerUrl() points MapLibre at it before any map
 * is constructed.
 */
import maplibreWorkerUrl from 'maplibre-gl/dist/maplibre-gl-worker.mjs?worker&url';

/*
 * RTL TEXT SHAPING, bundled with the build instead of fetched from a CDN.
 *
 * MapLibre core ships no Arabic/Hebrew bidi or shaping engine: without the
 * official RTL text plugin, every Arabic-script label — the basemap's own
 * names (أربيل) and the Kurdish Sorani project names this adapter draws
 * (هەولێر) — renders with isolated letter forms in left-to-right order.
 * The plugin is a self-contained script MapLibre loads by URL; plain `?url`
 * (no worker bundling — MapLibre performs the load itself) makes Vite emit
 * the package's dist file as a real hashed asset served from this origin,
 * so production Arabic rendering never depends on unpkg/jsdelivr or any
 * other third-party CDN being up. The deep dist path resolves through the
 * filesystem alias in vite.config.ts: the package's exports map exposes
 * only its root, and the root is a raw ESM wrapper importing ./icu.wasm —
 * MapLibre needs the SELF-CONTAINED worker script, which is exactly what
 * dist/mapbox-gl-rtl-text.js is.
 */
import rtlTextPluginUrl from '@mapbox/mapbox-gl-rtl-text/dist/mapbox-gl-rtl-text.js?url';

/**
 * MapLibre adapter — the default, and the only one that needs no key (§10.1).
 *
 * A fresh Hostinger upload with nothing configured must still produce a
 * working map, which is why this is the fallback target for every failure
 * path rather than a blank rectangle.
 *
 * Points are a CLUSTERED GeoJSON source rather than DOM markers: three hundred
 * absolutely-positioned divs is what makes a mid-range Android drop frames
 * while panning. Boundaries are a second, separate source — polygons and
 * labels have different lifetimes and different zoom gates.
 */
export class MapLibreAdapter implements MapAdapter {
    readonly provider = 'maplibre' as const;

    private map: import('maplibre-gl').Map | null = null;
    private loaded = false;
    private failedBeforeLoad = false;
    private destroyed = false;
    /** Category token for diagnostics: style / webgl / container / timeout. */
    private failureCategory: string | null = null;
    private observer: ResizeObserver | null = null;
    private pin: import('maplibre-gl').Marker | null = null;
    /*
     * The pending readiness operation is ADAPTER state, not a local of
     * ready(): destroy() must be able to cancel the deadline timer and settle
     * the promise immediately. When the timer lived inside ready()'s closure,
     * destroying a still-constructing map left it ticking, and the rejection
     * arrived up to twenty seconds after the component was gone.
     */
    private readyPromise: Promise<void> | null = null;
    private readyDeadline: number | null = null;
    private settleReady: { resolve: () => void; reject: (error: Error) => void } | null = null;
    private pending: {
        points: PointFeature[];
        boundaries: BoundaryCollection | null;
        pois: PoiFeature[];
    } = {
        points: [],
        boundaries: null,
        pois: [],
    };

    /*
     * Area-selection state (Map Phase 3). The slug is buffered exactly like
     * pending data so a selection set before `load` (a restored page state)
     * paints the moment the layers exist; interactivity is OFF until the
     * page opts in, so every other surface keeps its exact click behavior.
     */
    private selectedBoundarySlug: string | null = null;

    private boundaryInteractive = false;

    private hoveredBoundarySlug: string | null = null;

    private constructor(private readonly options: AdapterOptions) {}

    static async create(options: AdapterOptions): Promise<MapLibreAdapter> {
        const adapter = new MapLibreAdapter(options);
        await adapter.initialise();
        return adapter;
    }

    private async initialise(): Promise<void> {
        const maplibre = await import('maplibre-gl');

        // Idempotent; must precede the first Map construction, because the
        // worker pool is created eagerly in the constructor.
        maplibre.setWorkerUrl(maplibreWorkerUrl);

        /*
         * RTL plugin registration — also before the first Map, and exactly
         * once per page lifetime. The status is module state inside
         * maplibre-gl, so it outlives this adapter instance: repeated
         * Inertia navigations and simultaneous maps re-enter here with the
         * status already 'deferred', 'loading' or 'loaded' and must not
         * (and do not) register again — only the pristine 'unavailable'
         * state installs the plugin. lazy=true defers the actual download
         * until the first Arabic-script glyph run reaches symbol layout, so
         * a session that never meets RTL text never pays for it. A plugin
         * failure degrades shaping only; it must never take the map down.
         */
        if (maplibre.getRTLTextPluginStatus() === 'unavailable') {
            try {
                await maplibre.setRTLTextPlugin(rtlTextPluginUrl, true);
            } catch {
                // Shaping degrades; the map itself stays up.
            }
        }

        /*
         * A zero-sized container is a distinct failure class (a map built
         * inside a hidden v-show tab): the map constructs fine and draws
         * nothing. It is legal here — the ResizeObserver below heals it the
         * moment the box gains real dimensions — but in development it is
         * named out loud, because it is otherwise the least debuggable of
         * the blank-map causes.
         */
        if (
            import.meta.env.DEV
            && (this.options.container.clientWidth === 0 || this.options.container.clientHeight === 0)
        ) {
            console.warn('[map] container has zero size at construction; expecting a resize when it becomes visible');
        }

        /*
         * Style resolution: the configured URL wins; with none set, the
         * 'plain' fallback is a zero-network inline background (the admin
         * picker's contract — usable with nothing configured), and the
         * default public fallback is the MULK dark basemap style served
         * from THIS origin (Map Phase 1): CARTO Dark Matter raster tiles
         * over a near-black ground. Same-origin replaced the historical
         * demotiles.maplibre.org demo default, so a no-config deployment —
         * and CI's hermetic browser matrix — no longer depends on
         * third-party demo infrastructure for the style document. The
         * style deliberately names NO glyphs endpoint: maplibre-gl v6 then
         * rasterizes label glyphs locally (TinySDF) from device fonts, and
         * with the RTL plugin above that is what makes the adapter-drawn
         * Sorani and Arabic names render with correct joined shaping
         * instead of the tofu a Latin-only glyph server produces.
         */
        const style: string | import('maplibre-gl').StyleSpecification = this.options.styleUrl
            && this.options.styleUrl.trim() !== ''
            ? this.options.styleUrl
            : this.options.fallbackStyle === 'plain'
                ? {
                    version: 8 as const,
                    sources: {},
                    layers: [{
                        id: 'background',
                        type: 'background' as const,
                        paint: { 'background-color': '#e8e6e1' },
                    }],
                }
                : '/map-styles/mulk-dark.json';

        const map = new maplibre.Map({
            container: this.options.container,
            style,
            center: [this.options.centre.lng, this.options.centre.lat],
            zoom: this.options.zoom,
            // Optional camera fences (the investment map pins itself to
            // Erbil). Soft bounds, so a fling at the edge eases back instead
            // of hitting a wall.
            ...(this.options.minZoom !== undefined ? { minZoom: this.options.minZoom } : {}),
            ...(this.options.maxZoom !== undefined ? { maxZoom: this.options.maxZoom } : {}),
            ...(this.options.maxBounds
                ? {
                    maxBounds: [
                        [this.options.maxBounds.west, this.options.maxBounds.south],
                        [this.options.maxBounds.east, this.options.maxBounds.north],
                    ] as [[number, number], [number, number]],
                }
                : {}),
            // Added manually below: the constructor option cannot choose a
            // corner, and the corner is a direction decision now.
            attributionControl: false,
        });

        /*
         * Control corners are a DELIBERATE direction decision, not a CSS
         * side effect. MapLibre corners are physical by design, and the
         * vendor stylesheet is excluded from the page's rtlcss pipeline
         * (postcss.config.js) — before that exclusion, rtlcss mirrored the
         * corner containers under [dir="rtl"] and the RTL layout only
         * happened to look right, while `.maplibregl-marker { right: 0 }`
         * displaced every DOM marker on RTL pages. The adapter now reads
         * the container's computed direction (the container inherits the
         * document's dir) and picks the physical corner itself: zoom at
         * the top-END corner, scale at the bottom-START corner,
         * attribution at the bottom-END corner — exactly the positions
         * every locale has always seen. Pinned by map-rtl.spec.ts for
         * ckb, ar and en.
         */
        const direction =
            typeof getComputedStyle === 'function'
                && getComputedStyle(this.options.container).direction === 'rtl'
                ? 'rtl'
                : 'ltr';
        const topEnd = direction === 'rtl' ? 'top-left' : 'top-right';
        const bottomStart = direction === 'rtl' ? 'bottom-right' : 'bottom-left';
        const bottomEnd = direction === 'rtl' ? 'bottom-left' : 'bottom-right';

        map.addControl(new maplibre.NavigationControl({ showCompass: false }), topEnd);
        map.addControl(new maplibre.ScaleControl({ unit: 'metric' }), bottomStart);
        map.addControl(new maplibre.AttributionControl({ compact: true }), bottomEnd);

        map.on('error', (event) => {
            /*
             * An error before 'load' means the style itself failed — the map
             * will never become ready and ready() must say so (see below).
             * Errors AFTER load (a missing sprite image, one dropped tile)
             * are the recoverable kind: the map keeps working, so they no
             * longer flip the page into its failed state — that used to
             * mark a perfectly usable map as broken. They are still named
             * in development so tile-vs-style-vs-layout failures can be
             * told apart in a browser console.
             */
            if (!this.loaded) {
                this.failedBeforeLoad = true;
                this.failureCategory = this.failureCategory ?? 'style';
                this.options.events.onError();
                return;
            }

            if (import.meta.env.DEV) {
                console.warn('[map] recoverable resource error after load', event?.error?.message ?? event);
            }
        });
        map.on('moveend', () => this.options.events.onMoveEnd());
        map.on('click', (event) => {
            /*
             * A click that hit a marker belongs to the marker. Without this
             * guard the surface handler (which pages use to CLEAR selection)
             * fired on the same click that selected, and markers could never
             * be selected from the map at all.
             *
             * "Belongs to" requires a real action on the other side. Cluster
             * clicks always expand, so cluster hits are always claimed; point
             * hits are claimed only when the surface wired onMarkerClick.
             * The explorer wires none — selection lives in its list — and
             * under the unconditional guard its centre-pick and draw clicks
             * died silently whenever they landed on a project point.
             */
            const claimed = this.options.events.onMarkerClick
                ? ['unclustered', 'trend-markers', 'point-labels', 'point-names', 'clusters']
                : ['clusters'];
            const hit = map
                .queryRenderedFeatures(event.point, {
                    layers: this.presentLayers(map, claimed),
                })
                .length > 0;

            if (hit) {
                return;
            }

            /*
             * Area polygon selection (Map Phase 3) sits BELOW every record
             * interaction in the priority order: project markers first,
             * intentional POI interaction second (its layers are already in
             * the reserved set for the day it exists), polygon third, empty
             * map last. A click that touches any record pixel therefore
             * NEVER selects the polygon under it — it falls through to
             * onClick exactly as before, so centre-picking over a marker
             * keeps working and a marker tap never reads as an area tap.
             */
            if (this.boundaryInteractive && this.options.events.onBoundarySelect) {
                const reserved = [
                    'unclustered', 'trend-markers', 'point-labels', 'point-names',
                    'clusters', 'poi-dots', 'poi-labels',
                ];
                const recordHit = map
                    .queryRenderedFeatures(event.point, {
                        layers: this.presentLayers(map, reserved),
                    })
                    .length > 0;

                if (!recordHit) {
                    const boundary = map.queryRenderedFeatures(event.point, {
                        layers: this.presentLayers(map, ['boundary-fill']),
                    })[0];
                    const props = boundary?.properties as
                        | { slug?: unknown; name?: unknown; type?: unknown }
                        | undefined;

                    // Identity travels through the feature's own public
                    // properties — never layer internals.
                    if (props && typeof props.slug === 'string' && props.slug !== '') {
                        this.options.events.onBoundarySelect({
                            slug: props.slug,
                            name: typeof props.name === 'string' ? props.name : '',
                            type: typeof props.type === 'string' ? props.type : '',
                        });

                        return;
                    }
                }
            }

            this.options.events.onClick({ lat: event.lngLat.lat, lng: event.lngLat.lng });
        });

        /*
         * Self-healing size: a map created while its parent was hidden
         * (display:none measures 0×0) recovers the moment the box becomes
         * visible, and layout shifts after Inertia navigation are absorbed
         * without every page having to know. Guarded for environments
         * without ResizeObserver (jsdom).
         */
        if (typeof ResizeObserver !== 'undefined') {
            this.observer = new ResizeObserver(() => {
                this.map?.resize();
            });
            this.observer.observe(this.options.container);
        }

        map.on('load', () => {
            map.addSource('features', {
                type: 'geojson',
                data: this.pointCollection(this.pending.points),
                cluster: true,
                clusterRadius: 50,
                clusterMaxZoom: 15,
            });

            // Boundaries are their own source. Merging them into `features`
            // would put polygons through the clusterer, which silently drops
            // non-point geometry. promoteId lifts each feature's stable slug
            // into its feature id so the hover feature-state (Map Phase 3)
            // needs no generated ids and survives data refreshes.
            map.addSource('boundaries', {
                type: 'geojson',
                promoteId: 'slug',
                data: this.pending.boundaries ?? { type: 'FeatureCollection', features: [] },
            });

            // One accent drives polygons and clusters, so a page can restyle
            // the map (the investment surface uses the brand gold) without a
            // second adapter. The default is the explorer's existing blue.
            const accent = this.options.accentColour ?? '#1f6feb';

            /*
             * Adapter-drawn text paint, per basemap scheme (Map Phase 1).
             * 'light' is the byte-identical historical pairing; 'dark' puts
             * near-white ink on a night halo so MULK's own labels sit
             * quietly on the CARTO dark basemap instead of shouting from a
             * white outline. Restrained by design: same 11px size, same
             * weight, halo does the readability work.
             */
            const scheme = this.options.labelScheme ?? 'light';
            const labelPaint = scheme === 'dark'
                ? { price: '#eef2ff', name: '#d5ddf3', halo: 'rgba(7, 11, 22, 0.85)' }
                : { price: '#17181a', name: '#0f3e59', halo: '#ffffff' };

            map.addLayer({
                id: 'boundary-fill',
                type: 'fill',
                source: 'boundaries',
                paint: { 'fill-color': accent, 'fill-opacity': 0.08 },
            });

            map.addLayer({
                id: 'boundary-line',
                type: 'line',
                source: 'boundaries',
                paint: {
                    'line-color': accent,
                    'line-width': 1.5,
                    // Hover is a whisper, not a shout: the outline firms up
                    // under the pointer (Map Phase 3) and nothing else moves.
                    // With no feature-state set this is exactly the historic
                    // 0.6, so non-interactive surfaces render unchanged.
                    'line-opacity': [
                        'case',
                        ['boolean', ['feature-state', 'hover'], false], 0.9,
                        0.6,
                    ],
                },
            });

            /*
             * The selected area's premium highlight (Map Phase 3): restrained
             * warm amber outline over a barely-there interior lift, filtered
             * to one slug. Added HERE — beneath every cluster, marker, POI
             * and label layer that follows — so the selection never obscures
             * roads, projects or places. ' ' matches no real slug, so
             * with nothing selected both layers draw nothing.
             */
            map.addLayer({
                id: 'boundary-selected-fill',
                type: 'fill',
                source: 'boundaries',
                filter: ['==', ['get', 'slug'], this.selectedBoundarySlug ?? ' '],
                paint: { 'fill-color': '#f3c56f', 'fill-opacity': 0.1 },
            });

            map.addLayer({
                id: 'boundary-selected-line',
                type: 'line',
                source: 'boundaries',
                filter: ['==', ['get', 'slug'], this.selectedBoundarySlug ?? ' '],
                paint: {
                    'line-color': '#f3c56f',
                    'line-width': 2.5,
                    'line-opacity': 0.95,
                },
            });

            map.addLayer({
                id: 'clusters',
                type: 'circle',
                source: 'features',
                filter: ['has', 'point_count'],
                paint: {
                    'circle-color': accent,
                    'circle-opacity': 0.85,
                    'circle-radius': ['step', ['get', 'point_count'], 16, 25, 22, 100, 28],
                },
            });

            map.addLayer({
                id: 'cluster-count',
                type: 'symbol',
                source: 'features',
                filter: ['has', 'point_count'],
                layout: { 'text-field': '{point_count_abbreviated}', 'text-size': 12 },
                paint: { 'text-color': '#ffffff' },
            });

            map.addLayer({
                id: 'unclustered',
                type: 'circle',
                source: 'features',
                filter: ['all', ['!', ['has', 'point_count']], ['!', ['has', 'trendIcon']]],
                paint: {
                    'circle-color': ['get', 'colour'],
                    'circle-radius': 7,
                    'circle-stroke-width': 2,
                    'circle-stroke-color': '#ffffff',
                },
            });

            /*
             * Trend-shaped markers: colour AND direction, never colour
             * alone. The icons are drawn onto a canvas at registration time
             * — no sprite, no glyphs, no font stack — so they render under
             * ANY style, including the deterministic background-only style
             * the browser tests serve. Points without a trend keep the
             * plain circle above; `unknown` gets the neutral pin, because a
             * trend that is not known must not look like one that is.
             */
            for (const [name, icon] of Object.entries(trendMarkerImages())) {
                if (!map.hasImage(name)) {
                    map.addImage(name, icon, { pixelRatio: 2 });
                }
            }

            map.addLayer({
                id: 'trend-markers',
                type: 'symbol',
                source: 'features',
                filter: ['all', ['!', ['has', 'point_count']], ['has', 'trendIcon']],
                layout: {
                    'icon-image': ['get', 'trendIcon'],
                    'icon-size': 1,
                    'icon-allow-overlap': true,
                },
            });

            /*
             * Price labels (redesign §5.4): pre-formatted text the CALLER
             * supplies — the adapter renders, never derives. Rendered via
             * text-field, which maplibre v6 draws with local TinySDF glyphs
             * when the style names no glyph server, so the labels work under
             * every style including the deterministic test style. Points
             * without a `label` property are untouched by both layers.
             */
            map.addLayer({
                id: 'point-labels',
                type: 'symbol',
                source: 'features',
                filter: ['all', ['!', ['has', 'point_count']], ['has', 'label']],
                layout: {
                    'text-field': ['get', 'label'],
                    'text-size': 11,
                    'text-anchor': 'top',
                    'text-offset': [0, 1.1],
                    'text-allow-overlap': false,
                    'text-optional': true,
                },
                paint: {
                    'text-color': labelPaint.price,
                    'text-halo-color': labelPaint.halo,
                    'text-halo-width': 1.4,
                },
            });

            /*
             * Project names join at street zoom (>= 13), decluttered below
             * it. Gated on `id` + `title`, NOT on the price `label`: the name
             * is a click target that resolves to the id it displays, so it
             * belongs to every SELECTABLE point — with or without a recorded
             * price — and to no point a click could not select. Keying it on
             * `label` left the invest map nameless (its points carry no price
             * label) while the explorer's deliberately non-interactive,
             * id-less points stay text-free either way.
             */
            map.addLayer({
                id: 'point-names',
                type: 'symbol',
                source: 'features',
                minzoom: 13,
                filter: ['all', ['!', ['has', 'point_count']], ['has', 'id'], ['has', 'title']],
                layout: {
                    'text-field': ['get', 'title'],
                    'text-size': 11,
                    'text-anchor': 'bottom',
                    'text-offset': [0, -1.2],
                    'text-allow-overlap': false,
                    'text-optional': true,
                },
                paint: {
                    'text-color': labelPaint.name,
                    'text-halo-color': labelPaint.halo,
                    'text-halo-width': 1.4,
                },
            });

            /*
             * Controlled POI overlay (Map Phase 1 prepares, Phase 2 fills).
             * A third, separate, UNCLUSTERED source: POIs are ambient
             * neighbourhood context, deliberately subordinate to project
             * markers - smaller dots, muted ink, amber only for the one a
             * user focussed, and zoom-gated so no zoomed-out view ever
             * drowns in generic pins. Both layers are inserted BENEATH
             * 'clusters' (the lowest project-marker layer): project dots
             * draw over POI dots, and the project symbol layers, being
             * above, keep collision priority - a POI name never displaces
             * a project's price or name. No surface feeds this yet;
             * setPois() below is the hook the cached MULK POI endpoints
             * will drive.
             */
            map.addSource('pois', {
                type: 'geojson',
                data: this.poiCollection(this.pending.pois),
            });

            map.addLayer({
                id: 'poi-dots',
                type: 'circle',
                source: 'pois',
                minzoom: 13,
                paint: {
                    'circle-color': ['case', ['==', ['get', 'active'], true], '#f3c56f', '#8b95ad'],
                    'circle-radius': ['case', ['==', ['get', 'active'], true], 5, 3.5],
                    'circle-opacity': 0.85,
                    'circle-stroke-width': 1,
                    'circle-stroke-color': scheme === 'dark' ? '#070b16' : '#ffffff',
                },
            }, 'clusters');

            map.addLayer({
                id: 'poi-labels',
                type: 'symbol',
                source: 'pois',
                minzoom: 15,
                layout: {
                    'text-field': ['get', 'title'],
                    'text-size': 10,
                    'text-anchor': 'top',
                    'text-offset': [0, 0.7],
                    'text-allow-overlap': false,
                    'text-optional': true,
                },
                paint: {
                    'text-color': scheme === 'dark' ? '#aab4cc' : '#5c5e61',
                    'text-halo-color': labelPaint.halo,
                    'text-halo-width': 1.2,
                },
            }, 'clusters');

            const emitMarkerClick = (event: import('maplibre-gl').MapLayerMouseEvent): void => {
                const id = event.features?.[0]?.properties?.id as number | undefined;

                if (id !== undefined && this.options.events.onMarkerClick) {
                    this.options.events.onMarkerClick(Number(id));
                }
            };

            /*
             * Every visual piece of a project point is the SAME target:
             * dot, trend icon, price label and street-zoom name all resolve
             * to the project's id. `point-names` was missing from all three
             * registrations below (click, hit guard above, cursor), so at
             * zoom >= 13 a tap on a project's name fell through to the
             * surface handler and CLEARED the selection it looked like it
             * was making.
             */
            map.on('click', 'unclustered', emitMarkerClick);
            map.on('click', 'trend-markers', emitMarkerClick);
            map.on('click', 'point-labels', emitMarkerClick);
            map.on('click', 'point-names', emitMarkerClick);

            /*
             * The pointer cursor is a promise that clicking does something.
             * Point layers keep that promise only where the surface wired
             * onMarkerClick; the explorer wires none, and its markers used
             * to advertise a click that did nothing at all. Clusters stay
             * pointer everywhere — expansion is always a real action.
             */
            if (this.options.events.onMarkerClick) {
                for (const layer of ['unclustered', 'trend-markers', 'point-labels', 'point-names']) {
                    map.on('mouseenter', layer, () => {
                        map.getCanvas().style.cursor = 'pointer';
                    });
                    map.on('mouseleave', layer, () => {
                        map.getCanvas().style.cursor = '';
                    });
                }
            }

            /*
             * Clusters expand on click. Without this, a tap on "12 projects"
             * did nothing and the only way in was manual pinching — the one
             * map affordance every visitor already expects. The expansion
             * zoom comes from the clusterer itself, so one step always
             * separates at least two children.
             */
            map.on('click', 'clusters', (event) => {
                const feature = map.queryRenderedFeatures(event.point, { layers: ['clusters'] })[0];
                const clusterId = feature?.properties?.cluster_id as number | undefined;
                const source = map.getSource('features') as import('maplibre-gl').GeoJSONSource;

                if (clusterId === undefined || !source.getClusterExpansionZoom) {
                    return;
                }

                void source.getClusterExpansionZoom(clusterId).then((zoom) => {
                    const geometry = feature.geometry;
                    if (geometry.type === 'Point') {
                        map.easeTo({
                            center: geometry.coordinates as [number, number],
                            zoom,
                        });
                    }
                });
            });

            map.on('mouseenter', 'clusters', () => {
                map.getCanvas().style.cursor = 'pointer';
            });
            map.on('mouseleave', 'clusters', () => {
                map.getCanvas().style.cursor = '';
            });

            /*
             * Boundary hover (Map Phase 3): registered only for the surface
             * that wired onBoundarySelect, so every other map pays nothing.
             * The hovered polygon's outline firms up through feature-state
             * (the paint expression above) and the cursor promises the click
             * — but only while interaction is enabled and no record marker
             * or POI sits under the pointer, mirroring the click priority
             * exactly. Touch devices never depend on this: the first
             * intentional tap selects through the click path.
             */
            if (this.options.events.onBoundarySelect) {
                map.on('mousemove', (event) => {
                    // Clusters stay clickable in every mode, so their cursor
                    // promise is computed here too — this handler owns the
                    // cursor on the one surface that registers it, and the
                    // values agree with the generic cluster handlers above.
                    const clusterUnder = map
                        .queryRenderedFeatures(event.point, {
                            layers: this.presentLayers(map, ['clusters']),
                        })
                        .length > 0;

                    let hoverSlug: string | null = null;

                    if (this.boundaryInteractive && !clusterUnder) {
                        const recordUnder = map
                            .queryRenderedFeatures(event.point, {
                                layers: this.presentLayers(map, [
                                    'unclustered', 'trend-markers', 'point-labels',
                                    'point-names', 'poi-dots', 'poi-labels',
                                ]),
                            })
                            .length > 0;

                        if (!recordUnder) {
                            const boundary = map.queryRenderedFeatures(event.point, {
                                layers: this.presentLayers(map, ['boundary-fill']),
                            })[0];

                            hoverSlug = typeof boundary?.properties?.slug === 'string'
                                ? boundary.properties.slug
                                : null;
                        }
                    }

                    this.setBoundaryHover(map, hoverSlug);
                    map.getCanvas().style.cursor = clusterUnder || hoverSlug !== null ? 'pointer' : '';
                });

                map.on('mouseout', () => {
                    this.setBoundaryHover(map, null);
                    map.getCanvas().style.cursor = '';
                });
            }

            this.loaded = true;
        });

        this.map = map;
    }

    /**
     * Resolves when the map can draw; REJECTS when it never will.
     *
     * The old version waited for 'load' alone. With an unreachable style URL
     * — precisely the network condition this product plans for — MapLibre
     * emits 'error' and 'load' never fires, so ready() hung forever and every
     * caller sequenced behind it hung with it. The pages await ready() before
     * their first data fetch, which turned a missing TILE SERVER into a
     * permanently empty LIST — the exact coupling the always-rendered list
     * exists to prevent. A rejection lands in the pages' existing catch,
     * which states the provider failure and loads the data anyway.
     */
    ready(): Promise<void> {
        if (this.loaded) {
            return Promise.resolve();
        }

        if (this.destroyed) {
            return Promise.reject(new Error('maplibre-destroyed'));
        }

        if (this.failedBeforeLoad) {
            return Promise.reject(new Error('maplibre-style-failed'));
        }

        // One readiness operation per adapter: repeat callers share the same
        // pending promise instead of stacking timers and listener sets.
        if (this.readyPromise !== null) {
            return this.readyPromise;
        }

        /*
         * Bounded, four ways out: load resolves, a pre-load error rejects,
         * a DEADLINE rejects when the provider simply stalls — a style
         * whose tile or glyph requests hang without ever erroring used to
         * leave the page on its spinner indefinitely — and destroy()
         * settles immediately. Every exit funnels through settle(), which
         * clears the timer and fires exactly once.
         */
        this.readyPromise = new Promise((resolve, reject) => {
            this.settleReady = { resolve, reject };

            this.readyDeadline = window.setTimeout(() => {
                this.failureCategory = this.failureCategory ?? 'timeout';

                if (import.meta.env.DEV) {
                    console.warn('[map] ready() deadline elapsed; category:', this.failureCategory);
                }

                this.settle(new Error('maplibre-ready-timeout'));
            }, this.options.readyTimeoutMs ?? 20000);

            this.map?.once('load', () => {
                this.settle(null);
            });
            this.map?.once('error', () => {
                if (!this.loaded) {
                    this.settle(new Error('maplibre-style-failed'));
                }
            });
        });

        return this.readyPromise;
    }

    /**
     * Settle the pending ready() exactly once and stop its deadline.
     *
     * Load, pre-load error, timeout and destroy() all land here; whichever
     * arrives first wins and the rest become no-ops, so no sequence can
     * reject twice, resolve after rejecting, or leave the timer alive.
     */
    private settle(error: Error | null): void {
        if (this.readyDeadline !== null) {
            window.clearTimeout(this.readyDeadline);
            this.readyDeadline = null;
        }

        const pending = this.settleReady;
        this.settleReady = null;

        if (pending === null) {
            return;
        }

        if (error === null) {
            pending.resolve();
        } else {
            pending.reject(error);
        }
    }

    getBounds(): MapBounds | null {
        const bounds = this.map?.getBounds();

        if (!bounds) {
            return null;
        }

        return {
            north: bounds.getNorth(),
            south: bounds.getSouth(),
            east: bounds.getEast(),
            west: bounds.getWest(),
        };
    }

    getZoom(): number | null {
        return this.map?.getZoom() ?? null;
    }

    setPoints(points: PointFeature[]): void {
        this.pending.points = points;

        if (!this.loaded) {
            return;
        }

        const source = this.map?.getSource('features') as
            | import('maplibre-gl').GeoJSONSource
            | undefined;

        // MapLibre's setData accepts a GeoJSON object; its published type is
        // the @types/geojson FeatureCollection, which is not a dependency
        // here. The parameter type is taken from the method itself rather than
        // silenced with a cast, so a MapLibre upgrade that changes the shape
        // becomes a compile error instead of a runtime surprise.
        source?.setData(this.pointCollection(points) as Parameters<typeof source.setData>[0]);
    }

    setBoundaries(collection: BoundaryCollection): void {
        this.pending.boundaries = collection;

        if (!this.loaded) {
            return;
        }

        const source = this.map?.getSource('boundaries') as
            | import('maplibre-gl').GeoJSONSource
            | undefined;

        source?.setData(collection as Parameters<typeof source.setData>[0]);
    }

    setPois(pois: PoiFeature[]): void {
        this.pending.pois = pois;

        if (!this.loaded) {
            return;
        }

        const source = this.map?.getSource('pois') as
            | import('maplibre-gl').GeoJSONSource
            | undefined;

        source?.setData(this.poiCollection(pois) as Parameters<typeof source.setData>[0]);
    }

    /**
     * Highlight one boundary by its stable slug (Map Phase 3), null to
     * clear. Pre-load values are buffered like every other setter: the
     * selected layers are CREATED with the stored slug in their filter.
     */
    setSelectedBoundary(slug: string | null): void {
        this.selectedBoundarySlug = slug;

        if (!this.loaded || this.map === null) {
            return;
        }

        // ' ' can never equal a real slug (the grammar is [a-z0-9-]), so a
        // cleared selection genuinely draws nothing.
        const filter: import('maplibre-gl').FilterSpecification = ['==', ['get', 'slug'], slug ?? ' '];

        if (this.map.getLayer('boundary-selected-fill') !== undefined) {
            this.map.setFilter('boundary-selected-fill', filter);
            this.map.setFilter('boundary-selected-line', filter);
        }
    }

    /**
     * Gate polygon selection (Map Phase 3). Disabling also drops any live
     * hover so a pick/draw mode never leaves a stale highlight behind.
     */
    setBoundaryInteractive(enabled: boolean): void {
        this.boundaryInteractive = enabled;

        if (!enabled && this.map !== null && this.loaded) {
            this.setBoundaryHover(this.map, null);
        }
    }

    /**
     * Fit the camera to a lat/lng box once, at the moment of an explicit
     * selection. Padding is per-side CSS pixels so the caller can reserve
     * room for its card or sheet; maxZoom stops a tiny mahalla from filling
     * the screen and stripping all spatial context.
     */
    fitBounds(bounds: MapBounds, options?: {
        padding?: { top: number; bottom: number; left: number; right: number };
        maxZoom?: number;
    }): void {
        this.map?.fitBounds(
            [[bounds.west, bounds.south], [bounds.east, bounds.north]],
            {
                padding: options?.padding ?? { top: 48, bottom: 48, left: 48, right: 48 },
                maxZoom: options?.maxZoom ?? 15,
                duration: 650,
            },
        );
    }

    /** Feature-state bookkeeping for the boundary hover — state only, never cursor. */
    private setBoundaryHover(map: import('maplibre-gl').Map, slug: string | null): void {
        if (slug === this.hoveredBoundarySlug) {
            return;
        }

        if (this.hoveredBoundarySlug !== null && map.getSource('boundaries') !== undefined) {
            map.setFeatureState(
                { source: 'boundaries', id: this.hoveredBoundarySlug },
                { hover: false },
            );
        }

        if (slug !== null && map.getSource('boundaries') !== undefined) {
            map.setFeatureState({ source: 'boundaries', id: slug }, { hover: true });
        }

        this.hoveredBoundarySlug = slug;
    }

    flyTo(point: LatLng, zoom?: number): void {
        this.map?.flyTo({ center: [point.lng, point.lat], zoom: zoom ?? this.map.getZoom() });
    }

    /**
     * The picker's single editable point: a real draggable marker, distinct
     * from the clustered data points. null removes it.
     */
    setPin(point: LatLng | null, onDragEnd?: (point: LatLng) => void): void {
        if (point === null) {
            this.pin?.remove();
            this.pin = null;
            return;
        }

        if (this.pin) {
            this.pin.setLngLat([point.lng, point.lat]);
            return;
        }

        if (!this.map) {
            return;
        }

        void import('maplibre-gl').then((maplibre) => {
            if (!this.map || this.pin) {
                return;
            }

            // Petrol on light basemaps (the historical pin); amber on the
            // dark public basemap, where petrol sinks into the ground it
            // is supposed to mark.
            this.pin = new maplibre.Marker({
                color: this.options.labelScheme === 'dark' ? '#f3c56f' : '#0f3e59',
                draggable: true,
            })
                .setLngLat([point.lng, point.lat])
                .addTo(this.map);

            this.pin.on('dragend', () => {
                const position = this.pin?.getLngLat();

                if (position && onDragEnd) {
                    onDragEnd({ lat: position.lat, lng: position.lng });
                }
            });
        });
    }

    resize(): void {
        this.map?.resize();
    }

    destroy(): void {
        this.destroyed = true;
        /*
         * A pending ready() settles NOW, not at the deadline: after
         * map.remove() below, 'load' can never fire, so without this the
         * timer was the only exit — a rejection nobody expects arriving up
         * to twenty seconds after the component is gone.
         */
        this.settle(new Error('maplibre-destroyed'));
        this.observer?.disconnect();
        this.observer = null;
        this.pin?.remove();
        this.pin = null;
        this.map?.remove();
        this.map = null;
    }

    /** Layers that exist right now — query only what has been added. */
    private presentLayers(map: import('maplibre-gl').Map, candidates: string[]): string[] {
        return candidates.filter((layer) => map.getLayer(layer) !== undefined);
    }

    private poiCollection(pois: PoiFeature[]) {
        return {
            type: 'FeatureCollection' as const,
            features: pois.map((poi) => ({
                type: 'Feature' as const,
                geometry: {
                    type: 'Point' as const,
                    coordinates: [poi.lng, poi.lat] as [number, number],
                },
                properties: {
                    title: poi.title,
                    category: poi.category,
                    active: poi.active === true,
                },
            })),
        };
    }

    private pointCollection(points: PointFeature[]) {
        return {
            type: 'FeatureCollection' as const,
            features: points.map((point) => ({
                type: 'Feature' as const,
                geometry: {
                    type: 'Point' as const,
                    coordinates: [point.lng, point.lat] as [number, number],
                },
                properties: {
                    title: point.title,
                    colour: point.colour,
                    ...(point.id !== undefined ? { id: point.id } : {}),
                    ...(point.trend !== undefined ? { trendIcon: trendIconName(point.trend) } : {}),
                    ...(point.label !== undefined ? { label: point.label } : {}),
                },
            })),
        };
    }
}

/**
 * The four trend marker faces, drawn in code so they need no sprite, no
 * glyph server and no remote asset — they render under any style, including
 * the deterministic one the browser tests serve.
 *
 * Direction is carried by SHAPE as well as colour (chevron up / chevron
 * down / horizontal bar / plain dot), so the semantics survive colour
 * blindness and monochrome rendering:
 *
 *   trend-up      green  #15803d, upward chevron   — price increased
 *   trend-down    red    #b91c1c, downward chevron — price decreased
 *   trend-flat    amber  #b45309, horizontal bar   — no material change
 *   trend-unknown navy   #0f3e59, plain dot        — insufficient history,
 *                                                    NO claim implied
 */
export function trendMarkerImages(): Record<string, ImageData> {
    const draw = (paint: (ctx: CanvasRenderingContext2D) => void): ImageData => {
        const size = 44; // 22px at pixelRatio 2
        const canvas = document.createElement('canvas');
        canvas.width = size;
        canvas.height = size;
        const ctx = canvas.getContext('2d');

        if (!ctx) {
            return new ImageData(size, size);
        }

        paint(ctx);

        return ctx.getImageData(0, 0, size, size);
    };

    const base = (ctx: CanvasRenderingContext2D, fill: string): void => {
        ctx.beginPath();
        ctx.arc(22, 22, 19, 0, Math.PI * 2);
        ctx.fillStyle = fill;
        ctx.fill();
        ctx.lineWidth = 4;
        ctx.strokeStyle = '#ffffff';
        ctx.stroke();
    };

    const chevron = (ctx: CanvasRenderingContext2D, up: boolean): void => {
        ctx.beginPath();
        if (up) {
            ctx.moveTo(12, 27);
            ctx.lineTo(22, 15);
            ctx.lineTo(32, 27);
        } else {
            ctx.moveTo(12, 17);
            ctx.lineTo(22, 29);
            ctx.lineTo(32, 17);
        }
        ctx.lineWidth = 5;
        ctx.lineCap = 'round';
        ctx.lineJoin = 'round';
        ctx.strokeStyle = '#ffffff';
        ctx.stroke();
    };

    return {
        'trend-up': draw((ctx) => {
            base(ctx, trendColour('up'));
            chevron(ctx, true);
        }),
        'trend-down': draw((ctx) => {
            base(ctx, trendColour('down'));
            chevron(ctx, false);
        }),
        'trend-flat': draw((ctx) => {
            base(ctx, trendColour('flat'));
            ctx.beginPath();
            ctx.moveTo(12, 22);
            ctx.lineTo(32, 22);
            ctx.lineWidth = 5;
            ctx.lineCap = 'round';
            ctx.strokeStyle = '#ffffff';
            ctx.stroke();
        }),
        'trend-unknown': draw((ctx) => {
            base(ctx, trendColour('unknown'));
            ctx.beginPath();
            ctx.arc(22, 22, 5, 0, Math.PI * 2);
            ctx.fillStyle = '#ffffff';
            ctx.fill();
        }),
    };
}
