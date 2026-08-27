import { execFileSync } from 'node:child_process';
import type { Locator, Page } from '@playwright/test';
import { test, expect, LOCALES, expectNoHorizontalOverflow } from './support/harness';
import { colourDelta, decodePng, type Rgb } from './support/png';

/*
 * Map Phase 4: the Market heatmap on the public explorer.
 *
 * Everything painted is the movement engine's own verdict over PERSISTED
 * fixture rows: the seeded `browser-ankawa` ring carries a sale series of
 * 1190 (2025-07) → 1250 (2026-07) — a genuine +5.04% pair exactly twelve
 * months apart, so the 1y and All windows hold an honest claim while
 * 7d/30d/1m stay honestly unsupported. The polygon must paint the trend
 * green, the filters must disable what the evidence cannot support, rent
 * and typed-category views must answer with an honest reason rather than
 * a fabricated tint, and leaving Market mode must restore the ordinary
 * map. Phase 3's selection keeps working underneath: a painted polygon
 * still opens the Area Intelligence card.
 *
 * Pixel geometry: the explorer opens at (36.19, 44.009) zoom 11 — 2912.7
 * px/°lng, 3609.4 px/°lat. HEAT_PIXEL (−42, −72) ⇒ (43.9946, 36.2099) is
 * the Phase 3 selection pixel: inside the ring, clear of the RTL top-left
 * navigation control, the area's centroid marker and the in-ring project
 * dot (map-area-selection.spec.ts documents the margins). The heat wash is
 * `market-fill` at 0.32 opacity — over the deterministic light background
 * a rising area reads distinctly green, which the samples assert as both
 * a colour change AND a positive green-minus-red channel gap.
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

const AREA_NAME: Record<string, string> = { ckb: 'ئەنکاوە', ar: 'عنكاوة', en: 'Ankawa' };

/** map.market.market per locale — the Market mode button's label. */
const MODE_MARKET: Record<string, string> = { ckb: 'بازاڕ', ar: 'السوق', en: 'Market' };

/** map.layers.areas per locale — the chip Market mode must switch on. */
const AREAS_CHIP: Record<string, string> = { ckb: 'ناوچەکان', ar: 'المناطق', en: 'Areas' };

const HEAT_PIXEL = { dx: -42, dy: -72 };

interface HeatRow {
    area_slug: string;
    direction: string;
    change_percent: string | null;
    currency: string;
}

interface HeatResponse {
    available: boolean;
    reason: string | null;
    windows: Record<string, boolean>;
    rows: HeatRow[];
}

test.beforeAll(() => {
    try {
        execFileSync('php', ['artisan', 'cache:clear'], { stdio: 'ignore' });
    } catch {
        // Remote target or no artisan on this runner: nothing to clear.
    }
});

/** Open /map on the deterministic style and wait for a live canvas. */
async function openExplorer(page: Page, prefix = ''): Promise<Locator> {
    await serveDeterministicStyle(page);
    await page.goto(`${prefix}/map`, { waitUntil: 'domcontentloaded' });
    await expect(page.locator('.maplibregl-canvas')).toBeVisible({ timeout: 20_000 });

    return page.locator('[role="application"]').first();
}

/** Enter Market mode and return the first /map/market payload. */
async function enterMarketMode(page: Page, locale = 'ckb'): Promise<HeatResponse> {
    const heatFetch = page.waitForResponse((response) => response.url().includes('/map/market'));

    await page.getByTestId('map-mode-market').click();

    const response = await heatFetch;
    expect(response.ok()).toBe(true);

    // Entering the mode switches the ordinary areas chip on — the polygons
    // the heat paints arrive through the ordinary layer pipeline.
    await expect(page.getByRole('button', { name: AREAS_CHIP[locale], exact: true }))
        .toHaveAttribute('aria-pressed', 'true');

    return (await response.json()) as HeatResponse;
}

