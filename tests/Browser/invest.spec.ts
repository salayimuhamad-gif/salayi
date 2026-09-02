import {
    test, expect, LOCALES,
    expectNoHorizontalOverflow, expectNoDuplicateIds,
} from './support/harness';
import { colourDelta, decodePng, type Rgb } from './support/png';

/** The area-context toggle's label (map.invest.boundaries_label). */
const BOUNDARIES_TOGGLE: Record<string, string> = {
    ckb: 'سنوورەکانی ناوچە',
    ar: 'حدود المناطق',
    en: 'Area boundaries',
};

/*
 * Canvas assertions need the deterministic style, exactly as in
 * map-area-selection.spec.ts and map-market-heatmap.spec.ts: the REAL
 * basemap is a vector style whose source TileJSON lives on an external
 * host, and the hermetic harness answers every external request with an
 * empty 200 — an error BEFORE `load`, which the page answers with its
 * honest provider-failure teardown (overlay + list, no canvas). That
 * degraded path is deliberate and covered elsewhere; here the map must
 * LIVE so the area-context contract can be observed on it.
 */
const STYLE_HOST = '**/map-styles/mulk-dark.json';

const DETERMINISTIC_STYLE = {
    version: 8,
    name: 'deterministic-e2e',
    sources: {},
    layers: [{ id: 'bg', type: 'background', paint: { 'background-color': '#e8e6e1' } }],
};

/*
 * The seeded browser-ankawa centroid (36.225, 43.99) projected from the
 * invest camera centre (36.19, 44.009) at zoom 10 — 1456.35 px/°lng,
 * 1804.7 px/°lat (Web Mercator, 512px tiles, DPR-1 desktop project):
 * dx = (43.99 − 44.009) × 1456.35, dy = −(36.225 − 36.19) × 1804.7.
 */
const AREA_MARK = { dx: -27.7, dy: -63.2 };

/*
 * The Investment Map, in every locale and at every viewport — the first
 * browser coverage any map surface in this product has had.
 *
 * The harness's hermetic network fulfils every EXTERNAL request with an empty
 * body, so the CARTO raster tiles can never load here (the style document
 * itself is served same-origin since Map Phase 1 and does load). That is the
 * point, not a limitation: the contract under test is the degraded path the
 * product promises on Erbil mobile data — with every tile dropped the map
 * keeps its dark ground and stays up, the list still renders real persisted
 * rows, prices and trends included, and nothing freezes or errors. Marker
 * rendering itself is exercised by the geometry and endpoint suites; a tile
 * server would prove pixels, not behaviour.
 */
