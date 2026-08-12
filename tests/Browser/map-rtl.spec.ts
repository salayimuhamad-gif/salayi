import { test, expect } from './support/harness';

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

const STYLE_HOST = 'https://demotiles.maplibre.org/**';

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
    await home.locator('a.mh-invest-chip').click();
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
    const worker = files.find((file) => file.includes('maplibre-gl-worker'));
    const rtl = files.find((file) => file.includes('mapbox-gl-rtl-text'));
    expect(chunk && worker && rtl, 'chunk, worker and plugin must all be in the manifest').toBeTruthy();

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
