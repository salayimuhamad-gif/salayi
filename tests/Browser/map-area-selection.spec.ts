import { execFileSync } from 'node:child_process';
import type { Locator, Page } from '@playwright/test';
import { test, expect, LOCALES, expectNoHorizontalOverflow } from './support/harness';

/*
 * Map Phase 3: interactive area selection on the public explorer.
 *
 * ONE canonical selection, four entry paths — polygon click, the area list,
 * live location, and (already covered by location-intelligence.spec.ts) the
 * homepage — all rendering the same Area Intelligence card from the same
 * /location/resolve payload. Everything asserted here is PERSISTED fixture
 * data: the seeded `browser-ankawa` ring (43.960–44.004 × 36.205–36.245),
 * its published sale index (USD 1,250), and the two places assigned to it
 * (education 1, health 1).
 *
 * PIXEL GEOMETRY, stated once: the explorer opens at (36.19, 44.009), zoom
 * 11 — 2912.7 px/°lng, 3609.4 px/°lat (Web Mercator at this latitude). The
 * two interaction pixels below are chosen against the seeded geometry so
 * that each click can only mean one thing:
 *
 *   POLYGON_PIXEL (−42, −72) ⇒ (43.9946, 36.2099): inside the ring with
 *   ≥16px to every ring edge at 360px width, ≥55px from the area's own
 *   centroid marker (36.225, 43.99 ⇒ −55, −126), ≥66px from the in-ring
 *   project dot — and, critically, clear of the navigation control. In the
 *   RTL locales that control renders physically top-LEFT with the app's
 *   enlarged touch targets (≈62px from the start edge): the first cut of
 *   this pixel (−120, −170) landed ON the zoom button at phone widths, so
 *   every "polygon click" was silently a zoom click. Verified against the
 *   live page at 360 and 390: elementFromPoint at this pixel is the
 *   MapLibre canvas and the first click opens the sheet.
 *
 *   MARKER_PIXEL (−107.8, −75.8) ⇒ the seeded `browser-area-project` dot
 *   (36.211, 43.972) — INSIDE the ring, ≥70px from the centroid marker so
 *   the two never cluster at zoom 11, 66px from POLYGON_PIXEL. Used only
 *   on desktop-1440x900, where it sits far from the control corner.
 *
 * The deterministic style and the hermetic network keep every run
 * self-contained; the console/diagnostics gate fails any runtime fault in
 * the new adapter paths (hover, selection layers, fitBounds).
 */

const STYLE_HOST = '**/map-styles/mulk-dark.json';

const DETERMINISTIC_STYLE = {
    version: 8,
    name: 'deterministic-e2e',
    sources: {},
    layers: [{ id: 'bg', type: 'background', paint: { 'background-color': '#e8e6e1' } }],
};

async function serveDeterministicStyle(page: Page): Promise<void> {
    await page.route(STYLE_HOST, (route) =>
        route.fulfill({
            status: 200,
            contentType: 'application/json',
            body: JSON.stringify(DETERMINISTIC_STYLE),
        }),
    );
}

/** The seeded area's trilingual names — asserting real localized fields. */
const AREA_NAME: Record<string, string> = { ckb: 'ئەنکاوە', ar: 'عنكاوة', en: 'Ankawa' };

/** The areas layer chip label per locale (map.layers.areas). */
const AREAS_CHIP: Record<string, string> = { ckb: 'ناوچەکان', ar: 'المناطق', en: 'Areas' };

const POLYGON_PIXEL = { dx: -42, dy: -72 };
const MARKER_PIXEL = { dx: -107.8, dy: -75.8 };

/** Inside the seeded browser-ankawa ring. */
const INSIDE = { latitude: 36.225, longitude: 43.99 };

/** Inside the Erbil operating area, outside every seeded polygon. */
const OUTSIDE = { latitude: 36.1, longitude: 44.2 };

