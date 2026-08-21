import { test, expect, LOCALES, expectNoHorizontalOverflow, expectTouchTargets } from './support/harness';
import { fixtures, signIn, clearRateLimiter } from './support/fixtures';

/*
 * Wave 5 — the profile and the portfolio, end to end against the REAL
 * account routes and the seeded fixture rows. Nothing on the subject path is
 * intercepted: every figure on the dashboard was summed by
 * PortfolioSummaryService from persisted portfolio_valuations rows, and
 * every profile edit round-trips the real controller with its real rules.
 *
 * The fixture portfolio is the proof material: a two-point USD history (the
 * only chartable series), a second USD figure making the same-currency total
 * a real sum (165,000 + 85,000 = 250,000), an IQD figure that must stay in
 * its own group, and one property with no valuation at all — awaiting, never
 * zero.
 *
 * Only the UNRELATED homepage map endpoints are fulfilled with their valid
 * empty envelope (the Wave 3 pattern): the brief landing on the shell must
 * not spend a production limiter budget that belongs to the map specs.
 */

const KASNAZAN = { ckb: 'کەسنەزان', ar: 'كسنزان', en: 'Kasnazan' } as const;

const NOT_LINKED = { ckb: 'بەسترا نییە', ar: 'غير مرتبط', en: 'Not linked' } as const;

const NO_VALUATION = { ckb: 'هێشتا خەمڵاندن نییە', ar: 'لا يوجد تقييم بعد', en: 'No valuation yet' } as const;

const MULTI_CURRENCY = {
    ckb: 'کۆکراوەکان بۆ هەر دراوێک جیان — بڕەکانی دراوی جیاواز هەرگیز کۆناکرێنەوە.',
    ar: 'تُحفظ المجاميع لكل عملة على حدة — لا تُجمع مبالغ بعملات مختلفة أبداً.',
    en: 'Totals are kept per currency — amounts in different currencies are never added together.',
} as const;

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

async function fulfilMapEndpoints(page: import('@playwright/test').Page): Promise<void> {
    for (const pattern of ['**/invest/features*', '**/map/features*']) {
        await page.route(pattern, (route) =>
            route.fulfill({
                status: 200,
                contentType: 'application/json',
                body: JSON.stringify(EMPTY_FEATURES_ENVELOPE),
            }),
        );
    }
}

