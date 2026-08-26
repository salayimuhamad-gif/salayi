/**
 * The place-category -> POI overlay mapping (Map Phase 2) — pure, node-only.
 *
 * The table under test is the ONLY path from the database's open category
 * vocabulary into the adapter's closed PoiCategory union, so these
 * assertions are the contract: every shipped key maps somewhere legal,
 * nothing unknown ever reaches the union, and the semantically loaded
 * collapses (worship, hospitality) stay exactly as decided.
 */

import { KNOWN_PLACE_CATEGORY_KEYS, poiCategoryFor } from '../../resources/js/lib/map/poiCategories';
import type { PoiCategory } from '../../resources/js/lib/map/types';

let failures = 0;

function ok(name: string, condition: boolean): void {
    if (condition) {
        console.log(`  ok ${name}`);
    } else {
        failures += 1;
        console.error(`  FAIL ${name}`);
    }
}

const UNION: readonly PoiCategory[] = [
    'school', 'university', 'hospital', 'clinic', 'pharmacy', 'supermarket',
    'shopping', 'mosque', 'park', 'transport', 'government', 'bank', 'atm',
    'fuel', 'restaurant', 'other',
];

console.log('completeness: the 31 shipped keys');

// Mirrors PlaceCategoryKey::values() — a key added there must be mapped here.
const SHIPPED = [
    'school', 'kindergarten', 'university', 'institute', 'hospital', 'clinic',
    'pharmacy', 'mall', 'supermarket', 'market', 'restaurant', 'cafe', 'park',
    'sports_facility', 'mosque', 'church', 'government_office', 'police',
    'fire_station', 'bank', 'atm', 'airport', 'bus_station', 'fuel_station',
    'workplace', 'industrial_zone', 'landmark', 'hotel', 'road_entrance',
    'highway_access', 'planned_infrastructure',
];

ok('all 31 shipped keys are known to the table', SHIPPED.every(
    (key) => KNOWN_PLACE_CATEGORY_KEYS.includes(key),
));
ok('the table carries no key the product does not ship', KNOWN_PLACE_CATEGORY_KEYS.every(
    (key) => SHIPPED.includes(key),
));

console.log('closure: everything lands inside the union');

for (const key of SHIPPED) {
    ok(`${key} maps into the union`, UNION.includes(poiCategoryFor(key)));
}

console.log('semantic pins');

ok('school stays school', poiCategoryFor('school') === 'school');
ok('kindergarten joins school', poiCategoryFor('kindergarten') === 'school');
ok('institute joins university', poiCategoryFor('institute') === 'university');
ok('market joins supermarket', poiCategoryFor('market') === 'supermarket');
ok('mall is shopping', poiCategoryFor('mall') === 'shopping');
ok('cafe joins restaurant', poiCategoryFor('cafe') === 'restaurant');
ok('hotel is NOT restaurant', poiCategoryFor('hotel') === 'other');
ok('mosque stays mosque', poiCategoryFor('mosque') === 'mosque');
ok('church is other — never mosque', poiCategoryFor('church') === 'other');
ok('police is a civic service point', poiCategoryFor('police') === 'government');
ok('bus_station is transport', poiCategoryFor('bus_station') === 'transport');
ok('fuel_station is fuel', poiCategoryFor('fuel_station') === 'fuel');

console.log('unknown input degrades honestly');

ok('unshipped admin key → other', poiCategoryFor('shisha_lounge') === 'other');
ok('empty string → other', poiCategoryFor('') === 'other');
ok('null → other', poiCategoryFor(null) === 'other');
ok('undefined → other', poiCategoryFor(undefined) === 'other');

if (failures > 0) {
    console.error(`\n${failures} poi assertion(s) failed`);
    process.exit(1);
}

console.log('\npoi suite: PASS');