/** Sample the heat pixel from the canvas screenshot. */
async function sampleHeatPixel(page: Page, map: Locator): Promise<Rgb> {
    const box = await map.boundingBox();
    const png = decodePng(await page.locator('.maplibregl-canvas').first().screenshot());

    // Canvas pixels align with CSS pixels on these DPR-1 projects.
    return png.pixelAt(
        Math.round(box!.width / 2 + HEAT_PIXEL.dx),
        Math.round(box!.height / 2 + HEAT_PIXEL.dy),
    );
}

/* ----------------------------------------------- the heatmap, per locale */

for (const locale of LOCALES) {
    test(`market mode paints the honest movement and clears on exit [${locale.code}]`, async ({ page, diagnostics }, testInfo) => {
        testInfo.skip(
            testInfo.project.name !== 'desktop-1440x900',
            'pixel assertions run once per locale, on desktop-1440x900',
        );
        void diagnostics;

        const map = await openExplorer(page, locale.prefix);

        // The mode switch exists (market.intelligence is ON in the seed).
        const modeChip = page.getByTestId('map-mode-market');
        await expect(modeChip).toBeVisible();
        await expect(modeChip).toHaveText(MODE_MARKET[locale.code]);

        /*
         * Baseline WITH the ordinary boundary wash: the areas layer is
         * switched on first — exactly what Market mode itself would do —
         * because that wash legitimately remains when the mode exits.
         * A baseline taken against the bare basemap would read the wash
         * as residual heat. (The auto-enable contract itself is proven
         * from a clean state in the 360 test below.)
         */
        const boundariesFetch = page.waitForResponse((response) =>
            response.url().includes('/map/features')
            && decodeURIComponent(response.url()).includes('layers[]=areas'));
        await page.getByRole('button', { name: AREAS_CHIP[locale.code], exact: true }).click();
        await boundariesFetch;
        await page.waitForTimeout(1_200);

        const before = await sampleHeatPixel(page, map);

        const payload = await enterMarketMode(page, locale.code);

        // The engine's verdict, from PERSISTED rows: browser-ankawa rose
        // 1190 → 1250 across exactly one year — +5.04%, USD, and nothing
        // else in this viewport may claim movement.
        const ankawa = payload.rows.find((row) => row.area_slug === 'browser-ankawa');
        expect(payload.available).toBe(true);
        expect(ankawa?.direction).toBe('up');
        expect(ankawa?.change_percent).toBe('5.04');
        expect(ankawa?.currency).toBe('USD');

        // The window vocabulary reports honestly: a twelve-month pair
        // supports 1y and All; monthly evidence never supports 7d.
        expect(payload.windows['1y']).toBe(true);
        expect(payload.windows.all).toBe(true);
        expect(payload.windows['7d']).toBe(false);
        expect(payload.windows['1m']).toBe(false);

        // The polygon itself turns green: a real colour change with a
        // decisive green-over-red channel gap, sampled inside the ring.
        await expect
            .poll(async () => {
                const tinted = await sampleHeatPixel(page, map);

                return colourDelta(tinted, before) >= 12 && tinted.g - tinted.r >= 15;
            }, { timeout: 15_000, message: 'the rising area must paint green' })
            .toBe(true);

        // The legend states every colour in words, unknown included.
        await expect(page.getByTestId('market-legend')).toBeVisible();

        // Leaving Market mode restores the ordinary map.
        await page.getByTestId('map-mode-explore').click();
        await expect
            .poll(async () => colourDelta(await sampleHeatPixel(page, map), before) <= 8, {
                timeout: 15_000,
                message: 'leaving the mode must clear the tint',
            })
            .toBe(true);
    });
}

/* ------------------------------------------------- honest filter answers */

