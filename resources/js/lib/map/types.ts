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

/**
 * The price-trend vocabulary a marker may carry. Semantics are decided by
 * the SERVER from real stored observations (same price type, same currency,
 * two non-null values); the client only renders. `unknown` is a first-class
 * value — insufficient history must read as "no claim", never as flat.
 */
export type PriceTrend = 'up' | 'down' | 'flat' | 'unknown';

/**
 * The POI vocabulary the Phase 2 places layers will speak (Map Phase 1
 * prepares the hook; no ingestion exists yet). A closed union rather than a
 * free string so a typo cannot invent a category the toggles and styling
 * never heard of.
 */
export type PoiCategory =
    | 'school'
    | 'university'
    | 'hospital'
    | 'clinic'
    | 'pharmacy'
    | 'supermarket'
    | 'shopping'
    | 'mosque'
    | 'park'
    | 'transport'
    | 'government'
    | 'bank'
    | 'atm'
    | 'fuel'
    | 'restaurant'
    | 'other';

/**
 * One point-of-interest for the controlled overlay layers. Deliberately
 * SEPARATE from PointFeature: POIs are ambient context, never selectable
 * real-estate records — they carry no price, no trend, no record id, and the
 * renderer keeps them visually subordinate to project markers.
 */
export interface PoiFeature {
    lat: number;
    lng: number;
    /** Canonical MULK display name (already locale-resolved by the caller). */
    title: string;
    category: PoiCategory;
    /** Amber active voice for the one POI a user focussed; default subdued. */
    active?: boolean;
}

export interface PointFeature {
    lat: number;
    lng: number;
    title: string;
    colour: string;
    /** Stable identity, so a marker click can select the matching record. */
    id?: number;
    /**
     * When present the MapLibre adapter renders a trend-shaped marker
     * (direction chevron + colour) instead of a plain dot, so the trend is
     * readable on the MAP itself and never through colour alone.
     */
    trend?: PriceTrend;
    /**
     * Optional short text rendered ON the map beside the marker (the pricing
     * map's recorded-price label). Pre-formatted by the caller — the adapter
     * never formats or invents figures. Points without a label render exactly
     * as before; the label layers exist only for points that carry one.
     */
    label?: string;
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
    /**
     * A click that landed on an individual (unclustered) point. Optional:
     * pages that select from the list only need not wire it, and the Google
     * adapter does not emit it — the list remains the selection path there.
     */
    onMarkerClick?: (id: number) => void;

    /**
     * A click that selected an AREA POLYGON (Map Phase 3). Optional: only
     * the Explorer wires it, and the MapLibre adapter emits it only when
     * boundary interaction is enabled AND the click hit no record marker or
     * POI layer — the interaction priority (project marker, then intentional
     * POI interaction, then polygon) is enforced inside the adapter, never
     * left to pages. Identity comes from the boundary feature's own
     * properties — stable public fields, never internal layer ids.
     */
    onBoundarySelect?: (identity: BoundaryIdentity) => void;
}

/**
 * The stable identity a boundary feature carries (the /map/features
 * boundaries payload writes exactly these properties). Slug is the public
 * identifier every area route already uses; ids stay server-side.
 */
export interface BoundaryIdentity {
    slug: string;
    /** Locale-resolved server-side, exactly as the list renders it. */
    name: string;
    type: string;
}

export interface MapAdapter {
    /** Which provider actually rendered. Never a request, always a fact. */
    readonly provider: 'maplibre' | 'google';

    /**
     * Resolves once the map surface is ready for sources and layers;
     * REJECTS — with a category token in the Error message — when it never
     * will, and after a bounded deadline when the provider simply stalls.
     * Callers settle into an explicit error state; nothing waits forever.
     */
    ready(): Promise<void>;

    getBounds(): MapBounds | null;
    getZoom(): number | null;

    setPoints(points: PointFeature[]): void;
    setBoundaries(collection: BoundaryCollection): void;

