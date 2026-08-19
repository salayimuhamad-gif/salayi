/**
 * Simple-picker geometry fidelity (canonical L1), tested against the
 * PRODUCTION modules.
 *
 * The classification and display helpers exist so the simple picker never
 * silently downgrades a stored boundary richer than its single-ring editing
 * model. These tests pin the structure the map adapter RECEIVES — geometry
 * type, ring counts, exact closed coordinates, [lng, lat] order — not just
 * that something renders. Complexity is derived from the wizard parser's
 * already-parsed Component[] (`fromWkt`), never by re-reading WKT text: the
 * codebase allows exactly three WKT implementations, and this feature adds
 * none.
 */

import {
    boundaryDisplayCollection,
    classifyBoundaryComponents,
    componentsBounds,
    parsePolygonRing,
    polygonToWkt,
} from '../../resources/js/lib/geometry';
import { fromWkt } from '../../resources/js/lib/wizard/geometry';

let failures = 0;

function ok(name: string, condition: boolean): void {
    if (condition) {
        console.log(`  pass ${name}`);
    } else {
        failures += 1;
        console.log(`  FAIL ${name}`);
    }
}

const SIMPLE = 'POLYGON((44 36.18, 44.02 36.18, 44.02 36.2, 44 36.2, 44 36.18))';
const HOLED = 'POLYGON((44 36.18, 44.02 36.18, 44.02 36.2, 44 36.2, 44 36.18), '
    + '(44.005 36.185, 44.01 36.185, 44.01 36.19, 44.005 36.19, 44.005 36.185))';
const MULTI = 'MULTIPOLYGON(((44 36.18, 44.02 36.18, 44.02 36.2, 44 36.2, 44 36.18)), '
    + '((44.03 36.21, 44.05 36.21, 44.05 36.23, 44.03 36.23, 44.03 36.21), '
    + '(44.035 36.215, 44.04 36.215, 44.04 36.22, 44.035 36.22, 44.035 36.215)))';

console.log('\n=== boundary complexity, decided on parsed components ===');

ok('empty WKT classifies as none', classifyBoundaryComponents(fromWkt('')) === 'none');
ok('null WKT classifies as none', classifyBoundaryComponents(fromWkt(null)) === 'none');
ok('garbage classifies as none', classifyBoundaryComponents(fromWkt('not geometry at all')) === 'none');
ok('a single hole-free ring is simple', classifyBoundaryComponents(fromWkt(SIMPLE)) === 'simple');
ok('a polygon with a hole is complex', classifyBoundaryComponents(fromWkt(HOLED)) === 'complex');
ok('a multipolygon is complex', classifyBoundaryComponents(fromWkt(MULTI)) === 'complex');

console.log('\n=== full-fidelity display: what the adapter receives ===');

const holed = boundaryDisplayCollection(fromWkt(HOLED));

ok('holed polygon: one feature', holed.features.length === 1);
ok('holed polygon: FeatureCollection envelope', holed.type === 'FeatureCollection');
ok('holed polygon: geometry type Polygon', holed.features[0]?.geometry.type === 'Polygon');

const holedRings = holed.features[0]?.geometry.coordinates as [number, number][][];

ok('holed polygon: BOTH rings survive (exterior + hole)', holedRings.length === 2);
ok(
    'holed polygon: exterior is closed with [lng, lat] pairs intact',
    holedRings[0].length === 5
        && holedRings[0][0][0] === 44 && holedRings[0][0][1] === 36.18
        && holedRings[0][2][0] === 44.02 && holedRings[0][2][1] === 36.2
        && holedRings[0][4][0] === holedRings[0][0][0]
        && holedRings[0][4][1] === holedRings[0][0][1],
);
ok(
    'holed polygon: the hole ring is closed and exact',
    holedRings[1].length === 5
        && holedRings[1][0][0] === 44.005 && holedRings[1][0][1] === 36.185
        && holedRings[1][2][0] === 44.01 && holedRings[1][2][1] === 36.19
        && holedRings[1][4][0] === holedRings[1][0][0],
);

const multi = boundaryDisplayCollection(fromWkt(MULTI));

ok('multipolygon: one feature per part', multi.features.length === 2);
ok(
    'multipolygon: every part is a Polygon feature',
    multi.features.every((feature) => feature.type === 'Feature' && feature.geometry.type === 'Polygon'),
);

const partOne = multi.features[0]?.geometry.coordinates as [number, number][][];
const partTwo = multi.features[1]?.geometry.coordinates as [number, number][][];

ok('multipolygon: hole-free part carries exactly its exterior', partOne.length === 1 && partOne[0].length === 5);
ok('multipolygon: part order preserved', partOne[0][0][0] === 44 && partTwo[0][0][0] === 44.03);
ok('multipolygon: the holed part keeps its hole', partTwo.length === 2 && partTwo[1][0][0] === 44.035 && partTwo[1][0][1] === 36.215);
ok(
    'multipolygon: distinct feature slugs per part',
    multi.features[0]?.properties.slug !== multi.features[1]?.properties.slug,
);

console.log('\n=== camera bounds across every ring ===');

const bounds = componentsBounds(fromWkt(MULTI));

ok(
    'bounds span all parts',
    bounds !== null
        && bounds[0][0] === 44 && bounds[0][1] === 36.18
        && bounds[1][0] === 44.05 && bounds[1][1] === 36.23,
);
ok('bounds of nothing is null', componentsBounds(fromWkt('')) === null);

console.log('\n=== simple-path byte parity: the historical flow unchanged ===');

const simpleRing = parsePolygonRing(SIMPLE);

ok('simple exterior still parses through the historical reader', simpleRing !== null && simpleRing.length === 5);
ok(
    'polygonToWkt emits the historical byte format',
    polygonToWkt(simpleRing ?? []) === 'POLYGON((44 36.18, 44.02 36.18, 44.02 36.2, 44 36.2, 44 36.18))',
);
ok(
    'an unclosed drawn ring closes exactly as before',
    polygonToWkt([
        { lng: 44, lat: 36.18 },
        { lng: 44.02, lat: 36.18 },
        { lng: 44.02, lat: 36.2 },
    ]) === 'POLYGON((44 36.18, 44.02 36.18, 44.02 36.2, 44 36.18))',
);

if (failures > 0) {
    console.error(`\n${failures} geometry fidelity test(s) failed`);
    process.exit(1);
}

console.log('\ngeometry fidelity: all tests passed');
