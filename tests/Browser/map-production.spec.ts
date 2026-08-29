import { execFileSync } from 'node:child_process';
import { test, expect, LOCALES, expectTouchTargets, type PageDiagnostics } from './support/harness';
import { fixtures, signInAdmin } from './support/fixtures';
import { colourDelta, decodePng, type Rgb } from './support/png';

/*
 * The one console line Chromium logs for each 429 THIS FILE deliberately
 * injects (measured verbatim against a route-fulfilled 429). Consumed
 * locally, exactly and countedly — the shared IGNORED_CONSOLE allowlist
 * stays untouched, so any other console error, Vue warning or page error
 * still fails the diagnostics teardown.
 */
const RATE_LIMIT_CONSOLE = 'Failed to load resource: the server responded with a status of 429 (Too Many Requests)';

function consumeRateLimitConsole(diagnostics: PageDiagnostics, expected: number): void {
    const matches = diagnostics.consoleErrors.filter((text) => text === RATE_LIMIT_CONSOLE);

    expect(matches, 'deliberately injected 429 console entries').toHaveLength(expected);

    for (let index = diagnostics.consoleErrors.length - 1; index >= 0; index--) {
        if (diagnostics.consoleErrors[index] === RATE_LIMIT_CONSOLE) {
            diagnostics.consoleErrors.splice(index, 1);
        }
    }
}

/*
 * The map endpoints sit behind real per-IP rate limiters (map-features
 * 60/min, map-search 30/min) that a serial browser run legitimately consumes:
 * by the time this request-heavy file runs, the invest suite has already
 * spent part of the same rolling window, and on a fast machine the selection
 * tests' silent enrichment fetches start answering 429 — the card keeps its
 * stale (trendless) search row and the assertion reads as a product failure.
 * global-setup already clears the cache once per RUN for exactly this reason
 * (the login limiter); this file resets the window once per project pass.
 * Same silent no-op when the target app is remote and artisan is not here.
 */
test.beforeAll(() => {
    try {
        execFileSync('php', ['artisan', 'cache:clear'], { stdio: 'ignore' });
    } catch {
        // Remote target or no artisan on this runner: nothing to clear.
    }
});

/*
 * The map surfaces with a WORKING style — the other half of the contract.
 *
 * invest.spec.ts pins the degraded path: the harness's hermetic network drops
 * every external request, so the CARTO tiles never arrive and the surface
 * must stay calm on its dark ground with the list alive; the explicit
 * style-abort test below pins the full provider-failure statement. This suite pins the path
 * production was shipping broken: the style LOADS, the map becomes ready, the
 * canvas is laid out by the MapLibre stylesheet, markers carry data for all
 * four trends, a marker click selects, and the admin picker built inside a
 * hidden tab recovers when the tab is revealed.
 *
 * DETERMINISM: the style these tests load is served from inside the test via
 * route interception — a background-only MapLibre style with no sources, no
 * sprite, no glyph server and no tiles, so nothing here ever contacts
 * any tile provider. CI must not depend on outside map infrastructure — the
 * fallback style document itself is same-origin since Map Phase 1 — and a
 * style that needs zero further requests is the strongest form of that rule. Marker icons render regardless, because the
 * adapter draws them onto a canvas at registration time.
 */

const STYLE_HOST = '**/map-styles/mulk-dark.json';

const DETERMINISTIC_STYLE = {
    version: 8,
    name: 'deterministic-e2e',
    // No glyphs entry on purpose: maplibre-gl v6 draws label glyphs locally
    // (TinySDF) when a style names none, so even cluster counts render
    // without a font server. Nothing in this style can cause a request.
    sources: {},
    layers: [{ id: 'bg', type: 'background', paint: { 'background-color': '#e8e6e1' } }],
};

/**
 * Serve the deterministic style for the URL the adapter falls back to when no
 * MAPLIBRE_STYLE_URL is configured (the CI condition). Registered inside the
 * test, so it outranks the harness's catch-all hermetic route — Playwright
 * consults the most recently added route first.
 */
async function serveDeterministicStyle(page: import('@playwright/test').Page): Promise<void> {
    await page.route(STYLE_HOST, (route) =>
        route.fulfill({
            status: 200,
            contentType: 'application/json',
            body: JSON.stringify(DETERMINISTIC_STYLE),
        }),
    );
}

/* ------------------------------------------------------ homepage live map */

for (const locale of LOCALES) {
    test(`homepage live map becomes a real ready map [${locale.code}]`, async ({ page }) => {
        await serveDeterministicStyle(page);
        await page.goto(`${locale.prefix}/`, { waitUntil: 'domcontentloaded' });

        await expect(page.locator('html')).toHaveAttribute('lang', locale.code);
        await expect(page.locator('html')).toHaveAttribute('dir', locale.direction);

        // Construction is deferred until the section scrolls into view —
        // that is the performance contract, so the test scrolls.
        const section = page.locator('[data-testid="home-project-map"]');
        await expect(section).toHaveCount(1);
        await section.scrollIntoViewIfNeeded();

        // A real MapLibre canvas, not a teaser card and not a spinner.
        const canvas = section.locator('.maplibregl-canvas');
        await expect(canvas).toBeVisible({ timeout: 20_000 });

        /*
         * THE CSS ROOT CAUSE, pinned where it broke: without
         * maplibre-gl.css in the built graph the canvas lays out as a
         * static-flow element and the map renders as a grey void. The
         * stylesheet positions it absolutely inside the map box.
         */
        expect(await canvas.evaluate((el) => getComputedStyle(el).position)).toBe('absolute');

        const box = await canvas.boundingBox();
        expect(box).not.toBeNull();
        expect(box!.width).toBeGreaterThan(200);
        expect(box!.height).toBeGreaterThan(200);

        // The way into the full experience is a real localized link.
        await expect(section.getByRole('link').first()).toBeVisible();
    });
}

/* ------------------------------------------------------------- /map ready */

test('/map leaves its loading state and becomes an interactive map', async ({ page }) => {
    await serveDeterministicStyle(page);
    await page.goto('/map', { waitUntil: 'domcontentloaded' });

    await expect(page.locator('.maplibregl-canvas')).toBeVisible({ timeout: 20_000 });

    // The loading veil must be GONE — not covering a working map, and not
    // spinning forever. Its absence is the "never stuck loading" contract.
    await expect(page.getByText('بارکردنی نەخشە…')).toHaveCount(0);
    // And the failure state must not be claimed when the provider worked.
    await expect(page.getByText('نەخشە بار نەبوو')).toHaveCount(0);
});

/*
 * Map Phase 2: the places layer rides the POI overlay. Toggling the layer
 * must request it from /map/features, surface the seeded category chips,
 * and leave the map alive — the setPois() path runs for real here, and the
 * console-error diagnostics fail the test on any runtime fault in it. A
 * category chip then narrows the next fetch. Pixel-level dot assertions are
 * deliberately absent: the overlay is zoom-gated adapter internals; the
 * contract under test is the flag→request→overlay wiring.
 */
test('/map places layer fetches POIs and filters by category without breaking the map', async ({ page }) => {
    await serveDeterministicStyle(page);
    await page.goto('/map', { waitUntil: 'domcontentloaded' });

    await expect(page.locator('.maplibregl-canvas')).toBeVisible({ timeout: 20_000 });

    const placesChip = page.getByRole('button', { name: 'شوێنەکان', exact: true });
    await expect(placesChip).toBeVisible();

    const placesFetch = page.waitForResponse((response) =>
        response.url().includes('/map/features')
        && decodeURIComponent(response.url()).includes('layers[]=places'));

    await placesChip.click();
    await expect(placesChip).toHaveAttribute('aria-pressed', 'true');

    const placesResponse = await placesFetch;
    const payload = await placesResponse.json();
    expect(Array.isArray(payload.places)).toBe(true);
    expect(payload.places.length).toBeGreaterThanOrEqual(2);

    // The seeded categories arrive as filter chips once the layer is live.
    const schoolChip = page.getByRole('button', { name: 'قوتابخانە', exact: true });
    await expect(schoolChip).toBeVisible();

    const filteredFetch = page.waitForResponse((response) =>
        response.url().includes('/map/features')
        && decodeURIComponent(response.url()).includes('categories[]=school'));

    await schoolChip.click();
    const filtered = await (await filteredFetch).json();

    // Server-side narrowing: only the school remains in the layer payload.
    expect(filtered.places.length).toBe(1);
    expect(filtered.places[0].category).toBe('school');

    // The map itself never flinched.
    await expect(page.locator('.maplibregl-canvas')).toBeVisible();
    await expect(page.getByText('نەخشە بار نەبوو')).toHaveCount(0);
});

/*
 * F-6 (map RC hardening): a throttled /map/features answer is the limiter
 * speaking, not the data failing. The page must say "wait" in its own
 * voice — never the error alert, never the empty state — while the stale
 * list, the active layers and the whole filter state stand; and switching
 * every layer off clears the throttle voice WITHOUT issuing a request.
 */
test('/map states throttling honestly, keeps the loaded data, and clears without a request', async ({ page, diagnostics }) => {
    await serveDeterministicStyle(page);
    await page.goto('/map', { waitUntil: 'domcontentloaded' });
    await expect(page.locator('.maplibregl-canvas')).toBeVisible({ timeout: 20_000 });

    // A real first load: the seeded tower is in the list. Let the initial
    // fetches settle completely so the injected-429 count stays exact.
    const towerRow = page.getByRole('link', { name: /بورجی وەبەرهێنانی تاقیکردنەوە/ });
    await expect(towerRow.first()).toBeVisible({ timeout: 15_000 });
    await page.waitForTimeout(600);

    // From here the limiter "answers" every viewport fetch.
    await page.route('**/map/features**', (route) =>
        route.fulfill({ status: 429, contentType: 'application/json', body: '{}' }));

    const areasChip = page.getByRole('button', { name: 'ناوچەکان', exact: true });
    const projectsChip = page.getByRole('button', { name: 'پڕۆژەکان', exact: true });

    await areasChip.click();

    // The throttle voice — and ONLY the throttle voice.
    await expect(page.getByTestId('map-rate-limited')).toBeVisible();
    await expect(page.getByTestId('map-rate-limited'))
        .toContainText('داواکاری زۆرە — کەمێک چاوەڕوان بە');
    await expect(page.getByTestId('data-retry')).toHaveCount(0);
    await expect(page.getByTestId('map-refetch-failed')).toHaveCount(0);

    // Everything already loaded stands: the stale list row and the layer
    // state the visitor just set.
    await expect(towerRow.first()).toBeVisible();
    await expect(areasChip).toHaveAttribute('aria-pressed', 'true');
    await expect(projectsChip).toHaveAttribute('aria-pressed', 'true');

    /*
     * Switching the layers off, one then the other: the first OFF still
     * has an active layer, so it fetches (and is throttled again); the
     * second OFF empties the set — the throttle state must clear WITHOUT
     * any request leaving the page.
     */
    let featureRequests = 0;
    page.on('request', (request) => {
        if (request.url().includes('/map/features')) featureRequests++;
    });

    await areasChip.click();
    await expect(page.getByTestId('map-rate-limited')).toBeVisible();
    expect(featureRequests).toBe(1);

    await projectsChip.click();
    await expect(page.getByTestId('map-rate-limited')).toHaveCount(0);
    expect(featureRequests).toBe(1);

    // The limiter relents; the next real fetch recovers the ordinary voice.
    await page.unroute('**/map/features**');

    const recovered = page.waitForResponse((response) =>
        response.url().includes('/map/features') && response.ok());
    await projectsChip.click();
    expect((await recovered).ok()).toBe(true);
    await expect(towerRow.first()).toBeVisible();
    await expect(page.getByTestId('map-rate-limited')).toHaveCount(0);

    // Exactly the two injected 429s (layer ON, then the first layer OFF —
    // the empty-set OFF issued no request) and nothing else.
    consumeRateLimitConsole(diagnostics, 2);
});

