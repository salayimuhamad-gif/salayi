import { execFileSync } from 'node:child_process';
import type { Locator, Page } from '@playwright/test';
import { test, expect, expectNoHorizontalOverflow, type PageDiagnostics } from './support/harness';

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
 * Map Phase 6: the area comparison on the public explorer.
 *
 * Everything compared is seeded evidence through the existing authorities:
 * `browser-ankawa` (ring, services, a spanning sale index with a genuine
 * +5.04% all-window pair), `mv-kasnazan`/`mv-baharka` (the Wave 4 movement
 * areas whose apartment sale series form a COMPATIBLE pair — one rising,
 * one declining), and `browser-dinar` (a single IQD value whose identity
 * matches Ankawa's index in everything but currency, so direct comparison
 * must be refused with the currency reason, never converted). The specs
 * prove the Phase 5 search doubles as the picker, the 2–3 bounds, filter
 * changes preserving the selected set, honest incompatibility wording,
 * raw service counts, and the phone layouts.
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

/** Type an area query and wait for its /map/search answer to render. */
async function search(page: Page, query: string): Promise<void> {
    const answered = page.waitForResponse(
        (response) => response.url().includes('/map/search') && response.ok(),
    );

    await page.getByTestId('map-search-input').fill(query);
    await answered;
}

/** Add one area to the comparison through the Phase 5 search. */
async function addArea(page: Page, query: string, slug: string): Promise<void> {
    await search(page, query);
    await page.getByTestId('map-search-results')
        .locator(`[data-testid="map-search-option-area"][data-slug="${slug}"]`)
        .first()
        .click();
}

/* --------------------------------------- the core two-area comparison */

test('compare mode builds a two-area comparison through the Phase 5 search', async ({ page, diagnostics }, testInfo) => {
    testInfo.skip(
        testInfo.project.name !== 'desktop-1440x900',
        'the desktop comparison contract runs once, on desktop-1440x900',
    );
    void diagnostics;

    await openExplorer(page);
    await page.getByTestId('map-mode-compare').click();

    // The picker IS the Phase 5 search, narrowed to areas: a query that
    // matches a project in Explore mode offers ONLY areas here.
    await search(page, 'ئەنکاوە');
    const listbox = page.getByTestId('map-search-results');
    await expect(listbox.getByTestId('map-search-option-area').first()).toBeVisible();
    await expect(listbox.getByTestId('map-search-option-project')).toHaveCount(0);

    /*
     * The first add: one slot, an honest "choose two" hint, and the
     * fit-to-compared camera framing the ring — proven by its consequence,
     * the ZOOMED-IN viewport refetch that still brackets the whole seeded
     * ring. Measured live at 1440×900 the fit lands at z≈12.63 with west
     * ≈43.946 — the page-load fetch at z11 spans half the city (west
     * ≈43.898), so z≥12 with a west edge inside (43.9, 43.96) uniquely
     * identifies the fitted camera.
     */
    const fitFetch = page.waitForRequest((request) => {
        if (!request.url().includes('/map/features')) return false;

        const params = new URL(request.url()).searchParams;
        const west = Number(params.get('west'));

        return Number(params.get('zoom')) >= 12
            && west < 43.96 && west > 43.9
            && Number(params.get('east')) > 44.004
            && Number(params.get('south')) < 36.205 && Number(params.get('north')) > 36.245;
    }, { timeout: 20_000 });

    await listbox.getByTestId('map-search-option-area').first().click();
    await expect(page.getByTestId('compare-slot-A')).toContainText('ئەنکاوە');
    await expect(page.getByTestId('compare-hint')).toBeVisible();
    await fitFetch;

    // The second add completes the comparison.
    const compared = page.waitForResponse((response) => response.url().includes('/map/compare') && response.ok());
    await addArea(page, 'کەسنەزان', 'mv-kasnazan');
    expect((await compared).ok()).toBe(true);

    await expect(page.getByTestId('compare-slot-B')).toContainText('کەسنەزان');
    const grid = page.getByTestId('compare-grid');
    await expect(grid).toBeVisible();

    // Ankawa's spanning index holds a genuine all-window claim (+5.04%);
    // Kasnazan holds none under the all-categories view — shown as honest
    // "not enough evidence", never flat, while its column still renders.
    await expect(grid).toContainText('+5.04%');
    await expect(grid).toContainText('بەڵگەی بەراوردکردن تەواو نییە');

    // Services are the SAME counts the profile shows: education 1 for the
    // ring (the seeded school), "0 recorded" for the movement area.
    const education = page.getByTestId('compare-service-education');
    await expect(education).toContainText('1');
    await expect(education).toContainText('0 تۆمارکراو');

    // Real profile routes, never internal ids.
    await expect(page.getByTestId('compare-view-browser-ankawa'))
        .toHaveAttribute('href', '/areas/browser-ankawa');
});

