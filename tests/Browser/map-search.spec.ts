import { execFileSync } from 'node:child_process';
import type { Locator, Page } from '@playwright/test';
import { test, expect, expectNoHorizontalOverflow, expectTouchTargets } from './support/harness';

/*
 * Map Phase 5: the unified trilingual search on the public explorer.
 *
 * Everything found is MULK's own seeded data through the ONE stored
 * `search_key` — never a geocoder. The seeded fixtures give each language a
 * real target: the `browser-ankawa` area answers Sorani (ئەنکاوە), the
 * `browser-invest-tower` project answers English (Empire Investment Tower),
 * and the `browser-poi-pharmacy` place answers Arabic (صيدلية نوروز). The
 * specs prove the three navigation contracts — an area lands in the Phase 3
 * canonical selection, a project/place flies the camera and leaves the
 * compact context strip with the real profile route — plus the race guard,
 * the keyboard combobox, Market-mode preservation, and the phone layouts.
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

/** map.layers.* per locale — the group headers reuse the layer vocabulary. */
const AREAS_LABEL: Record<string, string> = { ckb: 'ناوچەکان', ar: 'المناطق', en: 'Areas' };
const PLACES_LABEL: Record<string, string> = { ckb: 'شوێنەکان', ar: 'الأماكن', en: 'Places' };

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

/** Type a query and wait for its /map/search answer to render. */
async function search(page: Page, query: string): Promise<void> {
    const answered = page.waitForResponse(
        (response) => response.url().includes('/map/search') && response.ok(),
    );

    await page.getByTestId('map-search-input').fill(query);
    await answered;
}

/* ------------------------------------------------ area → Phase 3 selection */

test('a Sorani area query lands in the canonical Phase 3 selection', async ({ page, diagnostics }, testInfo) => {
    testInfo.skip(
        testInfo.project.name !== 'desktop-1440x900',
        'the desktop selection contract runs once, on desktop-1440x900',
    );
    void diagnostics;

    await openExplorer(page);
    await search(page, 'ئەنکاوە');

    // Grouped, not one indistinguishable list: the areas header is visible
    // and the fixture area sits under it.
    const listbox = page.getByTestId('map-search-results');
    await expect(listbox.getByText(AREAS_LABEL.ckb, { exact: true })).toBeVisible();

    const option = listbox.getByTestId('map-search-option-area').filter({ hasText: AREA_NAME.ckb }).first();
    const intel = page.waitForResponse((response) => response.url().includes('/location/resolve'));

    await option.click();

    // The dropdown closes and the SAME Area Intelligence card the polygon
    // click opens carries the selection — no search-specific area state.
    await expect(listbox).toBeHidden();
    expect((await intel).ok()).toBe(true);

    const float = page.getByTestId('area-card-float');
    await expect(float).toBeVisible();
    await expect(float.getByTestId('area-card-name')).toHaveText(AREA_NAME.ckb);
});

/* --------------------------------------------- project → fly + real route */

test('an English project query flies the camera and offers the project route', async ({ page, diagnostics }, testInfo) => {
    testInfo.skip(
        testInfo.project.name !== 'desktop-1440x900',
        'the project navigation contract runs once, on desktop-1440x900',
    );
    void diagnostics;

    await openExplorer(page, '/en');
    await search(page, 'Empire');

    const option = page.getByTestId('map-search-option-project')
        .filter({ hasText: 'Empire Investment Tower' })
        .first();

    /*
     * The fly is proven by its consequence: the viewport refetch that
     * follows the camera movement asks for the project's own zoom-15
     * neighbourhood. (The immediate post-choose fetch can still carry the
     * old camera — the moveend fetch after the animation is the one that
     * must bracket the stored coordinate.)
     */
    const followUp = page.waitForRequest((request) => {
        if (!request.url().includes('/map/features')) return false;

        const params = new URL(request.url()).searchParams;
        const north = Number(params.get('north'));
        const south = Number(params.get('south'));
        const east = Number(params.get('east'));
        const west = Number(params.get('west'));

        return Math.abs(Number(params.get('zoom')) - 15) < 0.2
            && south < 36.195 && north > 36.195
            && west < 44.015 && east > 44.015;
    }, { timeout: 20_000 });

    await option.click();
    await followUp;

    // The projects layer stays on (it is the default layer), and the compact
    // context strip names the destination with the REAL public route.
    await expect(page.getByRole('button', { name: 'Projects', exact: true }))
        .toHaveAttribute('aria-pressed', 'true');

    const context = page.getByTestId('map-search-context');
    await expect(context).toBeVisible();
    await expect(context).toContainText('Empire Investment Tower');
    await expect(page.getByTestId('map-search-context-view'))
        .toHaveAttribute('href', '/en/projects/browser-invest-tower');

    // No contradictory selection: choosing a project never leaves an area
    // card claiming a different subject (§25).
    await expect(page.getByTestId('area-card-float')).toBeHidden();
});