declare global {
    interface Window {
        __geoRequests?: number;
    }
}

/**
 * Counts calls to navigator.geolocation.getCurrentPosition WITHOUT changing
 * its behaviour — the same probe location-intelligence.spec.ts installs, so
 * "the explorer asked for nothing until the click" is measured, not assumed.
 */
async function armGeolocationProbe(page: Page): Promise<void> {
    await page.addInitScript(() => {
        window.__geoRequests = 0;

        if (typeof navigator === 'undefined' || !navigator.geolocation) {
            return;
        }

        const geolocation = navigator.geolocation;
        const original = geolocation.getCurrentPosition.bind(geolocation);

        geolocation.getCurrentPosition = (...args: Parameters<Geolocation['getCurrentPosition']>) => {
            window.__geoRequests = (window.__geoRequests ?? 0) + 1;

            return original(...args);
        };
    });
}

async function geolocationRequests(page: Page): Promise<number> {
    return await page.evaluate(() => window.__geoRequests ?? -1);
}

/*
 * The map endpoints share real per-IP rate limiters with the earlier specs
 * in a serial run; reset the window once per project pass, exactly as
 * map-production.spec.ts does. Silent no-op against a remote target.
 */
test.beforeAll(() => {
    try {
        execFileSync('php', ['artisan', 'cache:clear'], { stdio: 'ignore' });
    } catch {
        // Remote target or no artisan on this runner: nothing to clear.
    }
});

/**
 * Open /map on the deterministic style, switch the areas layer on, and wait
 * for the boundary payload — polygons are served only while that layer is
 * active, which is why every polygon scenario starts here.
 */
async function openExplorerWithBoundaries(page: Page, prefix = '', chip = AREAS_CHIP.ckb): Promise<Locator> {
    await serveDeterministicStyle(page);
    await page.goto(`${prefix}/map`, { waitUntil: 'domcontentloaded' });
    await expect(page.locator('.maplibregl-canvas')).toBeVisible({ timeout: 20_000 });

    const boundariesFetch = page.waitForResponse((response) =>
        response.url().includes('/map/features')
        && decodeURIComponent(response.url()).includes('layers[]=areas'));

    const areasChip = page.getByRole('button', { name: chip, exact: true });
    await areasChip.click();
    await expect(areasChip).toHaveAttribute('aria-pressed', 'true');

    const payload = (await (await boundariesFetch)
        .json()) as { boundaries: { features: unknown[] } };
    expect(payload.boundaries.features.length, 'the seeded ring must arrive').toBeGreaterThanOrEqual(1);

    return page.locator('[role="application"]').first();
}

/**
 * Click the polygon pixel until the selection surface appears — the paint
 * of a freshly-set GeoJSON source lands a frame or two after the response,
 * and a miss before that is a harmless empty-map click.
 */
async function clickPolygonUntilSelected(page: Page, map: Locator, target: Locator): Promise<void> {
    const box = await map.boundingBox();
    expect(box).not.toBeNull();

    await expect(async () => {
        await map.click({
            position: { x: box!.width / 2 + POLYGON_PIXEL.dx, y: box!.height / 2 + POLYGON_PIXEL.dy },
        });
        await expect(target).toBeVisible({ timeout: 2_000 });
    }).toPass({ timeout: 20_000 });
}

/* --------------------------------------------------- polygon → card, all viewports */