/* ------------------------- compatible evidence and preserved selection */

test('compatible apartment evidence compares factually and filters preserve the set', async ({ page, diagnostics }, testInfo) => {
    testInfo.skip(
        testInfo.project.name !== 'desktop-1440x900',
        'the compatibility contract runs once, on desktop-1440x900',
    );
    void diagnostics;

    await openExplorer(page);
    await page.getByTestId('map-mode-compare').click();

    await addArea(page, 'کەسنەزان', 'mv-kasnazan');
    const firstCompare = page.waitForResponse((response) => response.url().includes('/map/compare') && response.ok());
    await addArea(page, 'بەهارکە', 'mv-baharka');
    await firstCompare;

    // Narrow to the apartment category the two series share: a COMPATIBLE
    // signature — one rising, one declining — so the facts are factual
    // observations, never a winner or a score.
    const typed = page.waitForResponse((response) => response.url().includes('/map/compare') && response.ok());
    await page.getByTestId('market-type-apartment').click();
    expect((await typed).ok()).toBe(true);

    const facts = page.getByTestId('compare-facts');
    await expect(facts).toBeVisible();
    // Diverged movement, named per direction…
    await expect(facts).toContainText('کەسنەزان');
    await expect(facts).toContainText('بەهارکە');
    // …and the compatible price gap, computed server-side: 121,000 vs
    // 184,000 → 52.07% apart.
    await expect(facts).toContainText('52.07');
    await expect(page.getByText('winner', { exact: false })).toHaveCount(0);

    // §38: changing the window refreshes the market answer only — both
    // slots stand exactly as chosen.
    const rewindowed = page.waitForResponse((response) => response.url().includes('/map/compare') && response.ok());
    await page.getByTestId('market-period-30d').click();
    expect((await rewindowed).ok()).toBe(true);
    await expect(page.getByTestId('compare-slot-A')).toContainText('کەسنەزان');
    await expect(page.getByTestId('compare-slot-B')).toContainText('بەهارکە');

    // Sale → Rent likewise: only Kasnazan holds rent evidence, the
    // comparison degrades honestly, the selection survives.
    const rented = page.waitForResponse((response) => response.url().includes('/map/compare') && response.ok());
    await page.getByTestId('market-transaction-rent').click();
    expect((await rented).ok()).toBe(true);
    await expect(page.getByTestId('compare-slot-A')).toContainText('کەسنەزان');
    await expect(page.getByTestId('compare-slot-B')).toContainText('بەهارکە');
    await expect(page.getByTestId('compare-panel')).toBeVisible();
});

/* --------------------------------------------- throttling is not failure */

/*
 * F-2 (map RC hardening): a throttled /map/compare answer must speak the
 * dedicated rate-limited voice — never the error state with its retry —
 * while the selected A/B slots, the LAST comparison payload and every
 * filter stand exactly as they were.
 */
