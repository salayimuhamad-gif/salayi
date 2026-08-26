import { test, expect, LOCALES, expectNoHorizontalOverflow } from './support/harness';

/*
 * Arabic-script text on the map — the RTL text plugin, behaviorally.
 *
 * MapLibre core ships no bidi/shaping engine; the shared adapter bundles
 * the official plugin and registers it lazily, guarded by MapLibre's own
 * status API. Three observable facts keep the production defect closed:
 *
 *   1. REGISTRATION IS SINGULAR. Two MapLibre surfaces across one SPA
 *      session re-enter the adapter with plugin state already set; a
 *      duplicate setRTLTextPlugin() call rejects, and the diagnostics
 *      fixture's teardown holds the run to zero console/page errors.
 *   2. THE PLUGIN IS A SAME-ORIGIN PRODUCTION ASSET — resolvable from the
 *      build manifest and served by this app, never unpkg/jsdelivr.
 *   3. THE SHIPPED PIPELINE ACTUALLY SHAPES ARABIC SCRIPT. A harness page
 *      (served same-origin via route interception, the same technique the
 *      deterministic style uses) drives the REAL built MapLibre chunk, the
 *      REAL built worker and the REAL built plugin with a symbol layer
 *      whose labels are أربيل and هەولێر: laying out that text is the lazy
 *      registration's trigger, so the test observes the plugin request
 *      fire, succeed from this origin, and the status reach 'loaded'.
 *
 * The app-flow test uses the same zero-request deterministic style as
 * map-production, so nothing here contacts public demo infrastructure; the
 * harness style is inline and needs no network at all.
 */

const STYLE_HOST = '**/map-styles/mulk-dark.json';

const DETERMINISTIC_STYLE = {
    version: 8,
    name: 'deterministic-e2e',
    sources: {},
    layers: [{ id: 'bg', type: 'background', paint: { 'background-color': '#e8e6e1' } }],
};

interface ManifestEntry {
    file?: string;
}

async function buildFiles(page: import('@playwright/test').Page): Promise<string[]> {
    const response = await page.request.get('/build/manifest.json');
    expect(response.status(), 'the build manifest must be served').toBe(200);
    const manifest = (await response.json()) as Record<string, ManifestEntry>;

    return Object.values(manifest)
        .map((entry) => entry.file ?? '')
        .filter((file) => file !== '');
}

test('two MapLibre surfaces in one session register the RTL plugin exactly once', async ({ page, diagnostics }) => {
    await page.route(STYLE_HOST, (route) =>
        route.fulfill({
            status: 200,
            contentType: 'application/json',
            body: JSON.stringify(DETERMINISTIC_STYLE),
        }),
    );

    await page.goto('/', { waitUntil: 'domcontentloaded' });

    const home = page.locator('[data-testid="home-project-map"]');
    await home.scrollIntoViewIfNeeded();
    await expect(home.locator('.maplibregl-canvas')).toBeVisible({ timeout: 20_000 });

    // Client-side navigation to a second MapLibre surface: the adapter
    // re-enters initialise() in the SAME JavaScript context, where the
    // plugin state persists. A second setRTLTextPlugin() call would reject
    // — the diagnostics teardown (zero console/page errors) is the trap.
    // TEST_ONLY selector update (Wave 2B): the way into the full surface is
    // the map card's amber head link now — the floating chip that used to
    // cover the zoom control is gone. Same navigation, same assertion.
    await home.locator('a.mh-link-amber').click();
    await expect(page.locator('.maplibregl-canvas').first()).toBeVisible({ timeout: 20_000 });

    expect(diagnostics.failedRequests, 'no request may fail across the two maps').toEqual([]);
});

