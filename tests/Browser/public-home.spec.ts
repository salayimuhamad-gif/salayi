import {
    test, expect, LOCALES,
    expectNoHorizontalOverflow, expectNoDuplicateIds, expectNoTextClipping,
} from './support/harness';

/*
 * The homepage, in every locale and at every viewport.
 *
 * The assertions about what is ABSENT matter as much as the ones about what is
 * present. This product's central promise is that it shows real published data
 * and nothing else, so a homepage that quietly grew a stock photograph or a
 * decorative chart would be a regression against the product, not just the
 * design.
 */
for (const locale of LOCALES) {
    test.describe(`home [${locale.code}]`, () => {
        test.beforeEach(async ({ page }) => {
            await page.goto(`${locale.prefix}/`, { waitUntil: 'networkidle' });
        });

        test('renders the shell and exactly one h1', async ({ page, diagnostics }) => {
            await expect(page.locator('.mh-luxury-public')).toBeVisible();
            await expect(page.locator('h1')).toHaveCount(1);
            expect(diagnostics.failedRequests, 'failed network requests').toEqual([]);
        });

        test('carries the correct language and direction', async ({ page }) => {
            await expect(page.locator('html')).toHaveAttribute('lang', locale.code);
            await expect(page.locator('html')).toHaveAttribute('dir', locale.direction);
        });

        test('does not scroll sideways', async ({ page }) => {
            await expectNoHorizontalOverflow(page);
        });

        test('has no duplicate DOM ids', async ({ page }) => {
            // Regression guard: the AI avatar renders repeatedly and its SVG
            // gradient ids were once fixed strings.
            await expectNoDuplicateIds(page);
        });

        test('does not clip its own text', async ({ page }) => {
            await expectNoTextClipping(page);
        });

        test('presents no photographic or charted data', async ({ page }) => {
            // The server sends no image field and one period per index, so an
            // <img> or a chart primitive here would be invented. The ONE
            // sanctioned canvas is the Pricing Intelligence Map's own
            // MapLibre surface (redesign §5.4 — now homepage section 2, so
            // its lazy mount can fire without scrolling on tall viewports);
            // every identity motif ships as inline SVG paths or CSS
            // backgrounds precisely so this gate keeps meaning what it says.
            //
            // Wave 4 adds exactly ONE sanctioned polyline source: the Market
            // Movement panel's sparkline, which renders nothing but genuine
            // published observations (its own spec and the backend suite hold
            // that line). Its fetch is intersection-driven, so whether it has
            // mounted here depends on the viewport — the gate must not. Any
            // polyline OUTSIDE a movement sparkline is still an invention.
            await expect(page.locator('main img')).toHaveCount(0);
            await expect(
                page.locator('main polyline:not([data-testid="movement-sparkline"] polyline)'),
            ).toHaveCount(0);
            await expect(page.locator('main canvas:not(.maplibregl-canvas)')).toHaveCount(0);
        });
    });
}
