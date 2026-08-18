/**
 * Wizard frontend logic, tested against the PRODUCTION module.
 *
 * The first version of this file copied MapPicker's helpers into itself, so it
 * passed whether or not the component was correct — and it was green while the
 * component flattened every hole into a separate filled shape. These import
 * `resources/js/lib/wizard/geometry.ts`, which is the code the component uses.
 */

import {
    addHole,
    createRequestGate,
    canStartHole,
    createThrottle,
    moveHoleVertex,
    parseTypedPoint,
    canRemoveHoleVertex,
    hasDuplicateVertices,
    validateGeometry,
    removeHoleVertex,
    toRenderedFeatures,
    toggleHoleSelection,
    fromWkt,
    isUsableRing,
    moveItem,
    moveItemTo,
    moveVertex,
    removeComponent,
    removeHole,
    removeVertex,
    toWkt,
    type Component,
} from '../../resources/js/lib/wizard/geometry';

let failures = 0;

function ok(name: string, condition: boolean): void {
    if (condition) {
        console.log(`  pass ${name}`);
    } else {
        failures += 1;
        console.log(`  FAIL ${name}`);
    }
}

const square = (offset = 0): { lat: number; lng: number }[] => [
    { lat: 36.18 + offset, lng: 44.0 + offset },
    { lat: 36.18 + offset, lng: 44.02 + offset },
    { lat: 36.2 + offset, lng: 44.02 + offset },
    { lat: 36.2 + offset, lng: 44.0 + offset },
];

const hole = (): { lat: number; lng: number }[] => [
    { lat: 36.185, lng: 44.005 },
    { lat: 36.185, lng: 44.01 },
    { lat: 36.19, lng: 44.01 },
    { lat: 36.19, lng: 44.005 },
];

console.log('\n=== WKT structure ===');

const simple: Component[] = [{ exterior: square(), holes: [] }];

ok('one component emits POLYGON', toWkt(simple)?.startsWith('POLYGON') === true);
ok('nothing usable emits null', toWkt([]) === null);
// Longitude first. Swapping it puts Erbil in the Indian Ocean.
ok('WKT is lng lat', toWkt(simple)?.includes('44.000000 36.180000') === true);

const withHole: Component[] = [{ exterior: square(), holes: [hole()] }];
const holeWkt = toWkt(withHole) as string;

ok('a polygon with a hole is still POLYGON', holeWkt.startsWith('POLYGON'));
// The bug: flattening turned this into MULTIPOLYGON, so a courtyard became a
// second building and its area was counted twice.
ok('a hole does NOT become a multipolygon', !holeWkt.startsWith('MULTIPOLYGON'));

const holeRound = fromWkt(holeWkt);

ok('a polygon with a hole round-trips to one component', holeRound.length === 1);
ok('the hole survives the round-trip', holeRound[0].holes.length === 1);
ok('the exterior survives the round-trip', holeRound[0].exterior.length === 4);
ok('round-trip serialisation is stable', toWkt(holeRound) === holeWkt);

const twoHoles: Component[] = [{
    exterior: square(),
    holes: [hole(), [
        { lat: 36.195, lng: 44.015 },
        { lat: 36.195, lng: 44.018 },
        { lat: 36.198, lng: 44.018 },
    ]],
}];
const twoHolesRound = fromWkt(toWkt(twoHoles) as string);

ok('two holes round-trip as two holes', twoHolesRound[0].holes.length === 2);
ok('two holes stay in one component', twoHolesRound.length === 1);

console.log('\n=== MultiPolygon structure ===');

const multi: Component[] = [
    { exterior: square(), holes: [hole()] },
    { exterior: square(0.5), holes: [] },
];
const multiWkt = toWkt(multi) as string;

ok('two components emit MULTIPOLYGON', multiWkt.startsWith('MULTIPOLYGON'));

const multiRound = fromWkt(multiWkt);

ok('a multipolygon round-trips to two components', multiRound.length === 2);
ok('the first component keeps its hole', multiRound[0].holes.length === 1);
ok('the second component has no holes', multiRound[1].holes.length === 0);
ok('multipolygon round-trip is stable', toWkt(multiRound) === multiWkt);

console.log('\n=== editing ===');

const edited = moveVertex(withHole, 0, 0, { lat: 36.17, lng: 43.99 });

ok('a completed component can be edited', edited[0].exterior[0].lat === 36.17);
ok('editing the exterior leaves holes alone', edited[0].holes.length === 1);

const trimmed = removeVertex(withHole, 0, 1);

ok('a vertex can be removed', trimmed[0].exterior.length === 3);
ok('three vertices are still usable', isUsableRing(trimmed[0].exterior));

// Deleting a hole is NOT deleting the component: removing a courtyard should
// not remove the building around it.
const holeless = removeHole(withHole, 0, 0);

ok('deleting a hole keeps the component', holeless.length === 1);
ok('deleting a hole removes only the hole', holeless[0].holes.length === 0);

const gone = removeComponent(multi, 0);

ok('deleting a component removes it', gone.length === 1);
ok('deleting a component takes its holes with it', gone[0].holes.length === 0);

