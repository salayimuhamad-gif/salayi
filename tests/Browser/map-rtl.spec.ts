import { test, expect } from './support/harness';

/*
 * Arabic-script text on the map — the RTL text plugin, behaviorally.
 *
 * MapLibre core ships no bidi/shaping engine; the shared adapter bundles
 * the official plugin and registers it lazily, so the browser-level proof
 * is a chain of observable facts:
 *
 *   1. an Arabic-script label reaching symbol layout makes MapLibre fetch
 *      the plugin — the lazy registration's trigger actually fires;
 *   2. that fetch succeeds AND is served from THIS origin's built assets —
 *      never from unpkg/jsdelivr or any other CDN;
 *   3. the map survives shaping kicking in, and the diagnostics fixture's
 *      teardown holds the whole scenario to zero console errors, zero
 *      uncaught page errors and zero failed requests.
 *
 * The deterministic style is the same zero-request style map-production
 * uses, so nothing here contacts public demo infrastructure. The
 * Arabic-script text comes from the Sorani-named fixture projects
 * (بورجی وەبەرهێنانی تاقیکردنەوە) through the adapter's own point-names
 * layer: its minzoom is 13 and the search flight lands at 15, which is
 * exactly the moment a broken deployment used to show reversed, unjoined
 * letters.
 */

const STYLE_HOST = 'https://demotiles.maplibre.org/**';

const DETERMINISTIC_STYLE = {
    version: 8,
    name: 'deterministic-e2e',
    // No glyphs entry on purpose: maplibre-gl v6 draws label glyphs locally
    // when a style names none, so label layout runs without a font server —
    // and label layout is precisely what triggers the lazy RTL plugin.
    sources: {},
    layers: [{ id: 'bg', type: 'background', paint: { 'background-color': '#e8e6e1' } }],
};

test('an Arabic-script label loads the locally bundled RTL text plugin', async ({ page, diagnostics }) => {
    await page.route(STYLE_HOST, (route) =>
        route.fulfill({
            status: 200,
            contentType: 'application/json',
            body: JSON.stringify(DETERMINISTIC_STYLE),
        }),
    );

    const rtlResponses: Array<{ url: string; status: number }> = [];
    page.on('response', (response) => {
        if (response.url().includes('mapbox-gl-rtl-text')) {
            rtlResponses.push({ url: response.url(), status: response.status() });
        }
    });

    await page.goto('/invest', { waitUntil: 'domcontentloaded' });
    await expect(page.locator('.maplibregl-canvas')).toBeVisible({ timeout: 20_000 });

    // Fly to a Sorani-named project: at the landing zoom the point-names
    // layer lays out Arabic-script text — the lazy plugin's trigger.
    await page.locator('#invest-search').fill('بورجی');
    const results = page.locator('#invest-search-results');
    await expect(results).toBeVisible();
    await results.getByRole('button').filter({ hasText: 'بورجی وەبەرهێنانی تاقیکردنەوە' }).first().click();

    await expect
        .poll(() => rtlResponses.length, {
            message: 'Arabic-script text reaching symbol layout must make MapLibre fetch the RTL plugin',
            timeout: 20_000,
        })
        .toBeGreaterThan(0);

    const first = rtlResponses[0];
    expect(first.status, 'the bundled plugin asset must load cleanly').toBe(200);

    const asset = new URL(first.url);
    expect(asset.origin, 'the plugin must come from the app origin, never a CDN').toBe(new URL(page.url()).origin);
    expect(asset.pathname, 'the plugin must be a hashed asset of the production build').toContain('/build/assets/');

    // The map survives shaping kicking in; the diagnostics fixture holds
    // the scenario to zero console/page errors and zero failed requests on
    // teardown.
    await expect(page.locator('.maplibregl-canvas').first()).toBeVisible();
    expect(diagnostics.failedRequests, 'no request may fail during plugin load').toEqual([]);
});