test('the filters disable unsupported windows and answer honestly, never with a tint', async ({ page, diagnostics }, testInfo) => {
    testInfo.skip(
        testInfo.project.name !== 'desktop-1440x900',
        'the filter contract runs once, on desktop-1440x900 only',
    );
    void diagnostics;

    await openExplorer(page);
    await enterMarketMode(page);

    // Chips mirror the engine's window availability: the pulse panel's own
    // convention — unsupported windows disable, the selected one stays live.
    await expect(page.getByTestId('market-period-7d')).toBeDisabled();
    await expect(page.getByTestId('market-period-1m')).toBeDisabled();
    await expect(page.getByTestId('market-period-1y')).toBeEnabled();

    // 1y holds the same honest pair.
    const yearFetch = page.waitForResponse((response) =>
        response.url().includes('/map/market') && response.url().includes('period=1y'));
    await page.getByTestId('market-period-1y').click();
    const year = (await (await yearFetch).json()) as HeatResponse;
    expect(year.rows.find((row) => row.area_slug === 'browser-ankawa')?.direction).toBe('up');

    // Rent holds nothing here — an honest reason in words, no tint, and
    // never a raw translation key.
    const rentFetch = page.waitForResponse((response) =>
        response.url().includes('/map/market') && response.url().includes('transaction=rent'));
    await page.getByTestId('market-transaction-rent').click();
    const rent = (await (await rentFetch).json()) as HeatResponse;
    expect(rent.available).toBe(false);
    expect(rent.rows).toHaveLength(0);
    const notice = page.getByTestId('market-notice');
    await expect(notice).toBeVisible();
    await expect(notice).not.toContainText('market.');

    // Back to sale; a typed category never lets the spanning index stand
    // in for it — absence, stated, instead of a fabricated claim.
    await page.getByTestId('market-transaction-sale').click();
    const typedFetch = page.waitForResponse((response) =>
        response.url().includes('/map/market') && response.url().includes('property_type=apartment'));
    await page.getByTestId('market-type-apartment').click();
    const typed = (await (await typedFetch).json()) as HeatResponse;
    expect(typed.rows).toHaveLength(0);
    await expect(page.getByTestId('market-notice')).toBeVisible();
});

/* ------------------------------------ Phase 3 selection under the heat */

test('a painted polygon still opens the Area Intelligence card', async ({ page, diagnostics }, testInfo) => {
    testInfo.skip(
        testInfo.project.name !== 'desktop-1440x900',
        'the selection regression runs once, on desktop-1440x900 only',
    );
    void diagnostics;

    const map = await openExplorer(page);
    await enterMarketMode(page);
    await page.waitForTimeout(1_000);

    const box = await map.boundingBox();
    const float = page.getByTestId('area-card-float');

    await expect(async () => {
        await map.click({
            position: { x: box!.width / 2 + HEAT_PIXEL.dx, y: box!.height / 2 + HEAT_PIXEL.dy },
        });
        await expect(float).toBeVisible({ timeout: 2_000 });
    }).toPass({ timeout: 20_000 });

    await expect(float.getByTestId('area-card-name')).toHaveText(AREA_NAME.ckb);
});

/* ----------------------------------------------------- 360 layout holds */

test('market mode holds the 360x800 layout', async ({ page, diagnostics }, testInfo) => {
    testInfo.skip(
        testInfo.project.name !== 'mobile-360x800',
        'the phone-layout pin runs once, at 360x800',
    );
    void diagnostics;

    await openExplorer(page);

    // From a CLEAN state here: entering the mode must itself switch the
    // areas layer on — the polygons the heat paints arrive through the
    // ordinary layer pipeline.
    const heatFetch = page.waitForResponse((response) => response.url().includes('/map/market'));
    await page.getByTestId('map-mode-market').click();
    expect((await heatFetch).ok()).toBe(true);
    await expect(page.getByRole('button', { name: AREAS_CHIP.ckb, exact: true }))
        .toHaveAttribute('aria-pressed', 'true');

    await expect(page.getByTestId('market-controls')).toBeVisible();
    await expect(page.getByTestId('market-legend')).toBeVisible();
    await expectNoHorizontalOverflow(page);
});
