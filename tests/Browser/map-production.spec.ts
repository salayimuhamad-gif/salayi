import { test, expect, LOCALES } from './support/harness';
import { fixtures, signInAdmin } from './support/fixtures';

/*
 * The map surfaces with a WORKING style — the other half of the contract.
 *
 * invest.spec.ts pins the degraded path: the harness's hermetic network makes
 * every external request return an empty body, so the style fails and the
 * product must state that and keep the list alive. This suite pins the path
 * production was shipping broken: the style LOADS, the map becomes ready, the
 * canvas is laid out by the MapLibre stylesheet, markers carry data for all
 * four trends, a marker click selects, and the admin picker built inside a
 * hidden tab recovers when the tab is revealed.
 *
 * DETERMINISM: the style these tests load is served from inside the test via
 * route interception — a background-only MapLibre style with no sources, no
 * sprite, no glyph server and no tiles, so nothing here ever contacts
 * demotiles.maplibre.org or any other provider. CI must not depend on public
 * demo infrastructure; a style that needs zero further requests is the
 * strongest form of that rule. Marker icons render regardless, because the
 * adapter draws them onto a canvas at registration time.
 */

const STYLE_HOST = 'https://demotiles.maplibre.org/**';

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

test('/map states a provider failure and keeps the list; no infinite loader', async ({ page }, testInfo) => {
    // NO deterministic style here: the harness's hermetic network answers the
    // style URL with an empty body, which is exactly what a dead or blocked
    // provider looks like from a browser in Erbil.
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