test('a throttled comparison refresh says wait and keeps slots, data and filters', async ({ page, diagnostics }, testInfo) => {
    testInfo.skip(
        testInfo.project.name !== 'desktop-1440x900',
        'the throttle contract runs once, on desktop-1440x900 only',
    );

    await openExplorer(page);
    await page.getByTestId('map-mode-compare').click();

    await addArea(page, 'کەسنەزان', 'mv-kasnazan');
    const compared = page.waitForResponse((response) => response.url().includes('/map/compare') && response.ok());
    await addArea(page, 'بەهارکە', 'mv-baharka');
    await compared;

    const grid = page.getByTestId('compare-grid');
    await expect(grid).toBeVisible();

    // Narrow to the apartment series both areas share — that comparison
    // carries 30-day movement, so the 30d window chip becomes available
    // for the throttled round below.
    const typed = page.waitForResponse((response) => response.url().includes('/map/compare') && response.ok());
    await page.getByTestId('market-type-apartment').click();
    await typed;
    await expect(page.getByTestId('market-period-30d')).toBeEnabled();

    // From here the limiter "answers" the comparison endpoint.
    await page.route('**/map/compare**', (route) =>
        route.fulfill({ status: 429, contentType: 'application/json', body: '{}' }));

    await page.getByTestId('market-period-30d').click();

    // The dedicated voice, verbatim in Sorani — and only that voice.
    await expect(page.getByTestId('compare-rate-limited'))
        .toHaveText('داواکاری زۆرە — کەمێک چاوەڕوان بە؛ ناوچە هەڵبژێردراوەکان هەر دەمێننەوە.');
    await expect(page.getByTestId('compare-error')).toHaveCount(0);
    await expect(page.getByTestId('compare-retry')).toHaveCount(0);

    // Slots, last comparison and filters all stand.
    await expect(page.getByTestId('compare-slot-A')).toContainText('کەسنەزان');
    await expect(page.getByTestId('compare-slot-B')).toContainText('بەهارکە');
    await expect(grid).toBeVisible();
    await expect(page.getByTestId('market-period-30d')).toHaveAttribute('aria-pressed', 'true');
    await expect(page.getByTestId('market-type-apartment')).toHaveAttribute('aria-pressed', 'true');
    await expect(page.getByTestId('map-mode-compare')).toHaveAttribute('aria-pressed', 'true');

    // The limiter relents: the next pick recovers the ordinary voice.
    await page.unroute('**/map/compare**');

    const recovered = page.waitForResponse((response) =>
        response.url().includes('/map/compare') && response.ok());
    await page.getByTestId('market-period-all').click();
    expect((await recovered).ok()).toBe(true);
    await expect(page.getByTestId('compare-rate-limited')).toHaveCount(0);
    await expect(grid).toBeVisible();

    // Exactly the one injected 429 and nothing else reached the console.
    consumeRateLimitConsole(diagnostics, 1);
});

/* ------------------------------------------------ 2–3 bounds and echo */

test('a third area joins and a fourth or duplicate is refused in words', async ({ page, diagnostics }, testInfo) => {
    testInfo.skip(
        testInfo.project.name !== 'desktop-1440x900',
        'the bounds contract runs once, on desktop-1440x900',
    );
    void diagnostics;

    await openExplorer(page);
    await page.getByTestId('map-mode-compare').click();

    await addArea(page, 'ئەنکاوە', 'browser-ankawa');
    await addArea(page, 'کەسنەزان', 'mv-kasnazan');
    const threeWay = page.waitForResponse((response) => response.url().includes('/map/compare') && response.ok());
    await addArea(page, 'بەهارکە', 'mv-baharka');
    await threeWay;

    await expect(page.getByTestId('compare-slot-C')).toContainText('بەهارکە');
    await expect(page.getByTestId('compare-add')).toHaveCount(0);

    // A fourth DISTINCT area is refused in words — three is the ceiling.
    await addArea(page, 'دینار', 'browser-dinar');
    await expect(page.getByTestId('compare-notice')).toContainText('سێ ناوچە');
    await expect(page.getByTestId('compare-slot-C')).toContainText('بەهارکە');

    // A duplicate likewise, never silently absorbed (§9).
    await addArea(page, 'ئەنکاوە', 'browser-ankawa');
    await expect(page.getByTestId('compare-notice')).toContainText('پێشتر لە بەراوردکردنەکەدایە');

    // Removing one hands its letter to the next in order.
    const rePair = page.waitForResponse((response) => response.url().includes('/map/compare') && response.ok());
    await page.getByTestId('compare-remove-browser-ankawa').click();
    await rePair;
    await expect(page.getByTestId('compare-slot-A')).toContainText('کەسنەزان');
    await expect(page.getByTestId('compare-slot-B')).toContainText('بەهارکە');
});

