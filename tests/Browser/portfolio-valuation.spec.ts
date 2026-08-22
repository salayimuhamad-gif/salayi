import { test, expect, LOCALES, expectNoHorizontalOverflow } from './support/harness';
import { fixtures, signIn, clearRateLimiter } from './support/fixtures';

/*
 * Wave 6 — the question-driven valuation rules, end to end against the REAL
 * owner routes and the seeded ACTIVE rule set. Nothing on the subject path
 * is intercepted: the questions the form renders were resolved server-side
 * from the published set, the answer travels as an OPTION ID, the
 * percentage is read from the option row on the server, and every figure in
 * the breakdown comes from the valuation row and its snapshot rows.
 *
 * The fixture geometry is the proof material: three published USD sale
 * records make the evidence median a real 110000, the seeded question's
 * +5.000% option turns it into exactly 115500.0000 server-side, and the
 * owner never sees that configured weight: the option list carries no
 * percentage, and each breakdown row shows only the factor's direction.
 * The TOTAL keeps its exact percent — the base and final figures already
 * imply the net effect, so the total hides nothing worth hiding — and it
 * is the only percent anywhere on the owner surface.
 *
 * Only the UNRELATED homepage map endpoints are fulfilled with their valid
 * empty envelope (the Wave 3 pattern): the brief landing on the shell must
 * not spend a production limiter budget that belongs to the map specs.
 */

const QUESTION = { ckb: 'دۆخی نۆژەنکردنەوە', ar: 'حالة التجديد', en: 'Renovation state' } as const;

const RENOVATED = { ckb: 'بەم دواییە نۆژەنکراوەتەوە', ar: 'مجدد حديثاً', en: 'Recently renovated' } as const;

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
    test.describe(`portfolio valuation rules [${locale.code}]`, () => {
        test('answers travel as ids, the estimate adjusts, the breakdown explains itself', async ({ page, diagnostics }, testInfo) => {
            void diagnostics;
            clearRateLimiter();
            await fulfilMapEndpoints(page);

            const f = fixtures();
            await signIn(page, f.valuation.email, f.password);

            await page.goto(`${locale.prefix}/account/portfolio/${f.valuation.property_id}`, {
                waitUntil: 'domcontentloaded',
            });

            await expect(page.locator('html')).toHaveAttribute('lang', locale.code);
            await expect(page.locator('html')).toHaveAttribute('dir', locale.direction);

            // The edit form renders the ACTIVE set's question in the page's
            // language — resolved server-side, never hardcoded client-side.
            await page.getByTestId('edit-property').click();

            const questions = page.getByTestId('valuation-questions');
            await expect(questions).toBeVisible();
            await expect(questions).toContainText(QUESTION[locale.code]);

            /*
             * Server authority, visible in the DOM: the whole question
             * section — labels, options, everything the owner can see —
             * carries no percentage and no percent sign. The numbers exist
             * only on the server until a valuation snapshots them.
             */
            await expect(questions).not.toContainText('%');
            await expect(questions).not.toContainText('5.000');

            const select = page.getByTestId('valuation-question-renovation_state');

            // The new controls keep the touch floor on phone viewports.
            const width = testInfo.project.use.viewport?.width ?? 0;

            if (width < 768) {
                const box = await select.boundingBox();
                expect(box?.height ?? 0).toBeGreaterThanOrEqual(44);
            }

            // Answer by LABEL: the value underneath is the option id.
            await select.selectOption({ label: RENOVATED[locale.code] });

            await page
                .locator('form')
                .filter({ has: page.getByTestId('valuation-questions') })
                .locator('button[type="submit"]')
                .click();

            // The save closes the edit fold; reopening proves rehydration —
            // the persisted answer preselects, from the server, by id.
            await expect(questions).toBeHidden();
            await page.getByTestId('edit-property').click();
            await expect(
                page.getByTestId('valuation-question-renovation_state').locator('option:checked'),
            ).toHaveText(RENOVATED[locale.code]);
            await page.getByTestId('edit-property').click();

            /*
             * The valuation: engine median 110000 from the three seeded
             * records, adjusted ONCE by the answered +5.000% server-side to
             * exactly 115500.0000 — Decimal end to end, asserted as exact
             * strings. The breakdown ROW explains the factor by direction
             * only; the exact weight never renders. The TOTAL keeps its
             * exact percent.
             */
            await page.getByTestId('request-valuation').click();

            const result = page.getByTestId('valuation-result');
            await expect(result).toContainText('115500.0000');
            await expect(result).toContainText('USD');

            const breakdown = page.getByTestId('valuation-breakdown');
            await expect(breakdown).toBeVisible();
            await expect(breakdown).toContainText('110000.0000');
            const adjustmentRow = page.getByTestId('breakdown-adjustment');
            await expect(adjustmentRow).toContainText(RENOVATED[locale.code]);
            await expect(adjustmentRow).toContainText('▲');
            await expect(adjustmentRow).not.toContainText('%');
            await expect(adjustmentRow).not.toContainText('5.000');
            await expect(page.getByTestId('breakdown-total')).toContainText('+5.000%');

            // No warning for a modest total: the threshold is |30.000|.
            await expect(page.getByTestId('adjustment-warning')).toHaveCount(0);

            // The history's newest row carries ITS OWN snapshot breakdown —
            // direction-only factor rows, exact percent on the total alone.
            const newest = page.locator('[data-testid="history-list"] > li').first();
            await expect(newest).toContainText('115500.0000');
            const historyRow = newest.getByTestId('history-adjustment-row');
            await expect(historyRow).toContainText('▲');
            await expect(historyRow).not.toContainText('%');
            await expect(historyRow).not.toContainText('5.000');
            await expect(newest.getByTestId('history-total')).toContainText('+5.000%');

            // The owner-configurable maximum text scale keeps the breakdown
            // inside the viewport.
            await page.evaluate(() => {
                document.documentElement.style.setProperty('--mh-type-scale', '120');
            });
            await expectNoHorizontalOverflow(page);
        });
    });
}