    /**
     * Replace the controlled POI overlay (Phase 2 places layers). Optional
     * capability like setPin: the MapLibre adapter renders POIs as a
     * separate zoom-gated, deliberately subdued layer pair; pages must
     * feature-test before relying on it, and the Google adapter may skip it.
     * An empty array clears the overlay. Phase 1 ships the hook only — no
     * surface calls it yet, and no browser ever talks to Overpass directly;
     * future data arrives through cached MULK endpoints.
     */
    setPois?(pois: PoiFeature[]): void;

    /**
     * A single editable pin (the admin picker's point). null removes it.
     * Optional capability: the MapLibre adapter implements it with a
     * draggable marker; pages must feature-test before relying on it.
     */
    setPin?(point: LatLng | null, onDragEnd?: (point: LatLng) => void): void;

    flyTo(point: LatLng, zoom?: number): void;

    /**
     * Fit the camera to a lat/lng box ONCE (Map Phase 3's selection focus).
     * Optional capability like setPin. Padding is CSS pixels per side so a
     * floating card or bottom sheet can be accounted for; maxZoom keeps a
     * tiny polygon from filling the screen. Called only at the moment of an
     * explicit selection — never from a watcher — so user panning afterwards
     * is respected.
     */
    fitBounds?(bounds: MapBounds, options?: {
        padding?: { top: number; bottom: number; left: number; right: number };
        maxZoom?: number;
    }): void;

    /**
     * Highlight one area boundary by its stable slug (Map Phase 3), or clear
     * with null. Optional capability: the MapLibre adapter renders a
     * restrained amber outline + faint interior over the existing boundaries
     * source; nothing else changes and record markers stay on top.
     */
    setSelectedBoundary?(slug: string | null): void;

    /**
     * Outline up to three compared areas by slug in the visitor's own
     * A/B/C order (Map Phase 6), or clear with null. Optional capability:
     * each position keeps a fixed neutral colour + dash identity outside
     * the market movement palette, drawn over the existing boundaries
     * source; markers stay on top and Phase 3/4 styling is untouched.
     */
    setComparedBoundaries?(slugs: string[] | null): void;

    /**
     * Gate polygon selection (Map Phase 3). The Explorer switches it OFF
     * while centre-picking or drawing so those clicks keep their existing
     * meaning even over a polygon; onBoundarySelect fires only while
     * enabled. Off by default until the page opts in.
     */
    setBoundaryInteractive?(enabled: boolean): void;

    /**
     * Tint the area polygons as the market heatmap (Map Phase 4), or clear
     * with null. Keys are area slugs; values are the only directions the
     * market engine ever asserts — there is no 'unknown' value because
     * absence IS unknown: an unpainted area keeps the neutral dark base.
     * Optional capability like setPois; rendering stays beneath every
     * marker and the selection outline, so nothing else changes.
     */
    setMarketHeat?(heat: Record<string, 'up' | 'down' | 'flat'> | null): void;

    /**
     * Recompute the canvas after the container's size changed. The adapter
     * also self-heals via a ResizeObserver (a map built inside a hidden
     * v-show tab measures 0×0 and must recover when revealed); this exists
     * for callers that know the exact moment.
     */
    resize(): void;

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
    /**
     * Paint scheme for the ADAPTER-DRAWN text and pin (project names, price
     * labels, the picker pin) — light ink on white halos for light basemaps
     * (the historical default, byte-identical when omitted), near-white ink
     * on night halos for the dark public basemap. This tunes MULK-drawn
     * paint only; it never touches provider tiles or vendor chrome.
     */
    labelScheme?: 'light' | 'dark';
    /**
     * Deadline for ready(), milliseconds. A style whose tiles/glyphs stall
     * without ever erroring must still settle the page into its error state
     * instead of an indefinite spinner. Defaults to 20 s.
     */
    readyTimeoutMs?: number;
    /**
     * What to render when NO style URL is configured. 'dark' (default) is
     * the MULK dark basemap style served from this origin
     * (/map-styles/mulk-dark.json — CARTO Dark Matter raster tiles over a
     * near-black ground), which replaced the third-party demotiles demo
     * default; 'plain' is an inline neutral background with no network
     * dependency at all — the admin picker's contract, where clicking and
     * drawing must work even with zero map configuration.
     */
    fallbackStyle?: 'dark' | 'plain';
    events: AdapterEvents;
}