/* ------------------------------------------- honest incompatibility */

test('a currency mismatch is refused with its reason, never converted', async ({ page, diagnostics }, testInfo) => {
    testInfo.skip(
        testInfo.project.name !== 'desktop-1440x900',
        'the incompatibility contract runs once, on desktop-1440x900',
    );
    void diagnostics;

    await openExplorer(page, '/en');
    await page.getByTestId('map-mode-compare').click();

    await addArea(page, 'Ankawa', 'browser-ankawa');
    const compared = page.waitForResponse((response) => response.url().includes('/map/compare') && response.ok());
    await addArea(page, 'Dinar', 'browser-dinar');
    await compared;

    const panel = page.getByTestId('compare-panel');

    // Both figures render, each in its own currency…
    await expect(panel).toContainText('1250.0000 USD');
    await expect(panel).toContainText('1650000.0000 IQD');

    // …and the difference is REFUSED with the reason — no conversion, no
    // price percentage, no ranking. (Ankawa's own movement claim keeps its
    // percent; the refusal is about comparing the two figures.)
    const facts = page.getByTestId('compare-facts');
    await expect(facts).toContainText('the currencies differ');
    await expect(facts).not.toContainText('higher than');
});

/* ----------------------------------------------------- phone layouts */

for (const project of ['mobile-360x800', 'mobile-390x844'] as const) {
    test(`the comparison stacks and stays usable at ${project}`, async ({ page, diagnostics }, testInfo) => {
        testInfo.skip(
            testInfo.project.name !== project,
            `the phone-layout pin runs once, at ${project}`,
        );
        void diagnostics;

        await openExplorer(page);
        await page.getByTestId('map-mode-compare').click();

        await addArea(page, 'ئەنکاوە', 'browser-ankawa');
        const compared = page.waitForResponse((response) => response.url().includes('/map/compare') && response.ok());
        await addArea(page, 'کەسنەزان', 'mv-kasnazan');
        await compared;

        // The comparison lives in the list tab as stacked A/B cards —
        // never a squeezed three-column table — and the page never scrolls
        // sideways.
        await page.getByRole('tab', { name: 'لیست' }).click();
        await expect(page.getByTestId('compare-stack')).toBeVisible();
        await expect(page.getByTestId('compare-grid')).toBeHidden();
        await expectNoHorizontalOverflow(page);

        // The map stays one tap away (§36).
        await page.getByRole('tab', { name: 'نەخشە' }).click();
        await expect(page.locator('.maplibregl-canvas')).toBeVisible();
        await expectNoHorizontalOverflow(page);
    });
}

/* ------------------------------------------------------ Arabic smoke */

test('the comparison renders in Arabic', async ({ page, diagnostics }, testInfo) => {
    testInfo.skip(
        testInfo.project.name !== 'laptop-1366x768',
        'the Arabic pass runs once, on laptop-1366x768',
    );
    void diagnostics;

    await openExplorer(page, '/ar');
    await page.getByTestId('map-mode-compare').click();

    await addArea(page, 'عنكاوة', 'browser-ankawa');
    const compared = page.waitForResponse((response) => response.url().includes('/map/compare') && response.ok());
    await addArea(page, 'كسنزان', 'mv-kasnazan');
    await compared;

    await expect(page.getByTestId('compare-slot-A')).toContainText('عنكاوة');
    await expect(page.getByTestId('compare-grid')).toBeVisible();
    await expect(page.getByTestId('compare-grid')).toContainText('حركة السوق');
    await expectNoHorizontalOverflow(page);
});
