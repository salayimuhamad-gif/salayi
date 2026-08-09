/**
 * Map provider adapter contract (§10.1).
 *
 * The explorer previously took `provider` from the server and then imported
 * MapLibre unconditionally. A deployment with a valid Google key was told it
 * was on Google, reported Google in its props, and rendered MapLibre tiles —
 * so the setting was untestable from the outside and the key bought nothing.
 *
 * This interface is deliberately small. Everything the explorer needs from a
 * map is here, and anything a specific provider can do beyond it is not
 * exposed, because the moment one adapter leaks its native object the other
 * stops being substitutable and the fallback path breaks in production rather
 * than in review.
 */

export interface LatLng {
    lat: number;
    lng: number;
}

export interface MapBounds {
    north: number;
    south: number;
    east: number;
    west: number;
}

export interface PointFeature {
    lat: number;
    lng: number;
    title: string;
    colour: string;
}

/*
 * Boundary geometry lives in ./geojson, which has no browser or map-library
 * import and is therefore unit-testable with node alone. Re-exported here so
 * adapters keep importing one module.
 */
import type { BoundaryCollection } from './geojson';

export type {
    BoundaryCollection,
    BoundaryFeature,
    BoundaryGeometry,
    LatLngLiteral,
    LinearRing,
    MultiPolygonGeometry,
    PolygonGeometry,
    Position,
} from './geojson';

export interface AdapterEvents {
    /** Viewport settled — the explorer refetches. */
    onMoveEnd: () => void;
    /** A click on the map surface, used for centre-picking and drawing. */
    onClick: (point: LatLng) => void;
    /**
     * The provider failed after construction (tile 403, revoked key, network).
     * Distinct from a constructor throw: this can arrive minutes later.
     */
    onError: () => void;
}

export interface MapAdapter {
    /** Which provider actually rendered. Never a request, always a fact. */
    readonly provider: 'maplibre' | 'google';

    /** Resolves once the map surface is ready for sources and layers. */
    ready(): Promise<void>;

    getBounds(): MapBounds | null;
    getZoom(): number | null;

    setPoints(points: PointFeature[]): void;
    setBoundaries(collection: BoundaryCollection): void;

    flyTo(point: LatLng, zoom?: number): void;

    destroy(): void;
}

export interface AdapterOptions {
    container: HTMLElement;
    /** MapLibre style URL. Ignored by the Google adapter. */
    styleUrl: string | null;
    /** Google JS API key. Ignored by the MapLibre adapter. */
    googleKey: string | null;
    centre: LatLng;
    zoom: number;
    /** Optional camera fences. Honoured by both adapters where the provider supports them. */
    minZoom?: number;
    maxZoom?: number;
    maxBounds?: MapBounds;
    /** Accent for clusters and boundary polygons. Defaults to the explorer blue. */
    accentColour?: string;
    events: AdapterEvents;
}