test('/map states a provider failure and keeps the list; no infinite loader', async ({ page }, testInfo) => {
    // The style is broken EXPLICITLY: the fallback style document is served
    // same-origin now (/map-styles/mulk-dark.json), so the hermetic harness
    // no longer breaks it for free — aborting it here reproduces exactly
    // what a dead or blocked provider looks like from a browser in Erbil.
    await page.route(STYLE_HOST, (route) => route.abort());
    await page.goto('/map', { waitUntil: 'domcontentloaded' });

    // The failure is stated in human words…
    await expect(page.getByText('نەخشە بار نەبوو').first()).toBeVisible({ timeout: 30_000 });
    // …and the loading veil is gone, because bounded readiness settled.
    await expect(page.getByText('بارکردنی نەخشە…')).toHaveCount(0);

    // The list fallback is independent of the dead map.
    const width = testInfo.project.use.viewport?.width ?? 0;
    if (width < 768) {
        await page.getByRole('tab').nth(1).click();
    }
    await expect(page.getByText('بورجی وەبەرهێنانی تاقیکردنەوە').first()).toBeVisible();
});

/* -------------------------------------------------- /invest trend markers */

test.describe('invest markers on a live map', () => {
    test.beforeEach(async ({ page }, testInfo) => {
        testInfo.skip(
            (testInfo.project.use.viewport?.width ?? 0) < 1024,
            'marker interaction runs on desktop; the mobile list path is covered by invest.spec.ts',
        );
    });

    test('the marker source carries all four trend semantics from persisted rows', async ({ page }) => {
        await serveDeterministicStyle(page);

        const features = page.waitForResponse(
            (response) => response.url().includes('/invest/features') && response.ok(),
        );
        await page.goto('/invest', { waitUntil: 'domcontentloaded' });

        await expect(page.locator('.maplibregl-canvas')).toBeVisible({ timeout: 20_000 });

        /*
         * The payload that becomes the marker layer, read from the page's own
         * request. Each trend claim below exists because the seeder persisted
         * REAL observations: up and down from two comparable USD rows, flat
         * from two identical rows, unknown from a single lone observation.
         * The icon each value maps to (green ↑ / red ↓ / amber — / neutral
         * dot) is pinned shape-by-shape in tests/js/trend.test.ts; this test
         * proves the live map is fed those values end to end.
         */
        const payload = (await (await features).json()) as {
            projects: Array<{ slug: string; trend: string; trend_percent: string | null }>;
        };
        const bySlug = new Map(payload.projects.map((row) => [row.slug, row]));

        expect(bySlug.get('browser-invest-tower')?.trend).toBe('up');
        expect(bySlug.get('browser-invest-tower')?.trend_percent).toBe('4.8');
        expect(bySlug.get('browser-invest-bazaar')?.trend).toBe('down');
        expect(bySlug.get('browser-invest-bazaar')?.trend_percent).toBe('-10.0');
        expect(bySlug.get('browser-invest-court')?.trend).toBe('flat');
        expect(bySlug.get('browser-invest-villa')?.trend).toBe('unknown');
        expect(bySlug.get('browser-invest-villa')?.trend_percent).toBeNull();
    });

    /** Select a fixture project through the search box and return its card. */
    async function selectProject(
        page: import('@playwright/test').Page,
        query: string,
        name: string,
    ): Promise<import('@playwright/test').Locator> {
        await page.locator('#invest-search').fill(query);
        const results = page.locator('#invest-search-results');
        await expect(results).toBeVisible();
        await results.getByRole('button').filter({ hasText: name }).first().click();

        const card = page.locator('[role="status"]').filter({ hasText: name });
        await expect(card).toBeVisible();

        return card;
    }

    test('selection cards speak each trend: direction glyph, signed percent, accessible text', async ({ page }) => {
        await serveDeterministicStyle(page);
        await page.goto('/invest', { waitUntil: 'domcontentloaded' });
        await expect(page.locator('.maplibregl-canvas')).toBeVisible({ timeout: 20_000 });

        // UP — upward glyph, percentage, and the words "price increased".
        let card = await selectProject(page, 'بورجی', 'بورجی وەبەرهێنانی تاقیکردنەوە');
        await expect(card.getByText('↑')).toBeVisible();
        await expect(card.getByText('4.8%')).toBeVisible();
        await expect(card.getByText('نرخ بەرزبووەوە')).toBeAttached();
        await card.getByRole('button', { name: 'داخستن' }).click();

        // DOWN — downward glyph and the words "price decreased".
        card = await selectProject(page, 'بازاڕی', 'بازاڕی وەبەرهێنانی تاقیکردنەوە');
        await expect(card.getByText('↓')).toBeVisible();
        await expect(card.getByText('-10.0%')).toBeVisible();
        await expect(card.getByText('نرخ دابەزی')).toBeAttached();
        await card.getByRole('button', { name: 'داخستن' }).click();

        // FLAT — horizontal glyph; a real claim from two real observations.
        card = await selectProject(page, 'کۆمەڵگەی', 'کۆمەڵگەی نیشتەجێبوونی تاقیکردنەوە');
        await expect(card.getByText('→')).toBeVisible();
        await expect(card.getByText('نرخ جێگیرە')).toBeAttached();
        await card.getByRole('button', { name: 'داخستن' }).click();

        // UNKNOWN — no glyph, no percentage, and a STATED absence for
        // screen readers. Pretending a trend here is the forbidden move.
        card = await selectProject(page, 'ڤیلاکانی', 'ڤیلاکانی تاقیکردنەوە');
        await expect(card.getByText('↑')).toHaveCount(0);
        await expect(card.getByText('↓')).toHaveCount(0);
        await expect(card.getByText('→')).toHaveCount(0);
        await expect(card.getByText('%')).toHaveCount(0);
        await expect(card.getByText('ئاراستەی نرخ بەردەست نییە')).toBeAttached();
    });

    test('clicking the marker itself on the canvas selects the project', async ({ page }) => {
        await serveDeterministicStyle(page);
        await page.goto('/invest', { waitUntil: 'domcontentloaded' });
        await expect(page.locator('.maplibregl-canvas')).toBeVisible({ timeout: 20_000 });

        /*
         * Put a known marker at a known pixel: selecting from search flies
         * the camera to the project (zoom 15), so after the flight the
         * marker sits at the container's centre. Close the card, click that
         * pixel on the CANVAS, and the click must resolve through the
         * symbol layer back to the same project — the full marker-click
         * path, not the list shortcut.
         */
        const card = await selectProject(page, 'بورجی', 'بورجی وەبەرهێنانی تاقیکردنەوە');
        await page.waitForTimeout(3_000); // let flyTo land
        await card.getByRole('button', { name: 'داخستن' }).click();
        await expect(card).toHaveCount(0);

        const map = page.locator('[role="application"]').first();
        const box = await map.boundingBox();
        expect(box).not.toBeNull();

        await expect(async () => {
            await map.click({ position: { x: box!.width / 2, y: box!.height / 2 } });
            await expect(
                page.locator('[role="status"]').filter({ hasText: 'بورجی وەبەرهێنانی تاقیکردنەوە' }),
            ).toBeVisible({ timeout: 2_000 });
        }).toPass({ timeout: 20_000 });
    });

    test('clicking the project NAME at street zoom selects it and never clears a selection', async ({ page }) => {
        await serveDeterministicStyle(page);
        await page.goto('/invest', { waitUntil: 'domcontentloaded' });
        await expect(page.locator('.maplibregl-canvas')).toBeVisible({ timeout: 20_000 });

        /*
         * L2: the point-names layer joins at zoom >= 13; selecting through
         * search flies the camera to the project at zoom 15, so the NAME
         * renders above the dot — text-anchor bottom with a -1.2em offset at
         * text-size 11 puts the glyph band roughly 13-24px above the point,
         * clear of the 22px trend icon. Close the card and click the NAME,
         * not the dot. Pre-fix, the name was absent from every interaction
         * registration: this click fell through to the surface handler and
         * CLEARED selection, so the card never appears and this times out.
         */
        const card = await selectProject(page, 'بورجی', 'بورجی وەبەرهێنانی تاقیکردنەوە');
        await page.waitForTimeout(3_000); // let flyTo land
        await card.getByRole('button', { name: 'داخستن' }).click();
        await expect(card).toHaveCount(0);

        const map = page.locator('[role="application"]').first();
        const box = await map.boundingBox();
        expect(box).not.toBeNull();
        const name = { x: box!.width / 2, y: box!.height / 2 - 18 };

        await expect(async () => {
            await map.click({ position: name });
            await expect(
                page.locator('[role="status"]').filter({ hasText: 'بورجی وەبەرهێنانی تاقیکردنەوە' }),
            ).toBeVisible({ timeout: 2_000 });
        }).toPass({ timeout: 20_000 });

        // A name click is a marker click: with the card already open it may
        // re-select, but it must never fall through and CLEAR the selection.
        await map.click({ position: name });
        await expect(
            page.locator('[role="status"]').filter({ hasText: 'بورجی وەبەرهێنانی تاقیکردنەوە' }),
        ).toBeVisible();

        // And the cursor states the affordance over the name.
        await map.hover({ position: name });
        expect(
            await page.locator('.maplibregl-canvas').first().evaluate((el) => getComputedStyle(el).cursor),
        ).toBe('pointer');
    });
});