/* -------------------------------------- place → category + fly + route */

test('an Arabic place query enables its one category and offers the place route', async ({ page, diagnostics }, testInfo) => {
    testInfo.skip(
        testInfo.project.name !== 'desktop-1440x900',
        'the place navigation contract runs once, on desktop-1440x900',
    );
    void diagnostics;

    await openExplorer(page, '/ar');
    await search(page, 'صيدلية نوروز');

    const option = page.getByTestId('map-search-option-place')
        .filter({ hasText: 'صيدلية نوروز' })
        .first();

    const followUp = page.waitForRequest((request) => {
        if (!request.url().includes('/map/features')) return false;

        const params = new URL(request.url()).searchParams;

        return Math.abs(Number(params.get('zoom')) - 16) < 0.2
            && Number(params.get('south')) < 36.188 && Number(params.get('north')) > 36.188
            && Number(params.get('west')) < 44.012 && Number(params.get('east')) > 44.012;
    }, { timeout: 20_000 });

    await option.click();
    await followUp;

    // The places layer switches on WITH exactly the pharmacy category —
    // never every category, and the school category stays untouched (§27).
    await expect(page.getByRole('button', { name: PLACES_LABEL.ar, exact: true }))
        .toHaveAttribute('aria-pressed', 'true');
    await expect(page.getByRole('button', { name: 'صيدلية', exact: true }))
        .toHaveAttribute('aria-pressed', 'true');
    await expect(page.getByRole('button', { name: 'مدرسة', exact: true }))
        .toHaveAttribute('aria-pressed', 'false');

    await expect(page.getByTestId('map-search-context')).toContainText('صيدلية نوروز');
    await expect(page.getByTestId('map-search-context-view'))
        .toHaveAttribute('href', '/ar/places/browser-poi-pharmacy');
});

/* ------------------------------------------------------- the race guard */

test('a stale slow answer can never overwrite the newest query', async ({ page, diagnostics }, testInfo) => {
    testInfo.skip(
        testInfo.project.name !== 'desktop-1440x900',
        'the race guard runs once, on desktop-1440x900',
    );
    void diagnostics;

    await openExplorer(page);

    /*
     * The older, shorter query is answered LATE and with a distinctive
     * fabricated row, so a stale overwrite would be unmistakable. The
     * newest query goes to the real backend.
     */
    await page.route('**/map/search**', async (route) => {
        const query = new URL(route.request().url()).searchParams.get('q');

        if (query === 'An') {
            await new Promise((resolve) => setTimeout(resolve, 1_500));

            try {
                await route.fulfill({
                    status: 200,
                    contentType: 'application/json',
                    body: JSON.stringify({
                        query: 'An',
                        groups: {
                            areas: [{
                                kind: 'area', slug: 'stale-an', name: 'STALE-AN', type: 'district',
                                type_label: 'district', breadcrumb: [], lat: null, lng: null, bounds: null,
                            }],
                            projects: [],
                            places: [],
                        },
                    }),
                });
            } catch {
                // The page ABORTING the stale request is itself a correct
                // outcome — fulfilling an aborted route just throws here.
            }

            return;
        }

        await route.continue();
    });

    const input = page.getByTestId('map-search-input');

    await input.fill('An');
    // Let the debounce dispatch the doomed request before the query grows.
    await page.waitForRequest((request) => new URL(request.url()).searchParams.get('q') === 'An');

    const latest = page.waitForResponse((response) =>
        response.url().includes('/map/search')
        && new URL(response.url()).searchParams.get('q') === 'Ankawa'
        && response.ok());
    await input.fill('Ankawa');
    await latest;

    const listbox = page.getByTestId('map-search-results');
    await expect(listbox.getByTestId('map-search-option-area').filter({ hasText: AREA_NAME.ckb }).first())
        .toBeVisible();

    // Outlive the delayed stale answer, then look again: the latest query
    // still owns the dropdown.
    await page.waitForTimeout(2_000);
    await expect(listbox.getByText('STALE-AN')).toHaveCount(0);
    await expect(listbox.getByTestId('map-search-option-area').filter({ hasText: AREA_NAME.ckb }).first())
        .toBeVisible();
});

/* ------------------------------------------------- keyboard accessibility */