test('clicking the area polygon opens the Area Intelligence card from persisted data', async ({ page, diagnostics }, testInfo) => {
    void diagnostics;
    const desktop = (testInfo.project.use.viewport?.width ?? 0) >= 1024;

    const map = await openExplorerWithBoundaries(page);

    // Desktop floats the glass card over the map's start side; below lg the
    // SAME content rides the bottom sheet (a real dialog). Never both.
    const target = desktop ? page.getByTestId('area-card-float') : page.getByRole('dialog');

    await clickPolygonUntilSelected(page, map, target);

    // Identity from the area's own trilingual fields, never a raw key.
    await expect(target).toContainText(AREA_NAME.ckb);

    // The REAL seeded figure through the same MarketMetricCard contract the
    // homepage card renders — currency attached, nothing fabricated.
    await expect(target.getByTestId('area-card-prices')).toContainText('1,250');
    await expect(target.getByTestId('area-card-prices')).toContainText('USD');

    // The Phase 2 service summary as live controls: education + health, one
    // seeded place each. Groups with zero places never render.
    await expect(target.getByTestId('area-card-services').getByRole('button')).toHaveCount(2);

    // The one action every state keeps: into the full area profile.
    await expect(target.getByTestId('area-card-view-full')).toHaveAttribute('href', '/areas/browser-ankawa');

    // No raw translation keys anywhere on the card.
    await expect(target).not.toContainText('map.');
    await expect(target).not.toContainText('home.');

    await expectNoHorizontalOverflow(page);

    if (desktop) {
        await target.getByTestId('area-card-close').click();
        await expect(page.getByTestId('area-card-float')).toHaveCount(0);
    } else {
        // The sheet owns close below lg — Escape is one of its dismissals.
        await page.keyboard.press('Escape');
        await expect(page.getByRole('dialog')).toHaveCount(0);
    }
});

/* ------------------------------------------------------------- locale pass */

for (const locale of LOCALES) {
    test(`the card identity and profile route localize [${locale.code}]`, async ({ page, diagnostics }, testInfo) => {
        testInfo.skip(
            testInfo.project.name !== 'desktop-1440x900',
            'the locale pass runs once, on desktop-1440x900 only',
        );
        void diagnostics;

        const map = await openExplorerWithBoundaries(page, locale.prefix, AREAS_CHIP[locale.code]);
        const float = page.getByTestId('area-card-float');

        await clickPolygonUntilSelected(page, map, float);

        await expect(float.getByTestId('area-card-name')).toHaveText(AREA_NAME[locale.code]);
        await expect(float.getByTestId('area-card-view-full'))
            .toHaveAttribute('href', `${locale.prefix}/areas/browser-ankawa`);
    });
}

/* --------------------------------------------------------- click priority */

test('a project marker click is never stolen by the polygon beneath it', async ({ page, diagnostics }, testInfo) => {
    testInfo.skip(
        testInfo.project.name !== 'desktop-1440x900',
        'the priority contract runs once, on desktop-1440x900 only',
    );
    void diagnostics;

    const map = await openExplorerWithBoundaries(page);
    const box = await map.boundingBox();
    expect(box).not.toBeNull();

    // Let the boundary source paint before proving a negative on it.
    await page.waitForTimeout(1_000);

    /*
     * The seeded browser-area-project dot sits INSIDE the ring. Its click
     * must fall through the adapter's priority order — marker before
     * polygon — and select nothing, exactly as before Phase 3.
     */
    await map.click({
        position: { x: box!.width / 2 + MARKER_PIXEL.dx, y: box!.height / 2 + MARKER_PIXEL.dy },
    });
    await page.waitForTimeout(1_500);
    await expect(page.getByTestId('area-card-float')).toHaveCount(0);

    // The same session CAN select — the polygon pixel a clear margin away
    // opens the card, so the negative above measured priority, not a
    // broken feature.
    await clickPolygonUntilSelected(page, map, page.getByTestId('area-card-float'));
});

/* ------------------------------------------------------- list ↔ map sync */