test('the RTL plugin ships as a same-origin production asset', async ({ page }) => {
    await page.goto('/', { waitUntil: 'domcontentloaded' });

    const rtlFile = (await buildFiles(page)).find((file) => file.includes('mapbox-gl-rtl-text'));
    expect(rtlFile, 'the RTL plugin must be emitted into the build manifest').toBeTruthy();

    // Served by THIS app — the request goes to the app origin, and the
    // body is the self-registering worker script, not an HTML error page.
    const asset = await page.request.get(`/build/${rtlFile}`);
    expect(asset.status()).toBe(200);
    expect(await asset.text()).toContain('registerRTLTextPlugin');
});

test('the shipped pipeline lazily loads the plugin for أربيل and هەولێر', async ({ page, diagnostics }) => {
    const files = await buildFiles(page);
    const chunk = files.find((file) => file.includes('maplibre-gl-') && !file.includes('worker'));
    const rtl = files.find((file) => file.includes('mapbox-gl-rtl-text'));

    // The worker is a `?worker&url` asset, which Vite emits WITHOUT a
    // manifest entry — but the PWA precache manifest inside sw.js names
    // every built asset, worker included.
    const sw = await page.request.get('/build/sw.js');
    expect(sw.status(), 'the service worker bundle must be served').toBe(200);
    const worker = (await sw.text()).match(/assets\/maplibre-gl-worker-[\w-]+\.js/)?.[0];

    expect(chunk && rtl, 'chunk and plugin must be in the build manifest').toBeTruthy();
    expect(worker, 'the MapLibre worker asset must be precached by sw.js').toBeTruthy();

    const rtlResponses: Array<{ url: string; status: number }> = [];
    page.on('response', (response) => {
        if (response.url().includes('mapbox-gl-rtl-text')) {
            rtlResponses.push({ url: response.url(), status: response.status() });
        }
    });

    // Same-origin harness page (route interception, exactly like the
    // deterministic style): the REAL built assets drive a map whose only
    // text is Arabic-script — the lazy registration's trigger.
    await page.route('**/__map-rtl-harness__', (route) =>
        route.fulfill({
            status: 200,
            contentType: 'text/html; charset=utf-8',
            body: `<!doctype html><meta charset="utf-8"><title>boot</title>
<div id="map" style="width:640px;height:420px"></div>
<script type="module">
const maplibre = await import('/build/${chunk}');
maplibre.setWorkerUrl('/build/${worker}');
if (maplibre.getRTLTextPluginStatus() === 'unavailable') {
    await maplibre.setRTLTextPlugin('/build/${rtl}', true);
}
new maplibre.Map({
    container: 'map',
    style: {
        version: 8,
        sources: { pts: { type: 'geojson', data: { type: 'FeatureCollection', features: [
            { type: 'Feature', geometry: { type: 'Point', coordinates: [44.0, 36.3] }, properties: { name: '\\u0623\\u0631\\u0628\\u064A\\u0644' } },
            { type: 'Feature', geometry: { type: 'Point', coordinates: [44.0, 36.1] }, properties: { name: '\\u0647\\u06D5\\u0648\\u0644\\u06CE\\u0631' } },
        ] } } },
        layers: [
            { id: 'bg', type: 'background', paint: { 'background-color': '#e8e6e1' } },
            { id: 'names', type: 'symbol', source: 'pts', layout: { 'text-field': ['get', 'name'], 'text-size': 28 } },
        ],
    },
    center: [44.0, 36.2],
    zoom: 8,
});
const tick = () => { document.title = maplibre.getRTLTextPluginStatus(); requestAnimationFrame(tick); };
tick();
</script>`,
        }),
    );

    await page.goto('/__map-rtl-harness__', { waitUntil: 'domcontentloaded' });

    // The Arabic-script layout must pull the plugin in and complete it.
    await expect
        .poll(async () => page.title(), {
            message: 'laying out Arabic-script text must drive the RTL plugin status to loaded',
            timeout: 20_000,
        })
        .toBe('loaded');

    expect(rtlResponses.length, 'the plugin fetch must be observable').toBeGreaterThan(0);
    expect(rtlResponses[0].status, 'the bundled plugin asset must load cleanly').toBe(200);

    const asset = new URL(rtlResponses[0].url);
    expect(asset.origin, 'the plugin must come from the app origin, never a CDN').toBe(new URL(page.url()).origin);
    expect(asset.pathname, 'the plugin must be a hashed asset of the production build').toContain('/build/assets/');

    expect(diagnostics.failedRequests, 'no request may fail during plugin load').toEqual([]);
});

