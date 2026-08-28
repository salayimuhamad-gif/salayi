import { GoogleMapsAdapter } from './google';
import { MapLibreAdapter } from './maplibre';
import type { AdapterOptions, MapAdapter } from './types';

export type { BoundaryIdentity, MapAdapter, MapBounds, PointFeature, PoiCategory, PoiFeature, PriceTrend, BoundaryCollection, LatLng } from './types';
export { poiCategoryFor } from './poiCategories';
export { boundaryBounds } from './geojson';
export { COMPARE_IDENTITIES, type CompareIdentity } from './compare';

export interface AdapterResult {
    adapter: MapAdapter;
    /** Set when the requested provider could not be used. */
    fallbackReason: string | null;
}

/**
 * Build the adapter for the provider the server selected, with a safe fallback.
 *
 * The server decides WHICH provider (it holds the key and the config); this
 * decides whether that choice actually worked in the browser. Those are
 * genuinely different questions — a key can be present, correctly formatted
 * and still rejected at request time for a referrer restriction, which the
 * server cannot know.
 *
 * Google failing falls back to MapLibre and REPORTS why. Silence here is what
 * produced the original defect: a deployment reporting Google while rendering
 * MapLibre, with nothing anywhere to reveal the mismatch.
 *
 * MapLibre failing does not fall back to anything. There is nowhere left to
 * go, so the explorer shows its provider-failure state and the list view —
 * which is why the list is a peer of the map rather than a degraded mode.
 */
export async function createMapAdapter(
    provider: string,
    options: AdapterOptions,
): Promise<AdapterResult> {
    if (provider === 'google') {
        try {
            return { adapter: await GoogleMapsAdapter.create(options), fallbackReason: null };
        } catch (error) {
            /*
             * GoogleMapsAdapter.create() destroys the adapter before
             * rethrowing, and destroy() empties the container. So the element
             * handed to MapLibre below is already free of Google's DOM — two
             * providers cannot end up stacked in the same box, which would
             * paint a dead grey map over a working one.
             */
            const reason = error instanceof Error ? error.message : 'google-maps-failed';

            return {
                adapter: await MapLibreAdapter.create(options),
                fallbackReason: reason,
            };
        }
    }

    return { adapter: await MapLibreAdapter.create(options), fallbackReason: null };
}