test('an area list row selects in place — the same canonical selection as the polygon', async ({ page, diagnostics }, testInfo) => {
    testInfo.skip(
        testInfo.project.name !== 'desktop-1440x900' && testInfo.project.name !== 'mobile-360x800',
        'the list entry path runs on desktop-1440x900 plus a 360x800 sheet pass',
    );
    void diagnostics;
    const width = testInfo.project.use.viewport?.width ?? 0;

    await openExplorerWithBoundaries(page);

    // Below md the list is the second tab — a peer view, not a fallback.
    if (width < 768) {
        await page.getByRole('tab').nth(1).click();
    }

    const row = page.getByTestId('area-row').filter({ hasText: AREA_NAME.ckb }).first();
    await expect(row).toBeVisible();
    await row.click();

    if (width >= 1024) {
        const float = page.getByTestId('area-card-float');
        await expect(float).toBeVisible();
        await expect(float.getByTestId('area-card-name')).toHaveText(AREA_NAME.ckb);
        // The row itself states the selection — list and map agree.
        await expect(row).toHaveAttribute('aria-pressed', 'true');
    } else {
        await expect(page.getByRole('dialog')).toContainText(AREA_NAME.ckb);
    }
});

/* -------------------------------------------------- empty-map click clears */

test('an empty-map click clears the selection and nothing else', async ({ page, diagnostics }, testInfo) => {
    testInfo.skip(
        testInfo.project.name !== 'desktop-1440x900',
        'the dismissal contract runs once, on desktop-1440x900 only',
    );
    void diagnostics;

    const map = await openExplorerWithBoundaries(page);
    const float = page.getByTestId('area-card-float');

    await clickPolygonUntilSelected(page, map, float);

    // Selection fits the camera once (start-side padding for the card);
    // let that single flight land before measuring pixels again.
    await page.waitForTimeout(1_500);

    /*
     * Bottom-centre is bare map in BOTH directions after the fit: the ring
     * lands inside the padded region (start-side 396px for the card), so
     * the bottom band under it is polygon-free, and the fixture markers all
     * resolve off-canvas or well away from this pixel.
     */
    const box = await map.boundingBox();
    await map.click({ position: { x: box!.width / 2, y: box!.height - 25 } });

    await expect(float).toHaveCount(0);

    // The layers the visitor chose are untouched — only the selection went.
    await expect(page.getByRole('button', { name: AREAS_CHIP.ckb, exact: true }))
        .toHaveAttribute('aria-pressed', 'true');
});

/* ------------------------------------------------------------ live location */

for (const project of ['desktop-1440x900', 'mobile-390x844']) {
    test(`My Location resolves the containing area through the existing endpoint [${project}]`, async ({ page, diagnostics }, testInfo) => {
        testInfo.skip(testInfo.project.name !== project, `this pass runs on ${project}`);
        void diagnostics;

        await armGeolocationProbe(page);
        await page.context().grantPermissions(['geolocation']);
        await page.context().setGeolocation(INSIDE);

        // Deliberately WITHOUT the areas layer: live location must select
        // the Area even when no polygon is loaded — identity over geometry.
        await serveDeterministicStyle(page);
        await page.goto('/map', { waitUntil: 'domcontentloaded' });
        await expect(page.locator('.maplibregl-canvas')).toBeVisible({ timeout: 20_000 });

        // Nothing asked for a location on load (§7) — measured, not assumed.
        expect(await geolocationRequests(page), 'geolocation requests before any click').toBe(0);

        const resolve = page.waitForResponse((response) => response.url().includes('/location/resolve'));
        await page.getByTestId('use-my-location').click();

        // The coordinates go ONCE to the existing endpoint — the same
        // resolver, contract and limiter as the homepage card.
        const response = await resolve;
        expect(new URL(response.url()).searchParams.has('lat')).toBe(true);
        expect(response.status()).toBe(200);
        expect(await geolocationRequests(page), 'geolocation requests after the click').toBe(1);

        const desktop = (testInfo.project.use.viewport?.width ?? 0) >= 1024;
        const target = desktop ? page.getByTestId('area-card-float') : page.getByRole('dialog');

        await expect(target).toBeVisible();
        await expect(target).toContainText(AREA_NAME.ckb);
        await expect(target.getByTestId('area-card-prices')).toContainText('1,250');
    });
}