/* ------------------------------------------ Phase 6: RTL layout by contract */

/*
 * NEW-RTL: the map's RTL LAYOUT — not its text shaping — used to be an
 * accident. postcss-rtlcss processed MapLibre's own stylesheet along with
 * the page chrome, so every physical-position rule the vendor ships
 * (control corners, the canvas anchor, DOM-marker offsets) silently gained
 * a [dir=rtl] mirror. Corners happened to look right; .maplibregl-marker
 * gained a `right: 0` that displaced every DOM marker on an RTL page. The
 * contract pinned here: vendor CSS stays direction-neutral, the page
 * chrome keeps its generated RTL rules, control corners are chosen
 * deliberately in the adapter per document direction, DOM markers land on
 * their coordinates in every locale, and the corners the adapter picks
 * never collide with the page's own floating chrome.
 */

/** The built stylesheets of the app entry, concatenated. */
async function appEntryCss(page: import('@playwright/test').Page): Promise<string> {
    const response = await page.request.get('/build/manifest.json');
    expect(response.status(), 'the build manifest must be served').toBe(200);
    const manifest = (await response.json()) as Record<string, { file?: string; css?: string[] }>;

    // Both shapes: entries whose FILE is a stylesheet (the app.css input)
    // and JS entries carrying a `css` array (the app.ts bundle that holds
    // the MapLibre vendor rules).
    const sheets = Object.values(manifest).flatMap((entry) => [
        ...(entry.css ?? []),
        ...(entry.file?.endsWith('.css') ? [entry.file] : []),
    ]);
    expect(sheets.length, 'the manifest must name the built stylesheets').toBeGreaterThan(0);

    let css = '';
    for (const sheet of [...new Set(sheets)]) {
        const asset = await page.request.get(`/build/${sheet}`);
        expect(asset.status(), `${sheet} must be served`).toBe(200);
        css += await asset.text();
    }

    return css;
}

test('MapLibre vendor CSS ships direction-neutral while page chrome keeps its RTL rules', async ({ page }) => {
    const css = await appEntryCss(page);

    /*
     * The mechanism itself: no [dir=…]-scoped rule may reposition the
     * vendor's physical layout — the corner containers and controls
     * (.maplibregl-ctrl*), the DOM marker (.maplibregl-marker) and the
     * canvas anchor (.maplibregl-canvas). Pre-contract, postcss-rtlcss
     * emitted dozens of exactly those, and every RTL layout fact
     * downstream was a side effect of that list. MapLibre's OWN stylesheet
     * ships a handful of [dir=rtl] popup rules (vendor-authored RTL
     * support, flex-direction only) — those are the vendor's design, not
     * our pipeline's accident, and they stay.
     */
    const flipped = css.match(/\[dir=[^\]]*\][^{}]*\.maplibregl-(?:ctrl|marker|canvas)[^{}]*\{[^}]*\}/g) ?? [];
    expect(flipped, 'no [dir]-scoped rule may reposition MapLibre controls, markers or canvas').toEqual([]);

    /*
     * …and the exclusion must not have widened: the page chrome's own
     * generated RTL rules stay. The design system's eyebrow treatment is
     * an rtlcss COMBINED-mode artifact (uppercase tracking in LTR, none in
     * RTL), so its [dir=rtl] variant existing proves rtlcss still ran on
     * the page's stylesheets — the vendor's own popup rules could not
     * satisfy this. (Minifiers strip the attribute quotes; match both.)
     */
    expect(
        /\[dir=["']?rtl["']?\][^{}]*\.mh-/.test(css),
        'page-chrome rtlcss output must still be generated',
    ).toBe(true);
    expect(css, 'vendor base rules must still ship').toContain('.maplibregl-ctrl-top-right');
});

