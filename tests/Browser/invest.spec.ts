import {
    test, expect, LOCALES,
    expectNoHorizontalOverflow, expectNoDuplicateIds,
} from './support/harness';

/*
 * The Investment Map, in every locale and at every viewport — the first
 * browser coverage any map surface in this product has had.
 *
 * The harness's hermetic network fulfils every EXTERNAL request with an empty
 * body, so map tiles and the MapLibre style can never load here. That is the
 * point, not a limitation: the contract under test is the degraded path the
 * product promises on Erbil mobile data — the provider failure is STATED, the
 * list still renders real persisted rows, prices and trends included, and
 * nothing freezes or errors. Marker rendering itself is exercised by the
 * geometry and endpoint suites; a tile server would prove pixels, not
 * behaviour.
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
    });
}