test('denied location permission leaves the map fully usable', async ({ page, diagnostics }, testInfo) => {
    testInfo.skip(
        testInfo.project.name !== 'desktop-1440x900',
        'the denial path runs once, on desktop-1440x900 only',
    );
    void diagnostics;

    await armGeolocationProbe(page);
    // Granting an empty set rejects everything else — a real denial, not
    // the "prompt" state that would hang a headless browser.
    await page.context().grantPermissions([]);

    await serveDeterministicStyle(page);
    await page.goto('/map', { waitUntil: 'domcontentloaded' });
    await expect(page.locator('.maplibregl-canvas')).toBeVisible({ timeout: 20_000 });

    await page.getByTestId('use-my-location').click();

    // The existing calm notice — never an alert(), never a dead end.
    await expect(page.getByText('دەستپێگەیشتن بە شوێن ڕەتکرایەوە')).toBeVisible();
    await expect(page.getByTestId('area-card-float')).toHaveCount(0);

    // The map stays completely usable manually: the canvas is live and an
    // ordinary click runs without a fault (the diagnostics gate fails this
    // test on any console or page error).
    const map = page.locator('[role="application"]').first();
    const box = await map.boundingBox();
    await map.click({ position: { x: box!.width / 2, y: box!.height / 2 } });
    await expect(page.locator('.maplibregl-canvas')).toBeVisible();
});

test('a location outside every polygon answers honestly, with no nearest-area guess', async ({ page, diagnostics }, testInfo) => {
    testInfo.skip(
        testInfo.project.name !== 'desktop-1440x900',
        'the outside-coverage path runs once, on desktop-1440x900 only',
    );
    void diagnostics;

    await armGeolocationProbe(page);
    await page.context().grantPermissions(['geolocation']);
    await page.context().setGeolocation(OUTSIDE);

    await serveDeterministicStyle(page);
    await page.goto('/map', { waitUntil: 'domcontentloaded' });
    await expect(page.locator('.maplibregl-canvas')).toBeVisible({ timeout: 20_000 });

    await page.getByTestId('use-my-location').click();

    // A compact toast states it; no card opens, and the seeded area's name
    // is not offered as a nearest match.
    const notice = page.getByTestId('location-notice');
    await expect(notice).toBeVisible();
    await expect(notice).not.toContainText('home.location.');
    await expect(page.getByTestId('area-card-float')).toHaveCount(0);
    await expect(notice).not.toContainText(AREA_NAME.ckb);
});

/* ------------------------------------------------- services → POI layers */

test('activating a service group from the card enables that POI category on the map', async ({ page, diagnostics }, testInfo) => {
    testInfo.skip(
        testInfo.project.name !== 'desktop-1440x900',
        'the POI wiring runs once, on desktop-1440x900 only',
    );
    void diagnostics;

    const map = await openExplorerWithBoundaries(page);
    const float = page.getByTestId('area-card-float');

    await clickPolygonUntilSelected(page, map, float);

    // Education first (the product's fixed group order), containing exactly
    // the seeded school category.
    const placesFetch = page.waitForResponse((response) =>
        response.url().includes('/map/features')
        && decodeURIComponent(response.url()).includes('layers[]=places')
        && decodeURIComponent(response.url()).includes('categories[]=school'));

    await float.getByTestId('area-card-services').getByRole('button').first().click();

    expect((await placesFetch).ok()).toBe(true);

    // The group toggled the REAL layer and category controls — never a
    // second POI system: the places chip and the school chip both report
    // pressed, and the map itself never flinched.
    await expect(page.getByRole('button', { name: 'شوێنەکان', exact: true }))
        .toHaveAttribute('aria-pressed', 'true');
    await expect(page.getByRole('button', { name: 'قوتابخانە', exact: true }))
        .toHaveAttribute('aria-pressed', 'true');
    await expect(page.locator('.maplibregl-canvas')).toBeVisible();
});