for (const locale of LOCALES) {
    test(`control corners are deliberate and collision-free on /invest [${locale.code}]`, async ({ page }) => {
        await page.route(STYLE_HOST, (route) =>
            route.fulfill({
                status: 200,
                contentType: 'application/json',
                body: JSON.stringify(DETERMINISTIC_STYLE),
            }),
        );

        await page.goto(`${locale.prefix}/invest`, { waitUntil: 'domcontentloaded' });
        await expect(page.locator('html')).toHaveAttribute('dir', locale.direction);
        await expect(page.locator('.maplibregl-canvas')).toBeVisible({ timeout: 20_000 });

        const map = page.locator('[role="application"]').first();
        const mapBox = await map.boundingBox();
        expect(mapBox).not.toBeNull();
        const midX = mapBox!.x + mapBox!.width / 2;

        /*
         * The corner contract, per direction: zoom at the TOP-END corner,
         * scale at the BOTTOM-START corner, attribution at the BOTTOM-END
         * corner — the exact positions RTL visitors have always seen.
         * Asserted twice over: the RENDERED side (what the visitor sees)
         * and the DOM corner container the adapter added the control to.
         * Pre-contract those disagreed in RTL — the JS said one corner and
         * an rtlcss side effect painted the other — so the membership
         * assertions are the fail-before half, and the rendered-side
         * assertions pin that the visible layout never changed.
         */
        const renderedSide = async (selector: string): Promise<'start' | 'end'> => {
            const box = await page.locator(selector).first().boundingBox();
            expect(box, `${selector} must render`).not.toBeNull();
            const physical = box!.x + box!.width / 2 < midX ? 'left' : 'right';

            if (locale.direction === 'rtl') {
                return physical === 'right' ? 'start' : 'end';
            }

            return physical === 'left' ? 'start' : 'end';
        };

        expect(await renderedSide('.maplibregl-ctrl-zoom-in'), 'zoom control renders at top-end').toBe('end');
        expect(await renderedSide('.maplibregl-ctrl-scale'), 'scale control renders at bottom-start').toBe('start');

        // Physical corner container each control was ADDED to — the
        // adapter's own deliberate choice, direction-resolved.
        const topEnd = locale.direction === 'rtl' ? 'top-left' : 'top-right';
        const bottomStart = locale.direction === 'rtl' ? 'bottom-right' : 'bottom-left';
        const bottomEnd = locale.direction === 'rtl' ? 'bottom-left' : 'bottom-right';

        await expect(
            page.locator(`.maplibregl-ctrl-${topEnd} .maplibregl-ctrl-zoom-in`),
            'the adapter must place the zoom control in the top-end corner container',
        ).toHaveCount(1);
        await expect(
            page.locator(`.maplibregl-ctrl-${bottomStart} .maplibregl-ctrl-scale`),
            'the adapter must place the scale control in the bottom-start corner container',
        ).toHaveCount(1);
        // The attribution control is hidden while the deterministic style
        // carries no attributions, but its DOM placement is still the
        // adapter's choice and still asserted.
        await expect(
            page.locator(`.maplibregl-ctrl-${bottomEnd} .maplibregl-ctrl-attrib`),
            'the adapter must place the attribution control in the bottom-end corner container',
        ).toHaveCount(1);

        /*
         * Collision guard: the page's own floating chrome (the boundaries
         * toggle at start-3 top-3) and the adapter's zoom control must
         * never share pixels, in either direction, at any viewport.
         */
        const toggle = await page.getByRole('button', { name: locale.code === 'en' ? 'Area boundaries' : locale.code === 'ar' ? 'حدود المناطق' : 'سنوورەکانی ناوچە' }).boundingBox();
        const zoom = await page.locator('.maplibregl-ctrl-zoom-in').boundingBox();
        expect(toggle, 'the boundaries toggle must render').not.toBeNull();
        expect(zoom).not.toBeNull();

        const disjoint =
            toggle!.x + toggle!.width <= zoom!.x
            || zoom!.x + zoom!.width <= toggle!.x
            || toggle!.y + toggle!.height <= zoom!.y
            || zoom!.y + zoom!.height <= toggle!.y;
        expect(disjoint, 'floating chrome must not cover the zoom control').toBe(true);

        await expectNoHorizontalOverflow(page);
    });
}

