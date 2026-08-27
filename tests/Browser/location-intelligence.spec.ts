import type { Page } from '@playwright/test';
import {
    test, expect, LOCALES,
    expectNoHorizontalOverflow,
} from './support/harness';

/*
 * Wave 3, Scenario B: the homepage location-intelligence card, with browser
 * geolocation fully controlled by the test — no run ever depends on the
 * machine's real GPS, and the network stays hermetic (the resolve call is
 * same-origin).
 *
 * THE HEADLINE ASSERTION is a negative one: geolocation is requested ONLY
 * after the visitor taps "Use My Location". A probe installed before any page
 * script counts every call to `getCurrentPosition`, so "the page loaded and
 * asked for nothing" is measured, not assumed.
 *
 * Everything the card then shows is a PERSISTED fixture: the seeded
 * `browser-ankawa` polygon (36.205–36.245 × 43.960–44.004) and its one
 * published sale index (USD 1,250, period 2026-07) — rendered through the
 * real resolver and the real price selection, never injected client-side.
 */

/** Inside the seeded browser-ankawa polygon. */
const INSIDE = { latitude: 36.225, longitude: 43.99 };

/** Inside the Erbil operating area, outside every seeded polygon. */
const OUTSIDE = { latitude: 36.1, longitude: 44.2 };

/** The seeded area's trilingual names — asserting real localized fields. */
const AREA_NAME: Record<string, string> = { ckb: 'ئەنکاوە', ar: 'عنكاوة', en: 'Ankawa' };

declare global {
    interface Window {
        __geoRequests?: number;
    }
}

/**
 * Counts calls to navigator.geolocation.getCurrentPosition WITHOUT changing
 * its behaviour — installed before any application script runs.
 */
async function armGeolocationProbe(page: Page): Promise<void> {
    await page.addInitScript(() => {
        window.__geoRequests = 0;

        if (typeof navigator === 'undefined' || !navigator.geolocation) {
            return;
        }

        const geolocation = navigator.geolocation;
        const original = geolocation.getCurrentPosition.bind(geolocation);

        geolocation.getCurrentPosition = (...args: Parameters<Geolocation['getCurrentPosition']>) => {
            window.__geoRequests = (window.__geoRequests ?? 0) + 1;

            return original(...args);
        };
    });
}

async function geolocationRequests(page: Page): Promise<number> {
    return await page.evaluate(() => window.__geoRequests ?? -1);
}

/*
 * ISOLATION FROM AN UNRELATED SHARED RATE BUDGET (main CI #224).
 *
 * Every homepage load mounts the Pricing Intelligence Map, whose one
 * features fetch spends the production `map-features` limiter (60/min per
 * IP) — the SAME bucket invest.spec drains for its own subject moments
 * earlier in the suite order. This spec loads the homepage 21 times per
 * viewport project, and the rolling 60-second window straddling the two
 * specs was measured at 58 of the 60-request budget: runner pacing decided
 * whether the 61st request fired, and when it did, the map's fetch answered
 * 429 — handled silently by the surface, but the browser console line
 * failed the zero-console-error gate, always mid-suite (the Arabic block).
 *
 * The map is not this spec's subject, so its fetch is fulfilled here with
 * the endpoint's own zero-state envelope (a real, valid empty answer — the
 * map renders its honest empty state; fulfilling rather than aborting keeps
 * the console clean without ignoring anything). The REAL endpoints and the
 * real limiter stay fully exercised where they are the subject:
 * invest.spec, map-production.spec and public-home.spec run uninterception.
 * No limiter, assertion, or application behaviour changes here — this spec
 * simply stops spending an unrelated budget.
 */
const EMPTY_FEATURES_ENVELOPE = {
    projects: [],
    places: [],
    offers: [],
    areas: [],
    companies: [],
    prices: [],
    project_boundaries: { type: 'FeatureCollection', features: [] },
    boundaries: { type: 'FeatureCollection', features: [] },
    boundary_zoom_threshold: 11,
    truncated: false,
    distance: { unit: 'km', method: 'straight_line', travel_time_available: false, applied: false },
};

