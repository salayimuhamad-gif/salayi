import type {
    AdapterOptions,
    BoundaryCollection,
    LatLng,
    MapAdapter,
    MapBounds,
    PointFeature,
} from './types';

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

        const map = new maplibre.Map({
            container: this.options.container,
            style: this.options.styleUrl ?? 'https://demotiles.maplibre.org/style.json',
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

        map.on('error', () => this.options.events.onError());
        map.on('moveend', () => this.options.events.onMoveEnd());
        map.on('click', (event) =>
            this.options.events.onClick({ lat: event.lngLat.lat, lng: event.lngLat.lng }),
        );

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
                filter: ['!', ['has', 'point_count']],
                paint: {
                    'circle-color': ['get', 'colour'],
                    'circle-radius': 7,
                    'circle-stroke-width': 2,
                    'circle-stroke-color': '#ffffff',
                },
            });

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

    ready(): Promise<void> {
        if (this.loaded) {
            return Promise.resolve();
        }

        return new Promise((resolve) => {
            this.map?.once('load', () => resolve());
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

    destroy(): void {
        this.map?.remove();
        this.map = null;
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
                properties: { title: point.title, colour: point.colour },
            })),
        };
    }
}