for (const locale of LOCALES) {
    test.describe(`invest [${locale.code}]`, () => {
        test.beforeEach(async ({ page }) => {
            await page.goto(`${locale.prefix}/invest`, { waitUntil: 'networkidle' });
        });

        test('renders the surface with the correct language and direction', async ({ page }) => {
            await expect(page.locator('html')).toHaveAttribute('lang', locale.code);
            await expect(page.locator('html')).toHaveAttribute('dir', locale.direction);
            await expect(page.locator('h1')).toHaveCount(1);
        });

        /*
         * The blank-map defect class, pinned in a real browser: whatever the
         * network does, the map surface itself must occupy real space. A
         * zero-height container is how "the map does not load" ships while
         * every API test stays green — the div exists, the adapter boots,
         * and nothing is visible.
         */
        test('the map container occupies real space before any tile arrives', async ({ page }) => {
            const box = await page.locator('[role="application"]').first().boundingBox();

            expect(box).not.toBeNull();
            expect(box!.width).toBeGreaterThan(200);
            expect(box!.height).toBeGreaterThanOrEqual(300);
        });

        test('the list carries the seeded projects with price and trend', async ({ page }, testInfo) => {
            const width = testInfo.project.use.viewport?.width ?? 0;

            if (width < 1024) {
                // The list is behind its tab on small screens — that is the
                // design, not an obstacle to route around.
                await page.getByRole('tab').nth(1).click();
            }

            await expect(page.getByText('بورجی وەبەرهێنانی تاقیکردنەوە').first()).toBeVisible();
            await expect(page.getByText('ڤیلاکانی تاقیکردنەوە').first()).toBeVisible();

            // The trend badge renders from the persisted price history:
            // arrow + percentage, never colour alone.
            await expect(page.getByText('4.8%').first()).toBeVisible();
        });

        test('search finds a project and selecting it opens its card', async ({ page }, testInfo) => {
            const width = testInfo.project.use.viewport?.width ?? 0;

            await page.locator('#invest-search').fill('بورجی');

            const results = page.locator('#invest-search-results');
            await expect(results).toBeVisible();
            await results.getByRole('button').first().click();

            const card = page.locator('[role="status"]').filter({ hasText: 'بورجی وەبەرهێنانی تاقیکردنەوە' });

            if (width >= 1024) {
                // Desktop selection surfaces the floating card over the map,
                // price line included, with a real link to the project page.
                await expect(card).toBeVisible();
                await expect(card.getByRole('link')).toHaveAttribute('href', /\/projects\/browser-invest-tower$/);
            } else {
                /*
                 * Below lg the same selection rides in the bottom sheet — the
                 * floating popover must NOT render there. Both halves are
                 * asserted so the popover cannot silently return on phones.
                 */
                const sheet = page.getByRole('dialog', { name: 'بورجی وەبەرهێنانی تاقیکردنەوە' });
                await expect(sheet).toBeVisible();
                await expect(sheet.getByRole('link')).toHaveAttribute('href', /\/projects\/browser-invest-tower$/);
                await expect(card).toHaveCount(0);
            }
        });

        test('selecting from the list keeps the list tab and opens the bottom sheet', async ({ page }, testInfo) => {
            const width = testInfo.project.use.viewport?.width ?? 0;
            testInfo.skip(width >= 1024, 'the tabs and the sheet exist only below lg');

            const listTab = page.getByRole('tab').nth(1);
            await listTab.click();
            await expect(listTab).toHaveAttribute('aria-selected', 'true');

            await page.getByRole('button', { name: /بورجی وەبەرهێنانی تاقیکردنەوە/ }).first().click();

            // The sheet opens OVER the list — selection no longer yanks the
            // visitor to the map tab — and it scroll-locks the page behind it.
            const sheet = page.getByRole('dialog', { name: 'بورجی وەبەرهێنانی تاقیکردنەوە' });
            await expect(sheet).toBeVisible();
            await expect(listTab).toHaveAttribute('aria-selected', 'true');
            expect(await page.evaluate(() => document.body.style.overflow)).toBe('hidden');

            // Escape dismisses, and the scroll-lock is released with it.
            await page.keyboard.press('Escape');
            await expect(sheet).toHaveCount(0);
            expect(await page.evaluate(() => document.body.style.overflow)).toBe('');
        });

        test('a type filter narrows the list', async ({ page }, testInfo) => {
            const width = testInfo.project.use.viewport?.width ?? 0;

            await page.locator('button.mh-invest-chip[aria-pressed]')
                .filter({ hasText: /ڤێلا|Villa|فيلا/ })
                .first()
                .click();

            if (width < 1024) {
                await page.getByRole('tab').nth(1).click();
            }

            await expect(page.getByText('ڤیلاکانی تاقیکردنەوە').first()).toBeVisible();
            await expect(page.getByText('بورجی وەبەرهێنانی تاقیکردنەوە')).toHaveCount(0);
        });

        test('does not scroll sideways and has no duplicate ids', async ({ page }) => {
            await expectNoHorizontalOverflow(page);
            await expectNoDuplicateIds(page);
        });

        /*
         * The production "published area disappears on zoom out" defect: with
         * area context on, the ONLY thing this page rendered for an area was
         * its polygon, and the server legitimately gates polygons below zoom
         * 11 — so crossing the gate erased the area entirely even though the
         * payload still carried its representative point. Pinned here: below
         * the gate the areas layer is still requested, its point row still
         * arrives with an empty polygon collection, the point leaves a real
         * mark on the canvas, and zooming back restores the polygon.
         */
        test('area context survives below the boundary zoom gate', async ({ page }, testInfo) => {
            testInfo.skip(
                testInfo.project.name !== 'desktop-1440x900',
                'pixel sampling and zoom choreography run once per locale, on desktop-1440x900',
            );

            // Reload onto the deterministic style — the shared beforeEach
            // navigated with the real one, which cannot construct a live
            // map under the hermetic harness (see STYLE_HOST above).
            await page.route(STYLE_HOST, (route) =>
                route.fulfill({
                    status: 200,
                    contentType: 'application/json',
                    body: JSON.stringify(DETERMINISTIC_STYLE),
                }));
            await page.goto(`${locale.prefix}/invest`, { waitUntil: 'domcontentloaded' });

            const canvas = page.locator('.maplibregl-canvas').first();
            await expect(canvas).toBeVisible({ timeout: 20_000 });

            /** Sample an 80×80 patch around the seeded area's projected mark. */
            const samplePatch = async (): Promise<Rgb[]> => {
                const box = await canvas.boundingBox();
                const png = decodePng(await canvas.screenshot());
                const cx = Math.round(box!.width / 2 + AREA_MARK.dx);
                const cy = Math.round(box!.height / 2 + AREA_MARK.dy);
                const samples: Rgb[] = [];

                for (let dy = -40; dy <= 40; dy += 2) {
                    for (let dx = -40; dx <= 40; dx += 2) {
                        samples.push(png.pixelAt(cx + dx, cy + dy));
                    }
                }

                return samples;
            };

            const changedPixels = (a: Rgb[], b: Rgb[]): number =>
                a.filter((pixel, index) => colourDelta(pixel, b[index]) > 40).length;

            /*
             * Drop below the gate FIRST, with area context still off — this
             * is the camera every later screenshot shares. Each zoom click
             * waits for its own settled fetch: a second click fired into the
             * first click's ease re-targets from a fractional mid-animation
             * zoom, and the final camera (and every projected pixel the
             * patch below relies on) lands somewhere run-dependent.
             */
            const zoomOut = page.locator('.maplibregl-ctrl-zoom-out');

            const firstStep = page.waitForResponse((response) => response.url().includes('/invest/features'));
            await zoomOut.click();
            expect((await firstStep).ok(), 'the zoom-11 step settles').toBe(true);

            const projectsOnly = page.waitForResponse((response) => {
                if (!response.url().includes('/invest/features')) return false;

                const zoom = Number(new URL(response.url()).searchParams.get('zoom'));

                return Number.isFinite(zoom) && zoom < 11;
            });
            await zoomOut.click();
            expect((await projectsOnly).ok(), 'the zoom-10 step settles').toBe(true);

            // The baseline must be a SETTLED frame: sampled until two
            // consecutive frames agree, so repaint noise can never
            // masquerade as the area's mark.
            let before = await samplePatch();
            await expect
                .poll(async () => {
                    const again = await samplePatch();
                    const settled = changedPixels(again, before) === 0;
                    before = again;

                    return settled;
                }, { timeout: 15_000, message: 'the pre-toggle frame must settle' })
                .toBe(true);

            // Switching context on below the gate must still REQUEST the
            // areas layer, and the answer carries the point with an honestly
            // empty polygon collection.
            const belowGate = page.waitForResponse((response) => {
                if (!response.url().includes('/invest/features')) return false;
                if (!decodeURIComponent(response.url()).includes('layers[]=areas')) return false;

                const zoom = Number(new URL(response.url()).searchParams.get('zoom'));

                return Number.isFinite(zoom) && zoom < 11;
            });

            await page.getByRole('button', { name: BOUNDARIES_TOGGLE[locale.code], exact: true }).click();

            const payload = (await (await belowGate)
                .json()) as { areas: Array<{ slug: string }>; boundaries: { features: unknown[] } };

            expect(payload.areas.length, 'the point row is served below the gate').toBeGreaterThanOrEqual(1);
            expect(payload.boundaries.features, 'the polygon is honestly gated').toHaveLength(0);

            // The point must leave a visible mark where only ground was —
            // the area no longer vanishes with its polygon.
            await expect
                .poll(async () => changedPixels(await samplePatch(), before), {
                    timeout: 15_000,
                    message: 'the area point must mark the map below the boundary gate',
                })
                .toBeGreaterThanOrEqual(4);

            // Back above the gate — one settled step, 10 → 11 — the polygon
            // returns through the ordinary fetch: restored, never re-derived
            // from the point.
            const aboveGate = page.waitForResponse((response) => {
                if (!response.url().includes('/invest/features')) return false;
                if (!decodeURIComponent(response.url()).includes('layers[]=areas')) return false;

                const zoom = Number(new URL(response.url()).searchParams.get('zoom'));

                return Number.isFinite(zoom) && zoom >= 11;
            });

            await page.locator('.maplibregl-ctrl-zoom-in').click();

            const restored = (await (await aboveGate).json()) as { boundaries: { features: unknown[] } };

            expect(restored.boundaries.features.length, 'the polygon returns above the gate').toBeGreaterThanOrEqual(1);
        });
    });
}