for (const dir of [
    { attr: 'rtl', lang: 'ckb' },
    { attr: 'rtl', lang: 'ar' },
    { attr: 'ltr', lang: 'en' },
]) {
    test(`a DOM marker lands on its coordinates under dir=${dir.attr} lang=${dir.lang}`, async ({ page, diagnostics }) => {
        const files = await buildFiles(page);
        const chunk = files.find((file) => file.includes('maplibre-gl-') && !file.includes('worker'));
        const sw = await page.request.get('/build/sw.js');
        const worker = (await sw.text()).match(/assets\/maplibre-gl-worker-[\w-]+\.js/)?.[0];
        const manifest = (await (await page.request.get('/build/manifest.json')).json()) as Record<string, { css?: string[] }>;
        const sheets = [...new Set(Object.values(manifest).flatMap((entry) => entry.css ?? []))];
        expect(chunk && worker && sheets.length > 0, 'built chunk, worker and css must resolve').toBeTruthy();

        /*
         * Same-origin harness (the technique this file already uses for
         * text shaping), now with the REAL BUILT APP CSS linked and the
         * document direction set — the exact conditions under which the
         * accidental `[dir=rtl] .maplibregl-marker { right: 0 }` displaced
         * every DOM marker. A 10×10 element marker anchored at the map
         * centre must render at the container centre in every direction.
         */
        await page.route('**/__map-rtl-marker-harness__', (route) =>
            route.fulfill({
                status: 200,
                contentType: 'text/html; charset=utf-8',
                body: `<!doctype html><html dir="${dir.attr}" lang="${dir.lang}"><meta charset="utf-8"><title>marker</title>
${sheets.map((sheet) => `<link rel="stylesheet" href="/build/${sheet}">`).join('\n')}
<div id="map" style="width:640px;height:420px;margin:0 auto"></div>
<script type="module">
const maplibre = await import('/build/${chunk}');
maplibre.setWorkerUrl('/build/${worker}');
const map = new maplibre.Map({
    container: 'map',
    style: { version: 8, sources: {}, layers: [{ id: 'bg', type: 'background', paint: { 'background-color': '#e8e6e1' } }] },
    center: [44.0, 36.2],
    zoom: 10,
});
const el = document.createElement('div');
el.id = 'probe-marker';
el.style.cssText = 'width:10px;height:10px;background:#c00;border-radius:50%';
map.on('load', () => {
    new maplibre.Marker({ element: el }).setLngLat([44.0, 36.2]).addTo(map);
    document.title = 'marker-ready';
});
</script></html>`,
            }),
        );

        await page.goto('/__map-rtl-marker-harness__', { waitUntil: 'domcontentloaded' });
        await expect.poll(async () => page.title(), { timeout: 20_000 }).toBe('marker-ready');

        const container = await page.locator('#map').boundingBox();
        const marker = await page.locator('#probe-marker').boundingBox();
        expect(container).not.toBeNull();
        expect(marker, 'the marker element must render').not.toBeNull();

        const containerCentreX = container!.x + container!.width / 2;
        const markerCentreX = marker!.x + marker!.width / 2;
        const containerCentreY = container!.y + container!.height / 2;
        const markerCentreY = marker!.y + marker!.height / 2;

        expect(Math.abs(markerCentreX - containerCentreX), 'marker x must match its coordinate').toBeLessThanOrEqual(8);
        expect(Math.abs(markerCentreY - containerCentreY), 'marker y must match its coordinate').toBeLessThanOrEqual(8);

        expect(diagnostics.failedRequests, 'the harness must load cleanly').toEqual([]);
    });
}
