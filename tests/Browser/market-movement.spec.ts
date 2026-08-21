import { test, expect, LOCALES, expectNoHorizontalOverflow } from './support/harness';
import { clearRateLimiter } from './support/fixtures';

/*
 * Wave 4 — Scenario C: the Market Movement panel, end to end against the
 * REAL /market/movement endpoint and the seeded fixture series. Nothing on
 * the subject path is intercepted: every percentage on screen was computed
 * by IndexCalculator::change() from persisted market_index_values rows.
 *
 * The fixture calendar is the proof material. June and July 2026 sit
 * exactly 30 real days apart, so the 30D dated window is HONESTLY
 * available; nothing sits within 7 days of anything, so 7D must render
 * disabled with its localized reason — monthly evidence cannot support a
 * seven-day claim, and the product says so instead of inventing one.
 *
 * The homepage flow fulfils the map feature endpoints with their own valid
 * empty envelope, exactly as the Wave 3 location spec does: the map is not
 * this spec's subject, and its production limiter budget must not be spent
 * here. The movement endpoint itself is NEVER intercepted.
 */

const KASNAZAN = { ckb: 'کەسنەزان', ar: 'كسنزان', en: 'Kasnazan' } as const;
const BAHARKA = { ckb: 'بەهارکە', ar: 'بهاركة', en: 'Baharka' } as const;

const SALE_ASKING = {
    ckb: 'نرخی داواکراوی فرۆشتن',
    ar: 'سعر البيع المطلوب',
    en: 'Sale asking price',
} as const;

const RISING = { ckb: 'بەرزبوونەوە', ar: 'ارتفاع', en: 'Rising' } as const;
const FALLING = { ckb: 'دابەزین', ar: 'انخفاض', en: 'Falling' } as const;

const PERIOD_UNAVAILABLE = {
    ckb: 'هیچ چاودێرییەکی بەراوردکراو بۆ ئەم ماوەیە بەردەست نییە',
    ar: 'لا تتوفر ملاحظات قابلة للمقارنة لهذه المدة',
    en: 'No comparable observations are available for this period',
} as const;

const NO_DATA_FOR_FILTERS = {
    ckb: 'هیچ زنجیرەیەکی بڵاوکراوە لەگەڵ ئەم فلتەرانە ناگونجێت',
    ar: 'لا تطابق أي سلسلة منشورة هذه المرشحات',
    en: 'No published series matches these filters',
} as const;

/* The same valid zero-state envelope the Wave 3 spec fulfils the map
 * endpoints with — the map renders its honest empty state and spends no
 * shared budget while movement is the subject. */
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