const added = addHole(simple, 0, hole());

ok('a hole can be added to a component', added[0].holes.length === 1);

// A two-vertex ring encloses nothing. The client must not invent a third.
ok('a two-vertex ring is not usable', !isUsableRing([{ lat: 1, lng: 1 }, { lat: 2, lng: 2 }]));
ok('an unusable component is not serialised', toWkt([{ exterior: [{ lat: 1, lng: 1 }], holes: [] }]) === null);

console.log('\n=== media ordering ===');

const ids = [10, 20, 30];

ok('moving up swaps with the previous', moveItem(ids, 1, -1).join() === '20,10,30');
ok('moving down swaps with the next', moveItem(ids, 1, 1).join() === '10,30,20');
ok('moving the first up is a no-op', moveItem(ids, 0, -1).join() === '10,20,30');
ok('moving the last down is a no-op', moveItem(ids, 2, 1).join() === '10,20,30');
ok('dragging first to last reorders', moveItemTo(ids, 0, 2).join() === '20,30,10');
ok('dragging last to first reorders', moveItemTo(ids, 2, 0).join() === '30,10,20');
ok('dropping on itself is stable', moveItemTo(ids, 1, 1).join() === '10,20,30');

console.log('\n=== request sequencing ===');

const gate = createRequestGate();
const first = gate.begin();
const second = gate.begin();

ok('the newest request is current', gate.isCurrent(second));
// The race: two requests fired for one point move, and whichever returned last
// won — producing a suggested area for a location nobody chose.
ok('an older response is discarded', !gate.isCurrent(first));

const third = gate.begin();

ok('a newer request supersedes again', gate.isCurrent(third) && !gate.isCurrent(second));

console.log('\n=== stale version and error classes ===');

const isStale = (client: number, server: number) => client !== server;

ok('a matching version is not stale', isStale(4, 4) === false);
ok('an older version is stale', isStale(3, 4) === true);
// The lock is mandatory: a request that omits the version must not bypass it.
ok('a missing version is stale', isStale(0, 4) === true);

const classify = (status: number) => (status >= 500 ? 'fatal' : 'recoverable');

ok('a 422 is recoverable', classify(422) === 'recoverable');
ok('a 409 is recoverable', classify(409) === 'recoverable');
ok('a 500 is fatal', classify(500) === 'fatal');

console.log('\n=== rendering structure ===');

/*
 * One component renders as ONE Polygon whose ring list is exterior then holes.
 * Pushing each ring as its own feature drew a courtyard as a second building.
 */
const rendered = toRenderedFeatures(withHole, (i) => `c${i}`)[0].rings;

ok('a component renders as one feature', rendered.length === 2);
ok('the first ring is the exterior', rendered[0].length === 5);
ok('the hole is a ring of the SAME feature, not a second one', rendered[1].length === 5);

const multiRendered = toRenderedFeatures(multi, (i) => `c${i}`).map((f) => f.rings);

ok('two components render as two features', multiRendered.length === 2);
ok('the first feature carries its hole', multiRendered[0].length === 2);
ok('the second feature has only an exterior', multiRendered[1].length === 1);

console.log('\n=== hole target rules ===');

ok('a hole needs a usable exterior', canStartHole(withHole[0]));
ok('an empty component cannot take a hole', !canStartHole({ exterior: [], holes: [] }));
ok('a two-vertex component cannot take a hole',
   !canStartHole({ exterior: [{ lat: 1, lng: 1 }, { lat: 2, lng: 2 }], holes: [] }));
ok('a missing component cannot take a hole', !canStartHole(undefined));

// Editing a hole vertex must not disturb the exterior.
const editedHole: Component[] = [{
    exterior: withHole[0].exterior,
    holes: [withHole[0].holes[0].map((v, i) => (i === 0 ? { lat: 36.186, lng: 44.006 } : v))],
}];

ok('a hole vertex can be edited', editedHole[0].holes[0][0].lat === 36.186);
ok('editing a hole leaves the exterior intact',
   editedHole[0].exterior.length === withHole[0].exterior.length);
ok('an edited hole still round-trips', fromWkt(toWkt(editedHole) as string)[0].holes.length === 1);

console.log('\n=== throttling ===');

/*
 * Trailing edge: the position that matters is where the pointer stopped, not
 * where it started. Without this, dragging a marker fires one request per
 * emitted position.
 */
let ran = 0;
const throttle = createThrottle(5);

throttle.schedule(() => { ran += 1; });
throttle.schedule(() => { ran += 1; });
throttle.schedule(() => { ran += 1; });

ok('a burst has not run yet', ran === 0);

await new Promise((resolve) => setTimeout(resolve, 20));

ok('a burst of three runs once', ran === 1);

const cancelled = createThrottle(5);
let cancelledRuns = 0;

cancelled.schedule(() => { cancelledRuns += 1; });
cancelled.cancel();

await new Promise((resolve) => setTimeout(resolve, 20));

ok('a cancelled schedule never runs', cancelledRuns === 0);

console.log('\n=== atomic point updates ===');

