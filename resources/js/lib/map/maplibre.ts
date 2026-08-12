import type {
    AdapterOptions,
    BoundaryCollection,
    LatLng,
    MapAdapter,
    MapBounds,
    PointFeature,
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
    /** Category token for diagnostics: style / webgl / container / timeout. */
    private failureCategory: string | null = null;
    private observer: ResizeObserver | null = null;
    private pin: import('maplibre-gl').Marker | null = null;
    private pending: { points: PointFeature[]; boundaries: BoundaryCollection | null } = {
        points: [],
        boundaries: null,
    };

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
         * picker's contract — usable with nothing configured), and 'demo'
         * keeps the historical public default. The demo host is
         * documentation-grade infrastructure and unsuitable for production
         * — the completion doc names real options — but silently swapping
         * providers is not this adapter's call.
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
                : 'https://demotiles.maplibre.org/style.json';

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
            attributionControl: { compact: true },
        });

        map.addControl(new maplibre.NavigationControl({ showCompass: false }), 'top-right');
        map.addControl(new maplibre.ScaleControl({ unit: 'metric' }), 'bottom-left');

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
             */
            const hit = map
                .queryRenderedFeatures(event.point, {
                    layers: this.presentLayers(map, ['unclustered', 'trend-markers', 'point-labels', 'clusters']),
                })
                .length > 0;

            if (!hit) {
                this.options.events.onClick({ lat: event.lngLat.lat, lng: event.lngLat.lng });
            }
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
            // non-point geometry.
            map.addSource('boundaries', {
                type: 'geojson',
                data: this.pending.boundaries ?? { type: 'FeatureCollection', features: [] },
            });

            // One accent drives polygons and clusters, so a page can restyle
            // the map (the investment surface uses the brand gold) without a
            // second adapter. The default is the explorer's existing blue.
            const accent = this.options.accentColour ?? '#1f6feb';

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
                paint: { 'line-color': accent, 'line-width': 1.5, 'line-opacity': 0.6 },
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
                    'text-color': '#17181a',
                    'text-halo-color': '#ffffff',
                    'text-halo-width': 1.4,
                },
            });

            // Project names join at street zoom (>= 13), decluttered below it.
            map.addLayer({
                id: 'point-names',
                type: 'symbol',
                source: 'features',
                minzoom: 13,
                filter: ['all', ['!', ['has', 'point_count']], ['has', 'label']],
                layout: {
                    'text-field': ['get', 'title'],
                    'text-size': 11,
                    'text-anchor': 'bottom',
                    'text-offset': [0, -1.2],
                    'text-allow-overlap': false,
                    'text-optional': true,
                },
                paint: {
                    'text-color': '#0f3e59',
                    'text-halo-color': '#ffffff',
                    'text-halo-width': 1.4,
                },
            });

            const emitMarkerClick = (event: import('maplibre-gl').MapLayerMouseEvent): void => {
                const id = event.features?.[0]?.properties?.id as number | undefined;

                if (id !== undefined && this.options.events.onMarkerClick) {
                    this.options.events.onMarkerClick(Number(id));
                }
            };

            map.on('click', 'unclustered', emitMarkerClick);
            map.on('click', 'trend-markers', emitMarkerClick);
            map.on('click', 'point-labels', emitMarkerClick);

            for (const layer of ['unclustered', 'trend-markers', 'point-labels']) {
                map.on('mouseenter', layer, () => {
                    map.getCanvas().style.cursor = 'pointer';
                });
                map.on('mouseleave', layer, () => {
                    map.getCanvas().style.cursor = '';
                });
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

        if (this.failedBeforeLoad) {
            return Promise.reject(new Error('maplibre-style-failed'));
        }

        /*
         * Bounded, three ways out: load resolves, a pre-load error rejects,
         * and a DEADLINE rejects when the provider simply stalls — a style
         * whose tile or glyph requests hang without ever erroring used to
         * leave the page on its spinner indefinitely. The category token in
         * the message feeds the dev diagnostics.
         */
        return new Promise((resolve, reject) => {
            const deadline = window.setTimeout(() => {
                this.failureCategory = this.failureCategory ?? 'timeout';

                if (import.meta.env.DEV) {
                    console.warn('[map] ready() deadline elapsed; category:', this.failureCategory);
                }

                reject(new Error('maplibre-ready-timeout'));
            }, this.options.readyTimeoutMs ?? 20000);

            this.map?.once('load', () => {
                window.clearTimeout(deadline);
                resolve();
            });
            this.map?.once('error', () => {
                if (!this.loaded) {
                    window.clearTimeout(deadline);
                    reject(new Error('maplibre-style-failed'));
                }
            });
        });
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

            this.pin = new maplibre.Marker({ color: '#0f3e59', draggable: true })
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