for (const locale of LOCALES) {
    test.describe(`profile + portfolio [${locale.code}]`, () => {
        test('the grouped profile edits real fields and refuses dishonest input', async ({ page, diagnostics }, testInfo) => {
            void diagnostics;
            clearRateLimiter();

            /*
             * BEFORE signing in: the post-login landing renders the shell,
             * and its map surfaces must answer from the hermetic envelope
             * rather than spend the shared production limiter budget.
             */
            await fulfilMapEndpoints(page);

            const f = fixtures();
            await signIn(page, f.portfolio.email, f.password);

            const width = testInfo.project.use.viewport?.width ?? 0;

            if (width >= 1024) {
                // The real journey: shell → account menu → profile.
                await page.goto(`${locale.prefix}/`, { waitUntil: 'domcontentloaded' });

                const trigger = page.locator('header [data-testid="account-menu"] > button');
                await trigger.click();
                const panelId = await trigger.getAttribute('aria-controls');
                await page.locator(`[id="${panelId}"] a[href*="/account/profile"]`).click();
                await page.waitForURL('**/account/profile');
            } else {
                await page.goto(`${locale.prefix}/account/profile`, { waitUntil: 'domcontentloaded' });
            }

            await expect(page.locator('html')).toHaveAttribute('lang', locale.code);
            await expect(page.locator('html')).toHaveAttribute('dir', locale.direction);

            // The five groups, each a real section of stored fields.
            for (const section of ['identity', 'contact', 'residence', 'personal', 'verification']) {
                await expect(page.getByTestId(`profile-section-${section}`)).toBeVisible();
            }

            /*
             * Verification claims stand independently: the fixture member is
             * Telegram-linked and phone-verified but has never touched
             * WhatsApp — and the page must say exactly that, not blur the
             * three into one "verified" badge.
             */
            await expect(page.getByTestId('verification-telegram')).not.toContainText(NOT_LINKED[locale.code]);
            await expect(page.getByTestId('verification-whatsapp')).toContainText(NOT_LINKED[locale.code]);

            // Edit a real field, save, and read the persisted truth back.
            const displayName = page.locator('[data-testid="profile-display-name"] input');
            await displayName.fill(`W5 ${locale.code}`);
            await page.getByTestId('profile-save').click();
            await expect(page.getByTestId('profile-saved')).toBeVisible();

            await page.reload({ waitUntil: 'domcontentloaded' });
            await expect(page.locator('[data-testid="profile-display-name"] input')).toHaveValue(`W5 ${locale.code}`);

            /*
             * A dishonest date of birth — a ten-year-old's — is refused with
             * the error on its own field, and the typed display name SURVIVES
             * the refusal: validation failure preserves state.
             */
            await displayName.fill(`W5 kept ${locale.code}`);
            await page.locator('[data-testid="profile-dob"] input').fill('2020-01-01');
            await page.getByTestId('profile-save').click();

            await expect(page.locator('[data-testid="profile-dob"] [role="alert"]')).toBeVisible();
            await expect(displayName).toHaveValue(`W5 kept ${locale.code}`);

            // Clearing the refused field lets the same submission through.
            await page.locator('[data-testid="profile-dob"] input').fill('');
            await page.getByTestId('profile-save').click();
            await expect(page.getByTestId('profile-saved')).toBeVisible();

            await expectNoHorizontalOverflow(page);
        });

        test('the portfolio dashboard derives every figure from persisted rows', async ({ page, diagnostics }, testInfo) => {
            void diagnostics;
            clearRateLimiter();
            await fulfilMapEndpoints(page);

            const f = fixtures();
            await signIn(page, f.portfolio.email, f.password);
            await page.goto(`${locale.prefix}/account/portfolio`, { waitUntil: 'domcontentloaded' });

            // Four real assets; three carry a current valuation.
            const summary = page.getByTestId('portfolio-summary');
            await expect(summary).toBeVisible();
            await expect(page.getByTestId('summary-count')).toContainText('4');
            await expect(page.getByTestId('summary-coverage')).toContainText('3');

            /*
             * Same-currency money is a REAL sum (165,000 + 85,000), the IQD
             * figure keeps its own card, and the note says why there is no
             * single number — never a silent conversion, never a blend.
             */
            await expect(page.getByTestId('summary-total-USD')).toContainText('250,000');
            await expect(page.getByTestId('summary-total-USD')).toContainText('USD');
            await expect(page.getByTestId('summary-total-IQD')).toContainText('320,000,000');
            await expect(page.getByTestId('summary-multi-currency')).toHaveText(MULTI_CURRENCY[locale.code]);

            // Coverage stays honest: one property is awaiting, dated by the
            // real latest valuation, and nothing anywhere reads as zero.
            await expect(page.getByTestId('summary-awaiting')).toContainText('1');
            await expect(page.getByTestId('summary-latest')).toContainText('2026-07-20');

            await expect(page.getByTestId('property-card')).toHaveCount(4);

            const awaiting = page.getByTestId('property-card').filter({ hasText: 'W5 Awaiting office' });
            await expect(awaiting).toContainText(NO_VALUATION[locale.code]);
            await expect(awaiting).not.toContainText('0.0000');

            // The entity chip resolves the stored area through the page's
            // language — real published identity, never an id.
            await expect(
                page.getByTestId('property-card').filter({ hasText: 'W5 Kasnazan home' }).getByTestId('property-entity'),
            ).toContainText(KASNAZAN[locale.code]);

            /*
             * The history page: newest first, every persisted field, and a
             * trend drawn ONLY from the two real same-currency points.
             */
            await page.goto(`${locale.prefix}/account/portfolio/${f.portfolio.property_ids.home}`, {
                waitUntil: 'domcontentloaded',
            });

            await expect(page.getByTestId('property-entity')).toContainText(KASNAZAN[locale.code]);

            const rows = page.locator('[data-testid="history-list"] > li');
            await expect(rows).toHaveCount(2);
            await expect(rows.nth(0)).toContainText('165000.0000');
            await expect(rows.nth(1)).toContainText('150000.0000');

            const points = await page
                .getByTestId('history-trend')
                .locator('polyline')
                .first()
                .getAttribute('points');
            expect(points?.split(' ')).toHaveLength(2);

            // The owner-configurable maximum text scale keeps both surfaces
            // inside the viewport, and touch viewports keep the 44px floor.
            await page.evaluate(() => {
                document.documentElement.style.setProperty('--mh-type-scale', '120');
            });
            await expectNoHorizontalOverflow(page);

            const width = testInfo.project.use.viewport?.width ?? 0;

            if (width < 768) {
                await page.goto(`${locale.prefix}/account/portfolio`, { waitUntil: 'domcontentloaded' });
                await expectTouchTargets(page);
            }
        });
    });
}

test.describe('portfolio authorization boundary', () => {
    test("another account's direct property URL answers 404, a guest is sent to sign in", async ({ page, diagnostics }) => {
        void diagnostics;
        clearRateLimiter();
        await fulfilMapEndpoints(page);

        const f = fixtures();

        // A guest holds nothing: the route demands a session first.
        const guest = await page.goto(`/account/portfolio/${f.portfolio.property_ids.home}`, {
            waitUntil: 'domcontentloaded',
        });
        expect(guest?.url()).toContain('/login');

        // A DIFFERENT signed-in member gets 404 — not 403: a stranger must
        // not even learn that a property with this id exists.
        await signIn(page, f.plain.email, f.password);
        const response = await page.goto(`/account/portfolio/${f.portfolio.property_ids.home}`, {
            waitUntil: 'domcontentloaded',
        });
        expect(response?.status()).toBe(404);
    });
});