/* ------------------------------------------------------ admin map picker */

test.describe('admin project location picker', () => {
    test.beforeEach(async ({ page }, testInfo) => {
        testInfo.skip(
            testInfo.project.name !== 'desktop-1440x900',
            'admin flow runs once, on desktop-1440x900 only',
        );
        await signInAdmin(page);
    });

    test('the hidden Location tab reveals a working map that click-places the point', async ({ page }) => {
        await page.goto('/admin/projects/create', { waitUntil: 'domcontentloaded' });

        /*
         * The form opens on the identity tab, so the picker is constructed
         * inside a v-show:false box — the exact zero-size condition that
         * used to ship a permanently blank picker. Revealing the tab must
         * yield a full-size canvas via the adapter's ResizeObserver, with
         * no rebuild.
         */
        await page.getByRole('button', { name: 'شوێن' }).click();

        const picker = page.locator('[role="application"]');
        await expect(picker).toBeVisible();

        const canvas = picker.locator('.maplibregl-canvas');
        await expect(canvas).toBeVisible({ timeout: 20_000 });

        const box = await canvas.boundingBox();
        expect(box).not.toBeNull();
        expect(box!.width).toBeGreaterThan(300);
        expect(box!.height).toBeGreaterThan(300);

        // No coordinates yet: no pin, and honest empty fields.
        const latitude = page.getByLabel('پانی', { exact: true });
        const longitude = page.getByLabel('درێژی', { exact: true });
        await expect(latitude).toHaveValue('');
        await expect(longitude).toHaveValue('');

        // Click to place. The picker opens centred on Erbil, so the centre
        // pixel must produce Erbil-plausible coordinates in the fields.
        const pickerBox = await picker.boundingBox();
        await picker.click({
            position: { x: pickerBox!.width / 2, y: pickerBox!.height / 2 },
        });

        await expect(latitude).toHaveValue(/^36\./);
        await expect(longitude).toHaveValue(/^4[34]\./);
        await expect(page.locator('.maplibregl-marker')).toHaveCount(1);

        // Manual coordinate editing stays authoritative: typing new values
        // must move the pin rather than being overwritten by it.
        await latitude.fill('36.21');
        await longitude.fill('44.02');
        await expect(page.locator('.maplibregl-marker')).toHaveCount(1);
        await expect(latitude).toHaveValue('36.21');
    });

    test('editing an existing project restores its persisted point', async ({ page }) => {
        // The row's id, taken from the same payload the public map serves —
        // the fixtures file stores no ids because the seeder does not know
        // them.
        const response = await page.request.get(
            '/invest/features?west=43.7&south=35.9&east=44.4&north=36.5&zoom=12',
        );
        const payload = (await response.json()) as { projects: Array<{ id: number; slug: string }> };
        const tower = payload.projects.find((row) => row.slug === 'browser-invest-tower');
        expect(tower).toBeDefined();

        await page.goto(`/admin/projects/${tower!.id}/edit`, { waitUntil: 'domcontentloaded' });
        await page.getByRole('button', { name: 'شوێن' }).click();

        await expect(page.locator('.maplibregl-canvas')).toBeVisible({ timeout: 20_000 });

        // The persisted coordinates are in the fields — and on the map.
        await expect(page.getByLabel('پانی', { exact: true })).toHaveValue(/^36\.195/);
        await expect(page.getByLabel('درێژی', { exact: true })).toHaveValue(/^44\.015/);
        await expect(page.locator('.maplibregl-marker')).toHaveCount(1);
    });
});

/* --------------------------------- admin picker geometry fidelity (L1) */

/*
 * Canonical L1: the simple picker edits exactly one hole-free ring, but the
 * server accepts Polygon-with-holes and MultiPolygon at every door. Such a
 * boundary used to render as nothing (or as its exterior alone) and a
 * redraw or clear then silently replaced it with a downgrade. The contract
 * pinned here: complex geometry renders in FULL (holes genuinely punched
 * out — proven by pixel, not by visibility), the limitation is stated,
 * replacing or clearing demands explicit intent plus confirmation,
 * cancelling emits nothing, and the simple single-ring flow is unchanged.
 */