for (const locale of LOCALES) {
    test.describe(`market movement [${locale.code}]`, () => {
        test('drives sale, rent, periods and categories against real derived data', async ({ page, diagnostics }) => {
            void diagnostics;
            clearRateLimiter();

            await page.goto(`${locale.prefix}/market`, { waitUntil: 'networkidle' });
            await expect(page.locator('html')).toHaveAttribute('dir', locale.direction);

            const panel = page.getByTestId('market-movement');
            await panel.scrollIntoViewIfNeeded();

            // Default view: Sale over the full history. The strongest gainer
            // is Kasnazan apartments at a real +21.00% (100000 -> 121000).
            const gainers = page.getByTestId('movement-gainers');
            await expect(gainers).toContainText(KASNAZAN[locale.code]);
            await expect(gainers).toContainText('+21.00%');

            // Direction is never colour alone: the word and the arrow icon
            // ride with every figure.
            const firstDirection = gainers.getByTestId('movement-direction').first();
            await expect(firstDirection).toContainText(RISING[locale.code]);
            expect(await firstDirection.locator('svg').count()).toBe(1);

            // 7D is honestly impossible on monthly evidence: disabled, with
            // the localized reason on the control — never a fabricated pair.
            const sevenDays = page.getByTestId('movement-period-7d');
            await expect(sevenDays).toBeDisabled();
            await expect(sevenDays).toHaveAttribute('title', PERIOD_UNAVAILABLE[locale.code]);

            // 30D is honestly available: June and July 2026 are 30 real days
            // apart. Kasnazan +10.00%, Baharka -8.00%.
            await page.getByTestId('movement-period-30d').click();
            await expect(gainers).toContainText('+10.00%');
            const losers = page.getByTestId('movement-losers');
            await expect(losers).toContainText(BAHARKA[locale.code]);
            await expect(losers).toContainText('-8.00%');
            await expect(losers.getByTestId('movement-direction').first()).toContainText(FALLING[locale.code]);

            // Category filter: apartments only — the office series (+2.00%)
            // must drop out, leaving exactly one gainer and one loser, both
            // wearing the sale-asking provenance label.
            await page.getByTestId('movement-category-apartment').click();
            await expect(gainers).not.toContainText('+2.00%');
            await expect(gainers.getByTestId('movement-card')).toHaveCount(1);
            await expect(losers.getByTestId('movement-card')).toHaveCount(1);
            await expect(gainers).toContainText(SALE_ASKING[locale.code]);

            // Freshness, currency and the genuine sparkline: four fixture
            // observations, four points on the line — nothing decorative.
            const gainerCard = gainers.getByTestId('movement-card').first();
            await expect(gainerCard).toContainText('2026-07');
            await expect(gainerCard).toContainText('USD');
            const points = await gainerCard
                .getByTestId('movement-sparkline')
                .locator('polyline')
                .first()
                .getAttribute('points');
            expect(points?.split(' ')).toHaveLength(4);

            // Multiple categories compose exactly: apartments + offices is
            // two independent gainers (+10.00 then +2.00), never a blend.
            await page.getByTestId('movement-category-office').click();
            await expect(gainers.getByTestId('movement-card')).toHaveCount(2);
            await expect(gainers.getByTestId('movement-card').nth(0)).toContainText('+10.00%');
            await expect(gainers.getByTestId('movement-card').nth(1)).toContainText('+2.00%');

            // Rent: the sale rows vanish entirely; Kasnazan's rent series
            // falls a real -5.00%.
            await page.getByTestId('movement-category-all').click();
            await page.getByTestId('movement-transaction-rent').click();
            await expect(losers).toContainText('-5.00%');
            await expect(panel).not.toContainText(SALE_ASKING[locale.code]);

            // An honest empty combination: no rent series exists for
            // offices, and the reason says exactly that — no zeros anywhere.
            await page.getByTestId('movement-category-office').click();
            await expect(page.getByTestId('movement-empty')).toBeVisible();
            await expect(page.getByTestId('movement-reason')).toHaveText(NO_DATA_FOR_FILTERS[locale.code]);
            await expect(panel).not.toContainText('0.00%');

            // The owner-configurable maximum text scale keeps the panel
            // inside the viewport.
            await page.evaluate(() => {
                document.documentElement.style.setProperty('--mh-type-scale', '120');
            });
            await expectNoHorizontalOverflow(page);
        });

        test('the homepage panel wears the glass and derives the same truth', async ({ page, diagnostics }) => {
            void diagnostics;
            clearRateLimiter();

            for (const pattern of ['**/invest/features*', '**/map/features*']) {
                await page.route(pattern, (route) =>
                    route.fulfill({
                        status: 200,
                        contentType: 'application/json',
                        body: JSON.stringify(EMPTY_FEATURES_ENVELOPE),
                    }),
                );
            }

            await page.goto(`${locale.prefix}/`, { waitUntil: 'networkidle' });

            const panel = page.getByTestId('market-movement');
            await panel.scrollIntoViewIfNeeded();

            // The fetch is intersection-driven: it fires only once the panel
            // is actually seen, and it answers with the same derived truth
            // the /market page shows.
            await expect(panel.getByTestId('movement-gainers')).toContainText('+21.00%');
            await expect(panel.getByTestId('movement-gainers')).toContainText(KASNAZAN[locale.code]);

            await expectNoHorizontalOverflow(page);
        });
    });
}