test('the dropdown is a real keyboard combobox', async ({ page, diagnostics }, testInfo) => {
    testInfo.skip(
        testInfo.project.name !== 'desktop-1440x900',
        'keyboard interaction runs once, on desktop-1440x900',
    );
    void diagnostics;

    await openExplorer(page);
    // ئەنکاوە matches the fixture area AND the in-ring fixture project
    // (پڕۆژەی ناو ئەنکاوە), so arrows have at least two options to walk.
    await search(page, 'ئەنکاوە');

    const input = page.getByTestId('map-search-input');
    const first = page.locator('#map-search-option-0');
    const second = page.locator('#map-search-option-1');

    await input.press('ArrowDown');
    await expect(first).toHaveAttribute('aria-selected', 'true');
    await expect(input).toHaveAttribute('aria-activedescendant', 'map-search-option-0');

    await input.press('ArrowDown');
    await expect(second).toHaveAttribute('aria-selected', 'true');
    await expect(first).toHaveAttribute('aria-selected', 'false');

    await input.press('ArrowUp');
    await expect(first).toHaveAttribute('aria-selected', 'true');

    // Enter chooses the active option — the area, whose canonical card opens.
    const intel = page.waitForResponse((response) => response.url().includes('/location/resolve'));
    await input.press('Enter');
    await expect(page.getByTestId('map-search-results')).toBeHidden();
    expect((await intel).ok()).toBe(true);
    await expect(page.getByTestId('area-card-float')).toBeVisible();

    // Focus reopens the list for the standing query; Escape closes it.
    await input.focus();
    await expect(page.getByTestId('map-search-results')).toBeVisible();
    await input.press('Escape');
    await expect(page.getByTestId('map-search-results')).toBeHidden();
});

/* -------------------------------------------- Market mode is preserved */

test('search navigates without touching Market mode or its filters', async ({ page, diagnostics }, testInfo) => {
    testInfo.skip(
        testInfo.project.name !== 'desktop-1440x900',
        'the Market compatibility contract runs once, on desktop-1440x900',
    );
    void diagnostics;

    await openExplorer(page);

    // Enter Market mode and move a filter off its default.
    const heat = page.waitForResponse((response) => response.url().includes('/map/market'));
    await page.getByTestId('map-mode-market').click();
    expect((await heat).ok()).toBe(true);

    const rentHeat = page.waitForResponse((response) => response.url().includes('/map/market'));
    await page.getByTestId('market-transaction-rent').click();
    expect((await rentHeat).ok()).toBe(true);

    await search(page, 'ئەنکاوە');
    const intel = page.waitForResponse((response) => response.url().includes('/location/resolve'));
    await page.getByTestId('map-search-results')
        .getByTestId('map-search-option-area').filter({ hasText: AREA_NAME.ckb }).first()
        .click();
    expect((await intel).ok()).toBe(true);

    // The searched area is selected OVER the live Market mode: mode, the
    // rent transaction, the default window and the legend all stand.
    await expect(page.getByTestId('area-card-float')).toBeVisible();
    await expect(page.getByTestId('map-mode-market')).toHaveAttribute('aria-pressed', 'true');
    await expect(page.getByTestId('market-transaction-rent')).toHaveAttribute('aria-pressed', 'true');
    await expect(page.getByTestId('market-period-all')).toHaveAttribute('aria-pressed', 'true');
    await expect(page.getByTestId('market-controls')).toBeVisible();
    await expect(page.getByTestId('market-legend')).toBeVisible();
});

/* ----------------------------------------------------- phone layouts */

for (const project of ['mobile-360x800', 'mobile-390x844'] as const) {
    test(`the search dropdown fits and taps at ${project}`, async ({ page, diagnostics }, testInfo) => {
        testInfo.skip(
            testInfo.project.name !== project,
            `the phone-layout pin runs once, at ${project}`,
        );
        void diagnostics;

        await openExplorer(page);
        await search(page, 'ئەنکاوە');

        // The open dropdown adds no sideways scroll and stays inside the
        // viewport, and its rows are honest touch targets.
        await expectNoHorizontalOverflow(page);

        const listbox = page.getByTestId('map-search-results');
        const box = await listbox.boundingBox();
        const viewport = page.viewportSize();
        expect(box).not.toBeNull();
        expect(box!.x).toBeGreaterThanOrEqual(-1);
        expect(box!.x + box!.width).toBeLessThanOrEqual(viewport!.width + 1);
        await expectTouchTargets(page);

        // Tapping a result closes the keyboard's claim on the screen and
        // opens the SAME area intelligence, as the mobile bottom sheet
        // (Phase 3's convention: below lg the sheet dialog IS the card).
        const intel = page.waitForResponse((response) => response.url().includes('/location/resolve'));
        await listbox.getByTestId('map-search-option-area').filter({ hasText: AREA_NAME.ckb }).first().tap();
        expect((await intel).ok()).toBe(true);

        const sheet = page.getByRole('dialog');
        await expect(sheet).toBeVisible();
        await expect(sheet).toContainText(AREA_NAME.ckb);

        // Closing the sheet hands the map back. (The sheet header owns the
        // close control on mobile; its scrim shares the label, so take the
        // header's — the last close button inside the dialog.)
        await sheet.getByRole('button', { name: 'داخستن' }).last().tap();
        await expect(page.getByRole('dialog')).toHaveCount(0);
        await expect(page.locator('.maplibregl-canvas')).toBeVisible();
        await expectNoHorizontalOverflow(page);
    });
}