test.describe('admin picker geometry fidelity', () => {
    const HOLED = 'POLYGON((44.0000000 36.1800000, 44.0200000 36.1800000, '
        + '44.0200000 36.2000000, 44.0000000 36.2000000, 44.0000000 36.1800000), '
        + '(44.0050000 36.1850000, 44.0100000 36.1850000, 44.0100000 36.1900000, '
        + '44.0050000 36.1900000, 44.0050000 36.1850000))';
    const MULTI = 'MULTIPOLYGON(((44.0000000 36.1800000, 44.0200000 36.1800000, '
        + '44.0200000 36.2000000, 44.0000000 36.2000000, 44.0000000 36.1800000)), '
        + '((44.0300000 36.2100000, 44.0500000 36.2100000, 44.0500000 36.2300000, '
        + '44.0300000 36.2300000, 44.0300000 36.2100000), '
        + '(44.0350000 36.2150000, 44.0400000 36.2150000, 44.0400000 36.2200000, '
        + '44.0350000 36.2200000, 44.0350000 36.2150000)))';

    /**
     * Create an area through the real admin form and land on its edit page,
     * where the picker constructs WITH the stored boundary and fits the
     * camera to it — the deterministic pose the pixel samples assume.
     */
    async function createAreaWithBoundary(
        page: import('@playwright/test').Page,
        slug: string,
        wkt: string,
    ): Promise<void> {
        await page.goto('/admin/areas/create', { waitUntil: 'domcontentloaded' });

        await page.getByLabel('ناو (کوردی)').fill(`ناوچەی ${slug}`);
        await page.getByLabel('ناسنامەی بەستەر (slug)').fill(slug);
        await page.getByLabel('پانی', { exact: true }).fill('36.18');
        await page.getByLabel('درێژی', { exact: true }).fill('44.00');
        await page.getByLabel('سنوور (WKT)').fill(wkt);

        await page.getByRole('button', { name: 'دروستکردنی ناوچە' }).click();
        await page.waitForURL(/\/admin\/areas\/\d+\/edit/, { timeout: 15_000 });

        /*
         * Fresh load of the edit page. The create→edit redirect is an
         * Inertia visit onto the SAME page component, so Vue patches the
         * form in place and the already-mounted picker keeps its create-page
         * camera. A reload is the pose the pixel maths assume — an editor
         * opening the record — where build() fits the camera to the stored
         * geometry at zoom 13.
         */
        await page.reload({ waitUntil: 'domcontentloaded' });
        await expect(page.locator('.maplibregl-canvas')).toBeVisible({ timeout: 20_000 });
    }

    /**
     * Screenshot the picker canvas and sample pixels at offsets from its
     * centre. MapLibre renders at zoom 13 over the geometry's bounds centre,
     * 512-pixel tiles: 11650.8 px/deg lng, / cos(lat) for latitude.
     */
    async function sampleCanvas(
        page: import('@playwright/test').Page,
        offsets: Array<{ dx: number; dy: number }>,
    ): Promise<Rgb[]> {
        const canvas = page.locator('.maplibregl-canvas');
        const png = decodePng(await canvas.screenshot());

        return offsets.map(({ dx, dy }) => png.pixelAt(png.width / 2 + dx, png.height / 2 + dy));
    }

    test.beforeEach(async ({ page }, testInfo) => {
        testInfo.skip(
            testInfo.project.name !== 'desktop-1440x900' && testInfo.project.name !== 'mobile-360x800',
            'the fidelity contract runs on desktop-1440x900, plus a 360x800 notice smoke',
        );
        await signInAdmin(page);
    });

    test('a polygon with a hole renders in full and survives cancelled destruction untouched', async ({ page }, testInfo) => {
        testInfo.skip(testInfo.project.name !== 'desktop-1440x900', 'pixel assertions run once, on desktop');

        // Unique per run: the disposable browser database persists across
        // local repeat runs, and the slug column is unique.
        await createAreaWithBoundary(page, `geometry-fidelity-holed-${Date.now()}`, HOLED);

        // The limitation is STATED, and the plain Draw affordance is gone —
        // read-only by default, never a silent path into replacement.
        await expect(page.getByTestId('boundary-complex-notice')).toBeVisible();
        await expect(page.getByRole('button', { name: 'کێشانی سنوور' })).toHaveCount(0);

        /*
         * The hole is genuinely punched out. Camera: bounds centre
         * (44.01, 36.19) at zoom 13. Hole centre sits 29px start-ward and
         * 36px down from the canvas centre and must match the background;
         * a point inside the ring but outside the hole must not.
         */
        await expect
            .poll(async () => {
                const [hole, fill, background] = await sampleCanvas(page, [
                    { dx: -29, dy: 36 },
                    { dx: 58, dy: -72 },
                    { dx: 150, dy: 0 },
                ]);

                return colourDelta(hole, background) <= 4 && colourDelta(fill, background) >= 8;
            }, { timeout: 20_000, message: 'the hole must render as background inside a filled exterior' })
            .toBe(true);

        const boundaryField = page.getByLabel('سنوور (WKT)');

        // Cancelled REPLACE: nothing may change, nothing may be emitted.
        await page.getByTestId('boundary-replace').click();
        await expect(page.getByTestId('boundary-confirm')).toBeVisible();
        await page.getByTestId('boundary-cancel').click();
        await expect(boundaryField).toHaveValue(HOLED);
        await expect(page.getByTestId('boundary-replace')).toBeVisible();

        // Cancelled CLEAR: identical rule.
        await page.getByTestId('boundary-clear-complex').click();
        await expect(page.getByTestId('boundary-confirm')).toBeVisible();
        await page.getByTestId('boundary-cancel').click();
        await expect(boundaryField).toHaveValue(HOLED);

        // Confirmed CLEAR is allowed — explicitly, never silently.
        await page.getByTestId('boundary-clear-complex').click();
        await page.getByTestId('boundary-confirm').click();
        await expect(boundaryField).toHaveValue('');
        await expect(page.getByTestId('boundary-complex-notice')).toHaveCount(0);

        // Confirmed REPLACE draws a new simple ring — an explicit decision.
        await boundaryField.fill(HOLED);
        await expect(page.getByTestId('boundary-complex-notice')).toBeVisible();
        await page.getByTestId('boundary-replace').click();
        await page.getByTestId('boundary-confirm').click();

        const picker = page.locator('[role="application"]');
        const box = await picker.boundingBox();
        await picker.click({ position: { x: box!.width * 0.35, y: box!.height * 0.35 } });
        await picker.click({ position: { x: box!.width * 0.65, y: box!.height * 0.35 } });
        await picker.click({ position: { x: box!.width * 0.5, y: box!.height * 0.65 } });
        await page.getByRole('button', { name: 'تەواوکردن' }).click();

        await expect(boundaryField).toHaveValue(/^POLYGON\(\([-0-9. ,]+\)\)$/);
    });

    test('a multipolygon renders every part with its holes intact', async ({ page }, testInfo) => {
        testInfo.skip(testInfo.project.name !== 'desktop-1440x900', 'pixel assertions run once, on desktop');

        await createAreaWithBoundary(page, `geometry-fidelity-multi-${Date.now()}`, MULTI);

        await expect(page.getByTestId('boundary-complex-notice')).toBeVisible();

        /*
         * Camera: bounds centre (44.025, 36.205) at zoom 13. Both parts must
         * carry fill, the gap between them must not (two parts, not one
         * merged blob), and the second part's hole must read as background.
         */
        await expect
            .poll(async () => {
                const [partOne, gap, partTwo, hole] = await sampleCanvas(page, [
                    { dx: -175, dy: 101 },
                    { dx: 0, dy: 0 },
                    { dx: 87, dy: -108 },
                    { dx: 146, dy: -180 },
                ]);

                return colourDelta(partOne, gap) >= 8
                    && colourDelta(partTwo, gap) >= 8
                    && colourDelta(hole, gap) <= 4;
            }, { timeout: 20_000, message: 'both parts filled, the gap and the hole background' })
            .toBe(true);
    });

    test('the simple single-ring flow is unchanged: draw, finish, clear — no notice, no confirmation', async ({ page }, testInfo) => {
        testInfo.skip(testInfo.project.name !== 'desktop-1440x900', 'the parity check runs once, on desktop');

        await page.goto('/admin/areas/create', { waitUntil: 'domcontentloaded' });
        await expect(page.locator('.maplibregl-canvas')).toBeVisible({ timeout: 20_000 });

        const boundaryField = page.getByLabel('سنوور (WKT)');
        const picker = page.locator('[role="application"]');
        const box = await picker.boundingBox();

        await page.getByRole('button', { name: 'کێشانی سنوور' }).click();
        await picker.click({ position: { x: box!.width * 0.35, y: box!.height * 0.35 } });
        await picker.click({ position: { x: box!.width * 0.65, y: box!.height * 0.35 } });
        await picker.click({ position: { x: box!.width * 0.5, y: box!.height * 0.65 } });
        await page.getByRole('button', { name: 'تەواوکردن' }).click();

        await expect(boundaryField).toHaveValue(/^POLYGON\(\(/);
        await expect(page.getByTestId('boundary-complex-notice')).toHaveCount(0);

        // Clear stays immediate for a simple ring — no confirmation step.
        await page.getByRole('button', { name: 'سڕینەوەی سنوور' }).click();
        await expect(boundaryField).toHaveValue('');
        await expect(page.getByTestId('boundary-confirm')).toHaveCount(0);
    });

    test('the limitation notice holds the 360x800 layout', async ({ page }, testInfo) => {
        testInfo.skip(testInfo.project.name !== 'mobile-360x800', 'the mobile smoke runs once, at 360x800');

        await page.goto('/admin/areas/create', { waitUntil: 'domcontentloaded' });
        await page.getByLabel('سنوور (WKT)').fill(HOLED);

        await expect(page.getByTestId('boundary-complex-notice')).toBeVisible();
        await expect(page.getByTestId('boundary-replace')).toBeVisible();

        /*
         * The whole-page overflow probe cannot run here: focusing ANY input
         * on this admin page at 360 grows the document's reported
         * scrollWidth by a sticky 111px BEFORE any of this feature's UI
         * exists (measured: goto 360 → bare focus() 471; removing the
         * notice, the map, and the entire form leaves 471) — a pre-existing
         * admin-layout artifact recorded in the Phase 7 backlog, not this
         * feature's layout. What this feature owns is asserted directly:
         * the notice and both action rows must fit the 360px viewport, in
         * both the intent and the confirmation state.
         */
        const fits = async (testId: string): Promise<void> => {
            // Layout-space edges: viewport rects shift with the sticky
            // artifact scroll, so add scrollLeft back (negative in RTL).
            const edges = await page.getByTestId(testId).evaluate((element) => {
                const rect = element.getBoundingClientRect();
                const scroll = document.documentElement.scrollLeft;
                return { left: rect.left + scroll, right: rect.right + scroll };
            });
            expect(edges.left, `${testId} start edge`).toBeGreaterThanOrEqual(-1);
            expect(edges.right, `${testId} end edge`).toBeLessThanOrEqual(361);
        };

        await fits('boundary-complex-notice');
        await fits('boundary-replace');
        await fits('boundary-clear-complex');

        /*
         * The confirmation state's layout, reached by keyboard: pointer
         * hit-testing at 360 is skewed by the same pre-existing scroll
         * artifact (the canvas and the notice text intercept the shifted
         * click point), and the pointer flow is already pinned on desktop.
         * Keyboard activation is hit-test-free and a real modality.
         */
        await page.getByTestId('boundary-replace').focus();
        await page.keyboard.press('Enter');
        await expect(page.getByTestId('boundary-confirm')).toBeVisible();
        await fits('boundary-complex-notice');
        await fits('boundary-confirm');
        await fits('boundary-cancel');

        await page.getByTestId('boundary-cancel').focus();
        await page.keyboard.press('Enter');
        await expect(page.getByTestId('boundary-replace')).toBeVisible();
        await expect(page.getByLabel('سنوور (WKT)')).toHaveValue(HOLED);
    });
});

/* --------------------------------- construction that outlives its page */

/*
 * M1: an adapter whose construction finishes AFTER its component unmounted
 * must be destroyed, never leaked. The race is reproduced deterministically
 * by gating the lazy maplibre-gl chunk request — its URL resolved from the
 * Vite manifest at runtime, never a hardcoded hash — so a page can be left
 * mid-construction by an Inertia navigation.
 *
 * The resource signal is WebGL context accounting: MapLibre's remove()
 * loses the canvas context via WEBGL_lose_context, so once the stale
 * construction resolves, created === lost holds iff the consumer's disposal
 * guard destroyed it. A leaked adapter's canvas is DETACHED from the
 * document, which is exactly why a DOM count cannot see the leak and this
 * counter can. Deliberately coupled to MapLibre's teardown internals; a
 * maplibre-gl upgrade that stops losing the context on remove() must update
 * this instrumentation too.
 */

async function instrumentWebglAccounting(page: import('@playwright/test').Page): Promise<void> {
    await page.addInitScript(() => {
        const counters = window as unknown as { __webglCreated: number; __webglLost: number };
        counters.__webglCreated = 0;
        counters.__webglLost = 0;

        const original = HTMLCanvasElement.prototype.getContext;
        HTMLCanvasElement.prototype.getContext = function (
            this: HTMLCanvasElement & { __webglCounted?: boolean },
            type: string,
            ...args: unknown[]
        ) {
            const context = original.call(this, type, ...args as never[]);

            // One canvas backs one map; count each WebGL canvas once even if
            // the library probes the same canvas for webgl2 and webgl.
            if (context !== null && (type === 'webgl' || type === 'webgl2') && !this.__webglCounted) {
                this.__webglCounted = true;
                counters.__webglCreated += 1;
                this.addEventListener('webglcontextlost', () => {
                    counters.__webglLost += 1;
                }, { once: true });
            }

            return context;
        } as typeof HTMLCanvasElement.prototype.getContext;
    });
}

/**
 * Hold the lazy maplibre-gl chunk behind a gate the test opens explicitly.
 * The chunk's hashed filename is read from the build manifest at runtime so
 * this never encodes a particular emitted name.
 */
async function gateMaplibreChunk(page: import('@playwright/test').Page): Promise<{
    release: () => void;
    requested: Promise<unknown>;
}> {
    const manifest = await page.request.get('/build/manifest.json')
        .then((response) => response.json()) as Record<string, { file: string }>;

    const entry = Object.entries(manifest).find(([source, chunk]) =>
        source.includes('node_modules/maplibre-gl/') && !chunk.file.includes('worker'));
    expect(entry, 'the maplibre-gl dynamic chunk must exist in the Vite manifest').toBeDefined();

    const pattern = `**/${entry![1].file}`;
    let release!: () => void;
    const gate = new Promise<void>((resolve) => {
        release = resolve;
    });

    await page.route(pattern, async (route) => {
        await gate;
        await route.continue();
    });

    return { release, requested: page.waitForRequest(pattern) };
}

test.describe('adapter construction outliving its page', () => {
    test.beforeEach(async ({}, testInfo) => {
        testInfo.skip(
            testInfo.project.name !== 'desktop-1440x900',
            'the lifecycle race runs once, on desktop-1440x900 only',
        );
    });

    test('navigating away mid-construction destroys the late adapter; returning yields one healthy map', async ({ page }) => {
        await instrumentWebglAccounting(page);
        await serveDeterministicStyle(page);
        const { release, requested } = await gateMaplibreChunk(page);

        // Start somewhere with no map, then enter /invest through a real
        // Inertia link so leaving it later is a genuine SPA unmount.
        await page.goto('/projects', { waitUntil: 'domcontentloaded' });
        await Promise.all([
            requested,
            page.locator('a[href$="/invest"]').first().click(),
        ]);

        // The chunk is in flight and gated: leave before construction ends.
        await page.locator('a[href$="/projects"]').first().click();
        await expect(page.locator('.maplibregl-canvas')).toHaveCount(0);

        release();

        /*
         * The stale construction now resolves against an unmounted page. The
         * disposal guard must destroy it: every created WebGL context ends
         * lost. Pre-fix, the leaked map keeps its context and this times out.
         */
        await page.waitForFunction(() => {
            const counters = window as unknown as { __webglCreated: number; __webglLost: number };
            return counters.__webglCreated > 0 && counters.__webglCreated === counters.__webglLost;
        }, undefined, { timeout: 15_000 });

        // Returning builds a fresh, healthy map — exactly one.
        await page.locator('a[href$="/invest"]').first().click();
        const canvas = page.locator('.maplibregl-canvas');
        await expect(canvas).toBeVisible({ timeout: 20_000 });
        await expect(canvas).toHaveCount(1);
    });

    test('the homepage lazy map is destroyed when navigation wins its construction race', async ({ page }) => {
        await instrumentWebglAccounting(page);
        await serveDeterministicStyle(page);
        const { release, requested } = await gateMaplibreChunk(page);

        await page.goto('/', { waitUntil: 'domcontentloaded' });

        // The IntersectionObserver is the trigger: scroll the section into
        // view, catch the gated chunk request, and leave immediately.
        await Promise.all([
            requested,
            page.locator('[data-testid="home-project-map"]').scrollIntoViewIfNeeded(),
        ]);
        await page.locator('a[href$="/projects"]').first().click();

        release();

        await page.waitForFunction(() => {
            const counters = window as unknown as { __webglCreated: number; __webglLost: number };
            return counters.__webglCreated > 0 && counters.__webglCreated === counters.__webglLost;
        }, undefined, { timeout: 15_000 });
    });
});

/* --------------------------------------- wizard picker provider failure */

test.describe('wizard location picker provider failure', () => {
    test.beforeEach(async ({ page }, testInfo) => {
        testInfo.skip(
            testInfo.project.name !== 'desktop-1440x900',
            'admin flow runs once, on desktop-1440x900 only',
        );
        await signInAdmin(page);
    });

    test('a failed provider states failure with Retry, and Retry rebuilds a working map without losing typed coordinates', async ({ page }) => {
        /*
         * Deterministic failure through the SAME seam the rest of this suite
         * uses — the style request, aborted. The adapter fails before load
         * and ready() rejects. Under the pre-fix picker that rejection was
         * unhandled and the UI sat on "loading" forever; the diagnostics
         * fixture fails this test on the console error alone, so the fix is
         * pinned even without the assertions below.
         */
        await page.route(STYLE_HOST, (route) => route.abort());

        await page.goto(
            `/admin/projects/wizard/${fixtures().wizard_draft_id}/location`,
            { waitUntil: 'domcontentloaded' },
        );

        // The failed state is STATED, with a retry — not an eternal spinner.
        const retry = page.getByRole('button', { name: 'دووبارە هەوڵدانەوە' });
        await expect(retry).toBeVisible({ timeout: 15_000 });
        await expect(page.getByText('نەخشە باردەکرێت…')).toBeHidden();

        // The coordinate inputs work without a map, and what is typed there
        // must survive the retry: a rebuild replaces the MAP, not the state.
        const latitude = page.getByLabel('پانی', { exact: true });
        const longitude = page.getByLabel('درێژی', { exact: true });
        await latitude.fill('36.21');
        await longitude.fill('44.02');

        // The provider recovers; Retry must produce a live canvas.
        await page.unroute(STYLE_HOST);
        await serveDeterministicStyle(page);
        await retry.click();

        const canvas = page.locator('.maplibregl-canvas');
        await expect(canvas).toBeVisible({ timeout: 20_000 });
        // Exactly one map: a retry may never stack a second instance.
        await expect(canvas).toHaveCount(1);

        await expect(latitude).toHaveValue('36.21');
        await expect(longitude).toHaveValue('44.02');
    });
});

/* ------------------------------------------- reactive breakpoint crossing */

test.describe('the lg breakpoint is reactive on the invest selection', () => {
    test.beforeEach(async ({}, testInfo) => {
        testInfo.skip(
            testInfo.project.name !== 'desktop-1440x900',
            'the crossing test drives the viewport itself; one project is enough',
        );
    });

    test('crossing 1024px with a selection open swaps card and sheet and releases the scroll-lock', async ({ page }) => {
        await serveDeterministicStyle(page);
        await page.goto('/invest', { waitUntil: 'domcontentloaded' });
        await expect(page.locator('.maplibregl-canvas')).toBeVisible({ timeout: 20_000 });

        await page.locator('#invest-search').fill('بورجی');
        const results = page.locator('#invest-search-results');
        await expect(results).toBeVisible();
        await results.getByRole('button').first().click();

        const card = page.locator('[role="status"]').filter({ hasText: 'بورجی وەبەرهێنانی تاقیکردنەوە' });
        const sheet = page.getByRole('dialog', { name: 'بورجی وەبەرهێنانی تاقیکردنەوە' });

        await expect(card).toBeVisible();
        await expect(sheet).toHaveCount(0);

        /*
         * M3: the split reacts to the media query itself, not to whatever
         * width the page mounted at. Pre-fix, isDesktop was a render-time
         * matchMedia call — after this resize the card stayed mounted and
         * no sheet (or its scroll-lock) ever appeared.
         */
        await page.setViewportSize({ width: 390, height: 844 });
        await expect(sheet).toBeVisible();
        await expect(card).toHaveCount(0);
        expect(await page.evaluate(() => document.body.style.overflow)).toBe('hidden');

        // Wide again: the sheet yields back to the card and the lock lifts.
        await page.setViewportSize({ width: 1440, height: 900 });
        await expect(card).toBeVisible();
        await expect(sheet).toHaveCount(0);
        expect(await page.evaluate(() => document.body.style.overflow)).toBe('');
    });
});

/* --------------------------------------- still-mounted provider failure */

/*
 * REV-P3: Phase 3 (M1) destroyed adapters whose construction outlived the
 * COMPONENT; these pin the complementary case — construction fails while the
 * page stays up. The failed, half-built adapter must be destroyed (its WebGL
 * context lost) instead of idling behind the failure message until the
 * visitor eventually navigates away. Pre-fix, the adapter stayed installed:
 * the accounting times out with created=1, lost=0.
 */
test.describe('still-mounted provider failure', () => {
    test.beforeEach(async ({}, testInfo) => {
        testInfo.skip(
            testInfo.project.name !== 'desktop-1440x900',
            'the resource accounting runs once, on desktop-1440x900 only',
        );
    });

    test('/map readiness failure destroys the failed adapter; no veil, list alive', async ({ page }) => {
        await instrumentWebglAccounting(page);
        await page.route(STYLE_HOST, (route) => route.abort());

        await page.goto('/map', { waitUntil: 'domcontentloaded' });

        // The failure is stated and the veil is settled, not eternal.
        await expect(page.getByText('نەخشە بار نەبوو').first()).toBeVisible({ timeout: 30_000 });
        await expect(page.getByText('بارکردنی نەخشە…')).toHaveCount(0);

        await page.waitForFunction(() => {
            const counters = window as unknown as { __webglCreated: number; __webglLost: number };
            return counters.__webglCreated > 0 && counters.__webglCreated === counters.__webglLost;
        }, undefined, { timeout: 15_000 });

        // The list is a peer, not a casualty.
        await expect(page.getByText('بورجی وەبەرهێنانی تاقیکردنەوە').first()).toBeVisible();
    });

    test('/invest readiness failure destroys the failed adapter, and leaving afterwards is clean', async ({ page }) => {
        await instrumentWebglAccounting(page);
        await page.route(STYLE_HOST, (route) => route.abort());

        await page.goto('/invest', { waitUntil: 'domcontentloaded' });

        await expect(page.getByText('نەخشە بار نەبوو').first()).toBeVisible({ timeout: 30_000 });

        await page.waitForFunction(() => {
            const counters = window as unknown as { __webglCreated: number; __webglLost: number };
            return counters.__webglCreated > 0 && counters.__webglCreated === counters.__webglLost;
        }, undefined, { timeout: 15_000 });

        /*
         * The unmount hook then runs against the already-nulled ref. A
         * double destroy would throw, and the diagnostics fixture fails the
         * test on any console or page error — this navigation IS the
         * double-destroy regression check.
         */
        await page.locator('a[href$="/projects"]').first().click();
        await expect(page.locator('.maplibregl-canvas')).toHaveCount(0);
    });
});

/* ---------------------------------------- Phase 5: in-place provider retry */

/*
 * REV-P3-RETRY: Phase 4 destroys a failed adapter so it cannot idle behind
 * the failure message; these pin the missing half — the visitor can rebuild
 * the map IN PLACE, without a full page reload. The wizard picker has had
 * exactly this contract since Phase 2 (see above); /map and /invest now
 * carry it too. Pre-fix, the retry control does not exist and the only
 * recovery is a browser reload.
 */
test.describe('public map in-place provider retry', () => {
    test.beforeEach(async ({}, testInfo) => {
        testInfo.skip(
            testInfo.project.name !== 'desktop-1440x900',
            'the retry lifecycle accounting runs once, on desktop-1440x900 only',
        );
    });

    for (const surface of [
        { path: '/map', features: '/map/features' },
        { path: '/invest', features: '/invest/features' },
    ]) {
        test(`${surface.path} provider failure offers Retry, and Retry rebuilds exactly one live map`, async ({ page }) => {
            await instrumentWebglAccounting(page);
            await page.route(STYLE_HOST, (route) => route.abort());

            await page.goto(surface.path, { waitUntil: 'domcontentloaded' });

            // The failed state is stated, with an in-canvas Retry beside it.
            await expect(page.getByText('نەخشە بار نەبوو').first()).toBeVisible({ timeout: 30_000 });
            const retry = page.getByTestId('map-retry-overlay');
            await expect(retry).toBeVisible();

            // Phase 4's cleanup already destroyed the failed adapter — the
            // precondition that makes an in-place rebuild safe at all.
            await page.waitForFunction(() => {
                const counters = window as unknown as { __webglCreated: number; __webglLost: number };
                return counters.__webglCreated === 1 && counters.__webglLost === 1;
            }, undefined, { timeout: 15_000 });

            // The provider recovers…
            await page.unroute(STYLE_HOST);
            await serveDeterministicStyle(page);

            /*
             * …and Retry is pressed TWICE in the same task, before Vue can
             * re-render the disabled attribute — the exact double-tap race.
             * The `mapBuilding` guard must make the second press a no-op;
             * if it does not, a second adapter is constructed and the exact
             * context accounting below (created 2, not 3) fails.
             */
            const features = page.waitForResponse(
                (response) => response.url().includes(surface.features) && response.ok(),
            );
            await page.evaluate(() => {
                const button = document.querySelector<HTMLButtonElement>('[data-testid="map-retry-overlay"]');
                button?.click();
                button?.click();
            });

            const canvas = page.locator('.maplibregl-canvas');
            await expect(canvas).toBeVisible({ timeout: 20_000 });
            await expect(canvas).toHaveCount(1);
            // One set of controls: a retry may never stack a second map.
            await expect(page.locator('.maplibregl-ctrl-zoom-in')).toHaveCount(1);

            // The rebuilt map repopulates its source through the normal
            // success path — the features request fires and succeeds.
            expect((await features).ok()).toBe(true);

            // Context accounting: failed build (created 1, lost 1) plus the
            // retried build (created 2), which stays live (lost stays 1).
            await page.waitForFunction(() => {
                const counters = window as unknown as { __webglCreated: number; __webglLost: number };
                return counters.__webglCreated === 2 && counters.__webglLost === 1;
            }, undefined, { timeout: 15_000 });

            // The failure state is fully withdrawn and no loader is stuck.
            await expect(page.getByText('نەخشە بار نەبوو')).toHaveCount(0);
            await expect(page.getByText('بارکردنی نەخشە…')).toHaveCount(0);
        });
    }
});

/* --------------------------------- Phase 8: homepage map in-place recovery */

/*
 * NEW-HOME-RETRY: the full surfaces carry Phase 5's in-place provider
 * recovery; the homepage — the first map most visitors ever see — did not.
 * Its failure state offered only the full-surface link, so a transient
 * provider failure was terminal until a page reload. These pin the homepage
 * edition of the contract, with its two lifecycle differences: the container
 * div is v-else'd away behind the failure state (the rebuild must wait for
 * it to re-render), and the projects were already fetched and kept — Retry
 * repopulates the fresh map through the existing sync() path and never
 * refetches. Pre-fix, [data-testid="home-map-retry"] does not exist and
 * every test here fails at that locator.
 */
test.describe('homepage map in-place recovery', () => {
    const FAILURE_STRINGS: Record<string, { failed: string; retry: string; open: string }> = {
        ckb: { failed: 'نەخشە بار نەبوو', retry: 'هەوڵدانەوە', open: 'نەخشە تەواوەکە بکەرەوە' },
        ar: { failed: 'تعذّر تحميل الخريطة', retry: 'إعادة المحاولة', open: 'افتح الخريطة الكاملة' },
        en: { failed: 'The map could not load', retry: 'Retry', open: 'Open the full map' },
    };

    /**
     * Break the provider before the page loads, scroll the lazy section into
     * view so the IntersectionObserver fires exactly as in production, and
     * wait for the settled failure state.
     */
    async function driveHomeMapToFailure(
        page: import('@playwright/test').Page,
        prefix = '',
    ): Promise<import('@playwright/test').Locator> {
        await page.route(STYLE_HOST, (route) => route.abort());
        await page.goto(`${prefix}/`, { waitUntil: 'domcontentloaded' });

        const section = page.locator('[data-testid="home-project-map"]');
        await expect(section).toHaveCount(1);
        await section.scrollIntoViewIfNeeded();

        await expect(page.getByTestId('home-map-retry')).toBeVisible({ timeout: 30_000 });

        return section;
    }

    /**
     * Count canvas pixels around the map centre that are not the
     * deterministic style's background — the fixture projects sit within
     * ~110px of the homepage camera (36.19, 44.009 at zoom 11; 2912.7
     * px/deg), so paint here is marker/cluster ink and nothing else. The
     * empty rebuilt map would sample zero: this is the sync() proof.
     */
    async function markerInkNearCentre(canvas: import('@playwright/test').Locator): Promise<number> {
        const png = decodePng(await canvas.screenshot());
        const background: Rgb = { r: 232, g: 230, b: 225 };
        let painted = 0;

        for (let dx = -60; dx <= 100; dx += 2) {
            for (let dy = -70; dy <= 70; dy += 2) {
                if (colourDelta(png.pixelAt(png.width / 2 + dx, png.height / 2 + dy), background) > 60) {
                    painted += 1;
                }
            }
        }

        return painted;
    }

    test.describe('recovery lifecycle', () => {
        test.beforeEach(async ({}, testInfo) => {
            testInfo.skip(
                testInfo.project.name !== 'desktop-1440x900',
                'the retry lifecycle accounting runs once, on desktop-1440x900 only',
            );
        });

        test('failure offers Retry beside the kept CTA; Retry rebuilds one live map from the kept data, refetching nothing', async ({ page }) => {
            await instrumentWebglAccounting(page);

            // Every /invest/features or /map/features fetch the page ever
            // makes — the proof that recovery repopulates WITHOUT load().
            let featureRequests = 0;
            page.on('request', (request) => {
                if (request.url().includes('/features?')) featureRequests += 1;
            });

            const section = await driveHomeMapToFailure(page);

            // The failure message and the full-surface CTA are preserved
            // exactly; Retry stands beside them, not instead of them.
            await expect(section.getByText('نەخشە بار نەبوو')).toBeVisible();
            const retry = page.getByTestId('home-map-retry');
            await expect(retry).toBeEnabled();
            await expect(page.locator('[data-testid="home-map-retry"] + a')).toHaveText('نەخشە تەواوەکە بکەرەوە');

            // Phase 4's cleanup destroyed the failed adapter — the
            // precondition that makes an in-place rebuild safe.
            await page.waitForFunction(() => {
                const counters = window as unknown as { __webglCreated: number; __webglLost: number };
                return counters.__webglCreated === 1 && counters.__webglLost === 1;
            }, undefined, { timeout: 15_000 });

            // The data path already ran, once, despite the dead provider.
            await expect.poll(() => featureRequests).toBe(1);

            // The provider recovers…
            await page.unroute(STYLE_HOST);
            await serveDeterministicStyle(page);

            /*
             * …and Retry is pressed TWICE in the same task, before Vue can
             * re-render the disabled attribute — the double-tap race. The
             * synchronous mapBuilding claim must make the second press a
             * no-op; otherwise a second adapter is constructed and the exact
             * accounting below (created 2, not 3) fails.
             */
            await page.evaluate(() => {
                const button = document.querySelector<HTMLButtonElement>('[data-testid="home-map-retry"]');
                button?.click();
                button?.click();
            });

            const canvas = section.locator('.maplibregl-canvas');
            await expect(canvas).toBeVisible({ timeout: 20_000 });
            await expect(canvas).toHaveCount(1);
            // One set of controls: a retry may never stack a second map.
            await expect(section.locator('.maplibregl-ctrl-zoom-in')).toHaveCount(1);

            // Failed build (created 1, lost 1) plus the rebuild (created 2)
            // which stays live: exactly one WebGL context survives.
            await page.waitForFunction(() => {
                const counters = window as unknown as { __webglCreated: number; __webglLost: number };
                return counters.__webglCreated === 2 && counters.__webglLost === 1;
            }, undefined, { timeout: 15_000 });

            // The failure state is fully withdrawn.
            await expect(section.getByText('نەخشە بار نەبوو')).toHaveCount(0);
            await expect(retry).toHaveCount(0);

            // The kept projects are back ON the rebuilt map via sync().
            await expect
                .poll(() => markerInkNearCentre(canvas), {
                    timeout: 20_000,
                    message: 'the rebuilt map must repaint the kept fixture projects',
                })
                .toBeGreaterThan(25);

            // And the whole recovery refetched NOTHING.
            expect(featureRequests).toBe(1);
        });

        test('a rebuild that fails again settles back into the stated failure, and a later Retry still recovers', async ({ page }) => {
            await instrumentWebglAccounting(page);
            const section = await driveHomeMapToFailure(page);

            await page.waitForFunction(() => {
                const counters = window as unknown as { __webglCreated: number; __webglLost: number };
                return counters.__webglCreated === 1 && counters.__webglLost === 1;
            }, undefined, { timeout: 15_000 });

            /*
             * Retry against the STILL-dead provider. The rebuild must fail
             * the same honest way the first build did — failure re-stated,
             * its adapter destroyed, the building flag released so Retry is
             * pressable again — never a wedged in-between state.
             */
            const retry = page.getByTestId('home-map-retry');
            await retry.click();

            await expect(retry).toBeVisible({ timeout: 30_000 });
            await expect(retry).toBeEnabled();
            await expect(section.getByText('نەخشە بار نەبوو')).toBeVisible();
            await page.waitForFunction(() => {
                const counters = window as unknown as { __webglCreated: number; __webglLost: number };
                return counters.__webglCreated === 2 && counters.__webglLost === 2;
            }, undefined, { timeout: 15_000 });

            // Now the provider recovers: the third attempt must come up
            // clean — nothing the two dead builds left behind (state, late
            // error callbacks) may poison it.
            await page.unroute(STYLE_HOST);
            await serveDeterministicStyle(page);
            await retry.click();

            const canvas = section.locator('.maplibregl-canvas');
            await expect(canvas).toBeVisible({ timeout: 20_000 });
            await expect(canvas).toHaveCount(1);
            await expect(section.getByText('نەخشە بار نەبوو')).toHaveCount(0);

            await page.waitForFunction(() => {
                const counters = window as unknown as { __webglCreated: number; __webglLost: number };
                return counters.__webglCreated === 3 && counters.__webglLost === 2;
            }, undefined, { timeout: 15_000 });
        });

        test('navigating away during a Retry rebuild destroys the in-flight adapter; nothing resurrects', async ({ page }) => {
            await instrumentWebglAccounting(page);
            await driveHomeMapToFailure(page);

            await page.waitForFunction(() => {
                const counters = window as unknown as { __webglCreated: number; __webglLost: number };
                return counters.__webglCreated === 1 && counters.__webglLost === 1;
            }, undefined, { timeout: 15_000 });

            /*
             * Hold the rebuild's style request open so the retry build is
             * genuinely in flight when navigation unmounts the component.
             * The disposal guard must destroy the late adapter — it may
             * never install itself against the dead page.
             */
            let release!: () => void;
            const gate = new Promise<void>((resolve) => {
                release = resolve;
            });

            await page.unroute(STYLE_HOST);
            await page.route(STYLE_HOST, async (route) => {
                await gate;
                try {
                    await route.fulfill({
                        status: 200,
                        contentType: 'application/json',
                        body: JSON.stringify(DETERMINISTIC_STYLE),
                    });
                } catch {
                    // The disposed rebuild aborted its own request — which
                    // is exactly the behaviour under test.
                }
            });

            const styleRequested = page.waitForRequest(STYLE_HOST);
            await page.getByTestId('home-map-retry').click();
            await styleRequested;

            await page.locator('a[href$="/projects"]').first().click();

            release();

            // Every context the retry created ends lost; none survives the
            // navigation, and no map exists on the destination page.
            await page.waitForFunction(() => {
                const counters = window as unknown as { __webglCreated: number; __webglLost: number };
                return counters.__webglCreated >= 2 && counters.__webglCreated === counters.__webglLost;
            }, undefined, { timeout: 15_000 });
            await expect(page.locator('.maplibregl-canvas')).toHaveCount(0);
        });

        for (const locale of LOCALES) {
            test(`the failure state speaks ${locale.code}: message, Retry, and the full-surface CTA`, async ({ page }) => {
                const section = await driveHomeMapToFailure(page, locale.prefix);
                const strings = FAILURE_STRINGS[locale.code];

                await expect(page.locator('html')).toHaveAttribute('dir', locale.direction);
                await expect(section.getByText(strings.failed)).toBeVisible();
                await expect(page.getByTestId('home-map-retry')).toHaveText(strings.retry);
                await expect(page.locator('[data-testid="home-map-retry"] + a')).toHaveText(strings.open);
            });
        }
    });

    test('on a phone the Retry is a real touch target inside the viewport, and one tap recovers', async ({ page }, testInfo) => {
        testInfo.skip(
            (testInfo.project.use.viewport?.width ?? 0) >= 768,
            'the touch-target smoke runs on the phone viewports',
        );

        const section = await driveHomeMapToFailure(page);
        const retry = page.getByTestId('home-map-retry');
        await retry.scrollIntoViewIfNeeded();

        const viewport = page.viewportSize()!;
        const box = await retry.boundingBox();
        expect(box).not.toBeNull();
        expect(box!.height, 'retry height').toBeGreaterThanOrEqual(44);
        expect(box!.width, 'retry width').toBeGreaterThanOrEqual(44);
        // The recovery row fits the phone: nothing pushed off-screen.
        expect(box!.x).toBeGreaterThanOrEqual(0);
        expect(box!.x + box!.width).toBeLessThanOrEqual(viewport.width + 0.5);
        await expect(page.locator('[data-testid="home-map-retry"] + a')).toBeVisible();

        await page.unroute(STYLE_HOST);
        await serveDeterministicStyle(page);
        await retry.tap();

        await expect(section.locator('.maplibregl-canvas')).toBeVisible({ timeout: 20_000 });
        await expect(section.getByText('نەخشە بار نەبوو')).toHaveCount(0);
    });
});

/* ----------------------------------- Phase 9: explorer marker affordances */

/*
 * NEW-EXPLORER-HONESTY: on /map, selection deliberately lives in the list —
 * the surface wires no onMarkerClick — yet the adapter advertised a pointer
 * cursor over every project point and claimed every click that landed on
 * one. Ordinary clicks promised an action that never came, and the
 * explorer's own centre-pick and draw modes died silently whenever the
 * click fell on a marker. Phase 9 makes the affordance honest: point layers
 * show pointer and claim clicks only where a surface wired onMarkerClick;
 * clusters keep both everywhere, because expansion is always a real action.
 * Pre-fix, the cursor assertion and both mode pass-throughs below fail.
 *
 * Geometry: /map opens at (36.19, 44.009), zoom 11 — 2912.7 px/deg of
 * longitude, 3609.6 px/deg of latitude (the Mercator 1/cos stretch). The
 * tower and villa fixtures sit ~46px apart, inside the 50px cluster radius,
 * and merge into one cluster near (+32, -36) from the canvas centre; the
 * bazaar fixture at (-26, +18) stays an unclustered point.
 */
test.describe('explorer marker affordance honesty', () => {
    const CLUSTER = { dx: 32, dy: -36 };
    const BAZAAR = { dx: -26, dy: 18 };
    /*
     * Empty basemap, clear of every fixture. Hovers route through here
     * because a single mouse move that leaves one registered layer and
     * enters another can settle the cursor on either handler's outcome —
     * approaching each probe point from neutral ground makes the enter
     * handler's verdict the only one in play.
     */
    const NEUTRAL = { dx: -120, dy: 100 };

    test.beforeEach(async ({}, testInfo) => {
        testInfo.skip(
            testInfo.project.name !== 'desktop-1440x900',
            'the interaction contract runs once, on desktop-1440x900 only',
        );
    });

    async function openExplorer(page: import('@playwright/test').Page): Promise<{
        map: import('@playwright/test').Locator;
        at: (offset: { dx: number; dy: number }) => { x: number; y: number };
        cursor: () => Promise<string>;
    }> {
        await serveDeterministicStyle(page);

        const first = page.waitForResponse(
            (response) => response.url().includes('/map/features') && response.ok(),
        );
        await page.goto('/map', { waitUntil: 'domcontentloaded' });
        await expect(page.locator('.maplibregl-canvas')).toBeVisible({ timeout: 20_000 });
        await first;

        const map = page.locator('[role="application"]').first();
        const box = await map.boundingBox();
        expect(box).not.toBeNull();

        const at = (offset: { dx: number; dy: number }): { x: number; y: number } =>
            ({ x: box!.width / 2 + offset.dx, y: box!.height / 2 + offset.dy });
        const cursor = (): Promise<string> =>
            page.locator('.maplibregl-canvas').first().evaluate((el) => getComputedStyle(el).cursor);

        // Marker layers paint asynchronously after the style settles; the
        // cluster's pointer promise — kept on every surface — doubles as
        // the rendered-and-interactive barrier.
        await expect(async () => {
            await map.hover({ position: at(NEUTRAL) });
            await map.hover({ position: at(CLUSTER) });
            expect(await cursor()).toBe('pointer');
        }).toPass({ timeout: 20_000 });

        await map.hover({ position: at(NEUTRAL) });

        return { map, at, cursor };
    }

    test('points do not advertise a click the surface cannot deliver; clusters keep theirs', async ({ page }) => {
        const { map, at, cursor } = await openExplorer(page);

        // The unclustered point: no onMarkerClick here, so no pointer.
        await map.hover({ position: at(BAZAAR) });
        expect(await cursor()).not.toBe('pointer');

        // An ordinary click on it triggers nothing: no selection UI exists
        // on this surface, no navigation — and the diagnostics fixture
        // fails the test on any console error this click might raise.
        await map.click({ position: at(BAZAAR) });
        await expect(page).toHaveURL(/\/map/);

        // The cluster still delivers both halves of its promise: pointer,
        // and click-to-expand — whose moveend schedules the debounced
        // background refetch this waiter observes.
        await map.hover({ position: at(NEUTRAL) });
        await map.hover({ position: at(CLUSTER) });
        expect(await cursor()).toBe('pointer');

        const refetch = page.waitForResponse(
            (response) => response.url().includes('/map/features') && response.ok(),
        );
        await map.click({ position: at(CLUSTER) });
        expect((await refetch).ok()).toBe(true);
    });

    test('centre-pick lands on a project point instead of dying on the marker guard', async ({ page }) => {
        const { map, at } = await openExplorer(page);

        await page.getByRole('button', { name: 'دیاریکردنی ناوەند' }).click();
        const hint = page.getByText('خاڵێک لەسەر نەخشە هەڵبژێرە، دواتر دووری بە کیلۆمەتر دیاری بکە');
        await expect(hint).toBeVisible();

        // The pick lands ON the bazaar marker. Pre-fix, the unconditional
        // guard claimed the click for a marker action that does not exist
        // here: the mode never exits and no radius search ever fires.
        const search = page.waitForResponse(
            (response) => response.url().includes('/map/features') && response.ok(),
        );
        await map.click({ position: at(BAZAAR) });
        expect((await search).ok()).toBe(true);
        await expect(hint).toBeHidden();
        await expect(page.getByRole('button', { name: 'ناوەند دیاری کرا' })).toBeVisible();
    });

    test('drawing appends a vertex over a project point, and a cluster hit still expands instead', async ({ page }) => {
        const { map, at } = await openExplorer(page);

        await page.getByRole('button', { name: 'کێشانی ناوچە' }).click();
        await expect(page.getByText('کلیک بکە بۆ زیادکردنی خاڵ. لانیکەم سێ خاڵ پێویستە.')).toBeVisible();

        // The vertex lands ON the bazaar marker; the draw tools' own point
        // counter is the product's proof of the append. Pre-fix it never
        // appears: the guard ate the click.
        await map.click({ position: at(BAZAAR) });
        await expect(page.getByText('1 خاڵ')).toBeVisible();

        // The mode/cluster boundary: a draw click on a CLUSTER still
        // belongs to the cluster — it expands, and no vertex is appended.
        await map.click({ position: at(CLUSTER) });
        await expect(page.getByText('1 خاڵ')).toBeVisible();
        await expect(page.getByText('2 خاڵ')).toHaveCount(0);
    });
});

/* ------------------------------------ Phase 5: refetch feedback, mobile tab */

/*
 * NEW-REFETCH: after the first load, a viewport refetch was invisible on the
 * mobile map tab — the only in-flight signal lived in the list pane, which the
 * map tab hides. These pin the pill (in flight), the stated failure with the
 * stale data KEPT, and the Data retry — which refreshes the data without
 * touching the map adapter. Provider Retry and Data retry are different
 * operations; this suite exercises the latter with the map alive throughout.
 */
test.describe('map refetch feedback on the mobile map tab', () => {
    test.beforeEach(async ({}, testInfo) => {
        testInfo.skip(
            (testInfo.project.use.viewport?.width ?? 0) >= 768,
            'the map/list tab split — and the feedback gap — exists below md',
        );
    });

    /**
     * Drag the map ~80px so moveend schedules a debounced refetch.
     *
     * The grip point is the UPPER third of the map box, not its centre: raw
     * mouse coordinates neither scroll nor hit-test, and at phone heights a
     * map's lower half can sit under the fixed bottom navigation — a centre
     * grip presses a nav link and the map never moves.
     */
    async function panMap(page: import('@playwright/test').Page): Promise<void> {
        const map = page.locator('[role="application"]').first();
        await map.scrollIntoViewIfNeeded();
        const box = await map.boundingBox();
        expect(box).not.toBeNull();
        const gripX = box!.x + box!.width / 2;
        const gripY = box!.y + box!.height * 0.3;
        await page.mouse.move(gripX, gripY);
        await page.mouse.down();
        await page.mouse.move(gripX - 80, gripY + 50, { steps: 6 });
        await page.mouse.up();
    }

    for (const surface of [
        {
            path: '/map',
            features: '/map/features',
            // The explorer's refetch rides on moveend: a genuine pan drives it.
            trigger: panMap,
        },
        {
            path: '/invest',
            features: '/invest/features',
            /*
             * The invest surface is driven through its boundaries toggle,
             * which runs the IDENTICAL load() path (watch(showBoundaries)).
             * A drag is deliberately not used here: raw-coordinate drags are
             * unreliable on this page — at phone heights the map's lower
             * half sits under the fixed bottom navigation, and a synthetic
             * MOUSE drag at 360×800 does not pan MapLibre even when it hits
             * the canvas. Both effects reproduce on main (6d19710) exactly
             * as on the Phase 5 build, and TOUCH input pans fine — a
             * harness/input artifact, not a product defect. The toggle is
             * deterministic (a locator click scrolls and hit-tests) and
             * proves the same contract: updating feedback, stale retention
             * on failure, Data retry through load(), recovery, no adapter
             * rebuild.
             */
            trigger: async (page: import('@playwright/test').Page): Promise<void> => {
                await page.getByRole('button', { name: 'سنوورەکانی ناوچە' }).click();
            },
        },
    ]) {
        test(`${surface.path}: a refetch shows the pill; a failed refetch keeps data, states it, and Data retry recovers`, async ({ page }) => {
            await serveDeterministicStyle(page);

            const first = page.waitForResponse(
                (response) => response.url().includes(surface.features) && response.ok(),
            );
            await page.goto(surface.path, { waitUntil: 'domcontentloaded' });
            await expect(page.locator('.maplibregl-canvas')).toBeVisible({ timeout: 20_000 });
            await first;

            /*
             * `first` proves a features response ARRIVED — not that the page
             * APPLIED it. Under runner starvation the ok response satisfying
             * that gate can belong to a superseded loadAttempt, which the
             * page discards by design; if the one refetch released below then
             * fails transiently, the page still holds nothing, and the
             * stale-retention assertion far below finds an empty list while
             * every assertion before it passes — exactly the CI #159 failure,
             * reproduced mechanically from this gap. The precondition is
             * therefore pinned on page STATE: the fixture tower is in the
             * list DOM (attached, not visible — the list pane sits behind the
             * map tab at this width) before the test takes ownership of the
             * endpoint. While this waits the endpoint is untouched, so the
             * page's own refetches can still apply what the network already
             * delivered.
             */
            await expect(page.getByText('بورجی وەبەرهێنانی تاقیکردنەوە').first()).toBeAttached({ timeout: 15_000 });

            // From here this test owns the features endpoint: one slow
            // response, then a dead one, then recovery. Registered after the
            // initial load so that load stays untouched. Both ends of every
            // request's lifecycle are counted — started and settled — because
            // the synchronization below is proven on that lifecycle, never on
            // a clock.
            let mode: 'slow' | 'fail' | 'ok' = 'slow';
            let featureRequests = 0;
            let featureSettled = 0;
            let releaseSlow!: () => void;
            const slowGate = new Promise<void>((resolve) => {
                releaseSlow = resolve;
            });
            await page.route(`**${surface.features}*`, async (route) => {
                featureRequests += 1;

                if (mode === 'slow') {
                    await slowGate;
                    await route.continue();
                } else if (mode === 'fail') {
                    await route.abort();
                } else {
                    await route.continue();
                }

                featureSettled += 1;
            });

            // Pan → debounce → refetch: the pill must be visible ON THE MAP
            // TAB while the request is in flight. Pre-fix, nothing appears.
            await surface.trigger(page);
            const pill = page.getByTestId('map-updating');
            await expect(pill).toBeVisible({ timeout: 10_000 });
            releaseSlow();
            await expect(pill).toBeHidden({ timeout: 10_000 });

            // A dropped refetch: the failure is stated on the map tab…
            mode = 'fail';
            await surface.trigger(page);
            const failedChip = page.getByTestId('map-refetch-failed');
            await expect(failedChip).toBeVisible({ timeout: 10_000 });

            // …and the stale results were KEPT, not blanked.
            await page.getByRole('tab').nth(1).click();
            await expect(page.getByText('بورجی وەبەرهێنانی تاقیکردنەوە').first()).toBeVisible();

            /*
             * Returning to the map tab resizes the revealed map, MapLibre's
             * resize fires moveend, and moveend schedules the debounced
             * background refetch — exactly as a real pan would. The endpoint
             * is still dead, so that refetch fails and the chip stays. It
             * MUST still be dead here: reviving it first lets the background
             * refetch succeed and withdraw the chip while the click below is
             * still lining up, and the click then waits forever for a
             * control the product correctly removed. That mistake shipped
             * twice — CI #126 pre-Phase-5, then CI #136 attempt 1, where a
             * 400ms wall-clock silence window certified quiescence on the
             * WRONG CLOCK (the test process's) while the page's own debounce
             * timer was still armed and fired late under runner starvation.
             *
             * The synchronization is therefore the OBSERVED request
             * lifecycle, never a delay: from a baseline recorded before the
             * tab return, the refetch it arms must actually START and
             * actually SETTLE against the still-dead endpoint.
             */
            const drainedFrom = featureRequests;
            await page.getByRole('tab').nth(0).click();

            await expect
                .poll(() => featureRequests, { timeout: 15_000 })
                .toBeGreaterThan(drainedFrom);
            await expect
                .poll(() => featureSettled === featureRequests, { timeout: 15_000 })
                .toBe(true);

            // Background refetches kept failing, so the failure stayed
            // stated and the stale data stayed kept.
            await expect(failedChip).toBeVisible();

            /*
             * And nothing older may remain able to mutate that state. The
             * page-side timer below shares the page's timer queue with the
             * 250ms debounce, and timers fire in due-time order: any
             * debounce armed before the barrier has fired before it
             * resolves — starvation delays both equally but cannot reorder
             * them. The barrier is only that ordering guard, never the
             * evidence: the lifecycle counters must not move across it.
             */
            const settledAt = featureRequests;
            await page.evaluate(() => new Promise((resolve) => setTimeout(resolve, 600)));
            expect(featureRequests).toBe(settledAt);
            expect(featureSettled).toBe(featureRequests);

            /*
             * The positioning contract, asserted in the pose a visitor
             * actually reads the map in: with the map panel's bottom edge at
             * the viewport's bottom, the retry control must sit clear of the
             * fixed bottom navigation band — the nav (z-40) paints over the
             * map's overlays (z-10), so any overlap leaves the control
             * hidden under the bar with its taps landing on the navigation.
             * This is what keeps the mobile bottom-24 anchor honest: the
             * centring scroll below would let a bottom-4 regression pass
             * unnoticed, because a centred chip is always clear of the nav.
             */
            await page
                .locator('[role="application"]')
                .first()
                .evaluate((element) => {
                    (element.closest('section') ?? element).scrollIntoView({ block: 'end' });
                });
            const retry = page.getByTestId('data-retry-overlay');
            const retryBox = await retry.boundingBox();
            const navBox = await page.locator('nav.fixed.bottom-0').boundingBox();
            expect(retryBox, 'retry chip must render').not.toBeNull();
            expect(navBox, 'the fixed bottom navigation must render').not.toBeNull();
            expect(
                retryBox!.y + retryBox!.height,
                'the retry control must sit clear of the fixed bottom navigation',
            ).toBeLessThanOrEqual(navBox!.y);

            /*
             * A visitor taps what they can see. At phone heights the map's
             * bottom edge can rest at the viewport's bottom, where the fixed
             * bottom navigation (z-40) paints over anything left there, so
             * scroll the chip to the viewport's centre first — the scroll a
             * real reader performs — and then deliver a REAL, unforced
             * pointer click. Playwright's own pre-click scroll cannot do
             * this: the chip already counts as "in view", so no scroll
             * happens and the click lands on the navigation instead.
             */
            await retry.evaluate((element) => element.scrollIntoView({ block: 'center' }));

            // Data retry refreshes the data without rebuilding the map.
            mode = 'ok';
            const recovered = page.waitForResponse(
                (response) => response.url().includes(surface.features) && response.ok(),
            );
            await retry.click();
            expect((await recovered).ok()).toBe(true);
            await expect(failedChip).toBeHidden({ timeout: 10_000 });
            await expect(page.locator('.maplibregl-canvas')).toHaveCount(1);
        });
    }
});

/* ------------------------------------------- Phase 5: touch-target floor */

/*
 * NEW-TOUCH: the interactive controls on the public map surfaces meet the
 * 44px floor on coarse-pointer devices. The mobile Playwright projects run
 * with hasTouch, so `(pointer: coarse)` genuinely matches — the same media
 * query the CSS scopes the enlargement to. MapLibre's native buttons are
 * checked width AND height (stock CSS ships them 29×29); everything else
 * goes through the shared harness floor, on both mobile tabs.
 */
test.describe('map touch targets on coarse-pointer viewports', () => {
    test.beforeEach(async ({}, testInfo) => {
        testInfo.skip(
            (testInfo.project.use.viewport?.width ?? 0) >= 768,
            'the 44px floor is a touch-viewport requirement',
        );
    });

    for (const path of ['/map', '/invest']) {
        test(`${path} controls meet the 44px touch floor`, async ({ page }) => {
            await serveDeterministicStyle(page);
            await page.goto(path, { waitUntil: 'domcontentloaded' });
            await expect(page.locator('.maplibregl-canvas')).toBeVisible({ timeout: 20_000 });

            // The enlargement is scoped to `pointer: coarse`; prove the
            // context actually matches it rather than trusting viewport width.
            expect(await page.evaluate(() => matchMedia('(pointer: coarse)').matches)).toBe(true);

            for (const control of ['.maplibregl-ctrl-zoom-in', '.maplibregl-ctrl-zoom-out']) {
                const box = await page.locator(control).boundingBox();
                expect(box, `${control} must render`).not.toBeNull();
                expect(box!.width, `${control} width`).toBeGreaterThanOrEqual(44);
                expect(box!.height, `${control} height`).toBeGreaterThanOrEqual(44);
            }

            const attribution = page.locator('.maplibregl-ctrl-attrib-button');
            if ((await attribution.count()) > 0 && (await attribution.first().isVisible())) {
                const box = await attribution.first().boundingBox();
                expect(box!.width, 'attribution toggle width').toBeGreaterThanOrEqual(44);
                expect(box!.height, 'attribution toggle height').toBeGreaterThanOrEqual(44);
            }

            // Every interactive control on the map tab, then the list tab.
            await expectTouchTargets(page);
            await page.getByRole('tab').nth(1).click();
            await expectTouchTargets(page);
        });
    }
});

/* -------------------------------------------------- mobile map/list switch */

test('mobile /map switches between a live map and the list', async ({ page }, testInfo) => {
    testInfo.skip(
        (testInfo.project.use.viewport?.width ?? 0) >= 768,
        'the map/list switch exists only below md',
    );

    await serveDeterministicStyle(page);
    await page.goto('/map', { waitUntil: 'domcontentloaded' });

    await expect(page.locator('.maplibregl-canvas')).toBeVisible({ timeout: 20_000 });

    // To the list: the map yields, the results take the viewport.
    await page.getByRole('tab').nth(1).click();
    await expect(page.getByText('بورجی وەبەرهێنانی تاقیکردنەوە').first()).toBeVisible();

    // And back: one tap returns the same live map — no rebuild, no loader.
    await page.getByRole('tab').nth(0).click();
    await expect(page.locator('.maplibregl-canvas')).toBeVisible();
    await expect(page.getByText('بارکردنی نەخشە…')).toHaveCount(0);
});