/*
 * One value, not two. Separate latitude and longitude updates produced an
 * intermediate state with one new coordinate and one old — a location that
 * never existed, which was then queried.
 */
ok('a complete pair parses to a point', parseTypedPoint('36.19', '44.009')?.lat === 36.19);
// Null means CLEARED; undefined means not yet usable. Collapsing the two would
// make a half-typed value indistinguishable from a deliberate clear.
ok('an empty half is a clear, not a point', parseTypedPoint('36.19', '') === null);
ok('a mid-typed value is not yet usable', parseTypedPoint('-', '44.0') === undefined);
ok('an out-of-range latitude is refused', parseTypedPoint('999', '44.0') === undefined);
ok('an out-of-range longitude is refused', parseTypedPoint('36.19', '999') === undefined);

console.log('\n=== hole selection ===');

/*
 * Switching from hole 0 of component A to hole 0 of component B must OPEN B.
 * Comparing two independent refs, one of which had already been reassigned,
 * closed the editor instead.
 */
const openA = toggleHoleSelection(null, 0, 0);

ok('opening a hole selects it', openA?.componentIndex === 0 && openA?.holeIndex === 0);
ok('re-selecting the same hole closes it', toggleHoleSelection(openA, 0, 0) === null);

const switched = toggleHoleSelection(openA, 1, 0);

ok('same index in another component opens, not closes',
   switched !== null && switched.componentIndex === 1 && switched.holeIndex === 0);
ok('another hole in the same component opens',
   toggleHoleSelection(openA, 0, 1)?.holeIndex === 1);

console.log('\n=== hole vertex operations ===');

const movedHole = moveHoleVertex(withHole, 0, 0, 1, { lat: 36.2, lng: 44.02 });

ok('any hole vertex can be moved', movedHole[0].holes[0][1].lat === 36.2);
ok('moving a hole vertex leaves the exterior alone',
   movedHole[0].exterior.length === withHole[0].exterior.length);

const droppedHoleVertex = removeHoleVertex(withHole, 0, 0, 0);

ok('a hole vertex can be removed', droppedHoleVertex[0].holes[0].length === 3);
ok('removing a hole vertex keeps the hole', droppedHoleVertex[0].holes.length === 1);

/*
 * A hole below three distinct vertices encloses nothing. Refusing the deletion
 * is honest; serialising it silently produces WKT the server rejects after the
 * person has done the work.
 */
ok('a four-point hole may lose one', canRemoveHoleVertex(withHole, 0, 0));
ok('a three-point hole may not', !canRemoveHoleVertex(droppedHoleVertex, 0, 0));

const refused = removeHoleVertex(droppedHoleVertex, 0, 0, 0);

ok('a refused deletion leaves the hole unchanged', refused[0].holes[0].length === 3);

// Second line of defence for geometry arriving from elsewhere: an unusable
// hole is dropped, never the component around it.
const withBadHole: Component[] = [{
    exterior: square(),
    holes: [[{ lat: 1, lng: 1 }, { lat: 2, lng: 2 }]],
}];
const serialised = toWkt(withBadHole) as string;

/*
 * Filtering the hole out CHANGED THE BOUNDARY: the courtyard vanished and the
 * saved shape was not the one on screen. Refusing to serialise is honest.
 */
ok('invalid geometry is not serialised at all', toWkt(withBadHole) === null);

const reported = validateGeometry(withBadHole);

ok('the problem is reported', reported.length === 1);
ok('the component is identified', reported[0].componentIndex === 0);
ok('the hole is identified', reported[0].holeIndex === 0);
ok('the reason is given', reported[0].reason === 'too_few_vertices');
ok('valid geometry reports nothing', validateGeometry(withHole).length === 0);
ok('a valid hole still serialises', (toWkt(withHole) as string).split('(').length > 3);

// A move can collapse one vertex onto another; the count still looks right.
const collapsed: Component[] = [{
    exterior: square(),
    holes: [[
        { lat: 36.185, lng: 44.005 },
        { lat: 36.185, lng: 44.005 },
        { lat: 36.19, lng: 44.01 },
        { lat: 36.19, lng: 44.005 },
    ]],
}];

ok('duplicate vertices are detected', hasDuplicateVertices(collapsed[0].holes[0]));
ok('duplicates are reported as a problem',
   validateGeometry(collapsed).some((p) => p.reason === 'duplicate_vertices'));
ok('a clean ring has no duplicates', !hasDuplicateVertices(square()));

// Distinct count AFTER the proposed removal, not raw length.
ok('a four-point hole with a duplicate cannot lose another',
   !canRemoveHoleVertex(collapsed, 0, 0, 2));

ok('a hole needs a usable exterior', canStartHole(withHole[0]));
ok('an empty component cannot take a hole', !canStartHole({ exterior: [], holes: [] }));
ok('a missing component cannot take a hole', !canStartHole(undefined));

console.log(
    failures === 0
        ? '\nALL WIZARD FRONTEND ASSERTIONS PASSED'
        : `\n${failures} WIZARD FRONTEND FAILURES`,
);

if (failures > 0) {
    process.exit(1);
}