/* ------------------------------------- the boundary gate never eats the area */

/**
 * Zoom persistence across the server's boundary gate (the production
 * "published area disappears on zoom out" report). The polygon payload is
 * legitimately empty below zoom 11 — points carry the label and the list row
 * there — so crossing the gate may take ONLY the polygon: the areas layer
 * stays requested, the row stays listed, the selection and its card stand,
 * and returning above the gate brings the polygon back without duplicating
 * the point row.
 */
test('the area survives the boundary zoom gate: row, selection and card persist below 11', async ({ page, diagnostics }, testInfo) => {
    testInfo.skip(
        testInfo.project.name !== 'desktop-1440x900',
        'the zoom choreography runs once, on desktop-1440x900',
    );
    void diagnostics;

    await openExplorerWithBoundaries(page);

    const row = page.getByTestId('area-row').filter({ hasText: AREA_NAME.ckb }).first();
    await expect(row).toBeVisible();
    await row.click();

    const float = page.getByTestId('area-card-float');
    await expect(float).toBeVisible();
    await expect(row).toHaveAttribute('aria-pressed', 'true');

    /*
     * Below the gate the areas layer must STILL be requested, its point row
     * must still arrive, and the polygon collection is honestly empty. Each
     * zoom click waits for its own settled fetch — a second click fired into
     * the first click's ease re-targets from a fractional mid-animation zoom
     * and lands the camera somewhere run-dependent.
     */
    const belowGate = page.waitForResponse((response) => {
        if (!response.url().includes('/map/features')) return false;
        if (!decodeURIComponent(response.url()).includes('layers[]=areas')) return false;

        const zoom = Number(new URL(response.url()).searchParams.get('zoom'));

        return Number.isFinite(zoom) && zoom < 11;
    });

    const zoomOut = page.locator('.maplibregl-ctrl-zoom-out');
    await zoomOut.click();

    const below = (await (await belowGate)
        .json()) as { areas: Array<{ slug: string }>; boundaries: { features: unknown[] } };

    const secondStep = page.waitForResponse((response) => response.url().includes('/map/features'));
    await zoomOut.click();
    expect((await secondStep).ok(), 'the deeper zoom-out step settles').toBe(true);

    expect(below.areas.length, 'the point row is served below the gate').toBeGreaterThanOrEqual(1);
    expect(below.boundaries.features, 'the polygon is honestly gated').toHaveLength(0);

    // Nothing was taken away with the polygon.
    await expect(row).toBeVisible();
    await expect(row).toHaveAttribute('aria-pressed', 'true');
    await expect(float).toBeVisible();

    // Back above the gate: the polygon returns, and the seeded area still
    // has exactly ONE list row — restored, not duplicated. Two settled
    // steps again, so the camera provably re-crosses the threshold.
    const zoomIn = page.locator('.maplibregl-ctrl-zoom-in');

    const returnStep = page.waitForResponse((response) => response.url().includes('/map/features'));
    await zoomIn.click();
    expect((await returnStep).ok(), 'the first zoom-in step settles').toBe(true);

    const aboveGate = page.waitForResponse((response) => {
        if (!response.url().includes('/map/features')) return false;
        if (!decodeURIComponent(response.url()).includes('layers[]=areas')) return false;

        const zoom = Number(new URL(response.url()).searchParams.get('zoom'));

        return Number.isFinite(zoom) && zoom >= 11;
    });
    await zoomIn.click();

    const above = (await (await aboveGate).json()) as { boundaries: { features: unknown[] } };

    expect(above.boundaries.features.length, 'the polygon returns above the gate').toBeGreaterThanOrEqual(1);

    await expect(page.getByTestId('area-row').filter({ hasText: AREA_NAME.ckb })).toHaveCount(1);
    await expect(row).toHaveAttribute('aria-pressed', 'true');
    await expect(float).toBeVisible();
});
