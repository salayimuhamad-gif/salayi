import type { PoiCategory } from './types';

/*
 * MULK place-category key -> Phase 1 POI overlay vocabulary (Map Phase 2).
 *
 * The database's category keys are an OPEN set (an administrator can add
 * categories without a deploy); the overlay's PoiCategory is a CLOSED
 * TypeScript union. This table is the one seam between them, so an arbitrary
 * DB string can never leak into the union: every known key is mapped
 * explicitly, and anything unknown renders as the honest 'other' — a subdued
 * dot with a name, claiming no category it does not have.
 *
 * Deliberate collapses, stated rather than implied:
 *   - kindergarten joins school, institute joins university: same service
 *     family at map-overlay altitude, and the label still says exactly what
 *     the place is.
 *   - market joins supermarket (grocery shopping); mall is 'shopping'.
 *   - cafe joins restaurant (places to eat); a hotel is NOT a place to eat,
 *     so it stays 'other' rather than stretching 'restaurant'.
 *   - church has no union member of its own, so it renders 'other' — the
 *     union stays unchanged in Phase 2, and 'mosque' is never borrowed for a
 *     different religion's building.
 *   - police and fire stations are civic service points: 'government'.
 *   - road/highway access markers are transport infrastructure.
 */
const POI_CATEGORY_BY_KEY: Record<string, PoiCategory> = {
    school: 'school',
    kindergarten: 'school',
    university: 'university',
    institute: 'university',
    hospital: 'hospital',
    clinic: 'clinic',
    pharmacy: 'pharmacy',
    supermarket: 'supermarket',
    market: 'supermarket',
    mall: 'shopping',
    restaurant: 'restaurant',
    cafe: 'restaurant',
    park: 'park',
    sports_facility: 'park',
    mosque: 'mosque',
    church: 'other',
    government_office: 'government',
    police: 'government',
    fire_station: 'government',
    bank: 'bank',
    atm: 'atm',
    airport: 'transport',
    bus_station: 'transport',
    fuel_station: 'fuel',
    hotel: 'other',
    workplace: 'other',
    industrial_zone: 'other',
    landmark: 'other',
    road_entrance: 'transport',
    highway_access: 'transport',
    planned_infrastructure: 'other',
};

/** The 31 keys the product ships; exported for the test's completeness sweep. */
export const KNOWN_PLACE_CATEGORY_KEYS: readonly string[] = Object.keys(POI_CATEGORY_BY_KEY);

export function poiCategoryFor(key: string | null | undefined): PoiCategory {
    if (typeof key !== 'string') {
        return 'other';
    }

    return POI_CATEGORY_BY_KEY[key] ?? 'other';
}