test.beforeEach(async ({ page }) => {
    for (const pattern of ['**/invest/features*', '**/map/features*']) {
        await page.route(pattern, (route) =>
            route.fulfill({
                status: 200,
                contentType: 'application/json',
                body: JSON.stringify(EMPTY_FEATURES_ENVELOPE),
            }),
        );
    }
});

for (const locale of LOCALES) {
    test.describe(`location intelligence [${locale.code}]`, () => {
        test('never requests geolocation on load — only after the visitor asks', async ({ page, diagnostics }) => {
            await armGeolocationProbe(page);
            await page.context().grantPermissions(['geolocation']);
            await page.context().setGeolocation(INSIDE);

            await page.goto(`${locale.prefix}/`, { waitUntil: 'networkidle' });

            // Loaded, hydrated, revealed — and the page asked for nothing.
            await page.getByTestId('use-my-location').scrollIntoViewIfNeeded();
            expect(await geolocationRequests(page), 'geolocation requests before any click').toBe(0);

            await page.getByTestId('use-my-location').click();

            // The click is the FIRST and only request.
            await expect(page.getByTestId('resolved-area')).toBeVisible();
            expect(await geolocationRequests(page), 'geolocation requests after the click').toBe(1);

            expect(diagnostics.failedRequests, 'failed network requests').toEqual([]);
        });

        test('allowed location resolves the published polygon and its real price', async ({ page, diagnostics }) => {
            void diagnostics;
            await armGeolocationProbe(page);
            await page.context().grantPermissions(['geolocation']);
            await page.context().setGeolocation(INSIDE);

            await page.goto(`${locale.prefix}/`, { waitUntil: 'networkidle' });
            await page.getByTestId('use-my-location').click();

            const resolved = page.getByTestId('resolved-area');
            await expect(resolved).toBeVisible();
            // The localized name from the area's own trilingual fields.
            await expect(resolved).toContainText(AREA_NAME[locale.code]);

            // The REAL seeded figure, with its currency — never a fabricated
            // number, never a bare unlabelled one.
            const prices = page.getByTestId('location-prices');
            await expect(prices).toContainText('1,250');
            await expect(prices).toContainText('USD');
            await expect(prices).toContainText('2026-07');

            // Every label in the price block is a translated string — a raw
            // market.* or home.* key leaking through (e.g. an evidence level
            // outside the product's vocabulary) is a defect this must catch.
            await expect(prices).not.toContainText('market.');
            await expect(prices).not.toContainText('home.');

            // RTL and LTR alike, the card must not push the page sideways.
            await expectNoHorizontalOverflow(page);
        });

        test('denied permission is a graceful localized state, not a trap', async ({ page, diagnostics }) => {
            void diagnostics;
            await armGeolocationProbe(page);
            // Granting an empty set rejects everything else — the geolocation
            // request is DENIED outright. (The default "prompt" state would
            // hang a headless browser instead; the card's own watchdog covers
            // that live, but the deny path must be tested as a real denial.)
            await page.context().grantPermissions([]);

            await page.goto(`${locale.prefix}/`, { waitUntil: 'networkidle' });
            await page.getByTestId('use-my-location').click();

            const failure = page.getByTestId('location-failure');
            await expect(failure).toBeVisible();
            await expect(failure).not.toBeEmpty();
            // A missing translation renders its raw key — that is a failure,
            // not a localized state.
            await expect(failure).not.toContainText('home.location.');

            // No area, no price — nothing was invented to fill the gap.
            await expect(page.getByTestId('resolved-area')).toHaveCount(0);

            // The manual fallback stays live: the visitor is never stuck.
            const manual = page.getByTestId('choose-area-manually');
            await expect(manual).toBeEnabled();
            await manual.click();
            await expect(page.getByTestId('manual-area-select')).toBeVisible();
        });

        test('a location outside every published polygon answers honestly', async ({ page, diagnostics }) => {
            void diagnostics;
            await armGeolocationProbe(page);
            await page.context().grantPermissions(['geolocation']);
            await page.context().setGeolocation(OUTSIDE);

            await page.goto(`${locale.prefix}/`, { waitUntil: 'networkidle' });
            await page.getByTestId('use-my-location').click();

            await expect(page.getByTestId('outside-coverage')).toBeVisible();

            // NO nearest-match fabrication: the seeded area is ~9 km away and
            // its name must appear nowhere in the answer. The check is scoped
            // to the location card itself — since Wave 4 the homepage
            // legitimately names areas in unrelated published market content
            // (the metric cards render each index's Sorani-first name), and
            // asserting over all of <main> would measure that content, not
            // this answer.
            await expect(page.getByTestId('resolved-area')).toHaveCount(0);
            await expect(page.getByTestId('location-intelligence')).not.toContainText(AREA_NAME[locale.code]);
        });

        test('the manual published-area fallback renders the same honest presentation', async ({ page, diagnostics }) => {
            void diagnostics;
            await armGeolocationProbe(page);

            await page.goto(`${locale.prefix}/`, { waitUntil: 'networkidle' });
            await page.getByTestId('choose-area-manually').click();

            const select = page.getByTestId('manual-area-select');
            await expect(select).toBeVisible();
            await select.selectOption('browser-ankawa');

            const resolved = page.getByTestId('resolved-area');
            await expect(resolved).toBeVisible();
            await expect(resolved).toContainText(AREA_NAME[locale.code]);
            await expect(page.getByTestId('location-prices')).toContainText('1,250');

            // Manual selection is a choice, not a location claim — and it
            // must never have asked the browser where the visitor is.
            expect(await geolocationRequests(page)).toBe(0);

            await expectNoHorizontalOverflow(page);
        });

        test('remains usable at the owner-configurable 120% text scale', async ({ page, diagnostics }) => {
            void diagnostics;
            await armGeolocationProbe(page);
            await page.context().grantPermissions(['geolocation']);
            await page.context().setGeolocation(INSIDE);

            await page.goto(`${locale.prefix}/`, { waitUntil: 'networkidle' });

            // The same CSS token the owner setting writes (typography.public_scale
            // → --mh-type-scale, consumed by public.css), at its maximum.
            await page.evaluate(() => {
                document.documentElement.style.setProperty('--mh-type-scale', '120');
            });

            const locate = page.getByTestId('use-my-location');
            await locate.scrollIntoViewIfNeeded();

            // Touch targets keep the 44px floor when type grows.
            for (const id of ['use-my-location', 'choose-area-manually']) {
                const box = await page.getByTestId(id).boundingBox();
                expect(box, `${id} rendered`).not.toBeNull();
                expect(box!.height, `${id} touch height at 120%`).toBeGreaterThanOrEqual(44);
            }

            await locate.click();
            await expect(page.getByTestId('resolved-area')).toBeVisible();
            await expectNoHorizontalOverflow(page);
        });

        test('the valuation CTA exists behind its flag and follows the real auth gate', async ({ page, diagnostics }) => {
            void diagnostics;
            await armGeolocationProbe(page);

            await page.goto(`${locale.prefix}/`, { waitUntil: 'networkidle' });

            // portfolio = ON in the seed, so the CTA exists; it points INTO
            // the product (the portfolio flow), never at an external host.
            const cta = page.getByTestId('valuation-cta');
            await cta.scrollIntoViewIfNeeded();
            await expect(cta).toBeVisible();

            const href = await cta.getAttribute('href');
            expect(href).toBe('/account/portfolio');

            // A guest is handed to the REAL login flow by the route's own
            // auth middleware — the card adds no gate of its own.
            await cta.click();
            await page.waitForURL('**/login**');
            expect(new URL(page.url()).pathname.endsWith('/login')).toBe(true);

            expect(await geolocationRequests(page)).toBe(0);
        });
    });
}
