import {
    test, expect, LOCALES,
    expectNoDuplicateIds, expectTouchTargets, expectNoHorizontalOverflow,
} from './support/harness';

/*
 * The accessibility gates that only a browser can settle.
 *
 * Touch targets are measured from the rendered box rather than read off the
 * class list, because `min-h-11` losing a specificity fight produces exactly
 * the same markup and a 30px button.
 */
for (const path of ['/', '/projects', '/login']) {
    test(`no duplicate ids on ${path}`, async ({ page }) => {
        await page.goto(path, { waitUntil: 'networkidle' });
        await expectNoDuplicateIds(page);
    });

    test(`touch targets are at least 44px on ${path}`, async ({ page }, testInfo) => {
        const width = testInfo.project.use.viewport?.width ?? 0;
        test.skip(width >= 768, 'the 44px floor is a touch-viewport requirement');

        await page.goto(path, { waitUntil: 'networkidle' });
        await expectTouchTargets(page);
    });
}

test('a skip link is the first thing a keyboard reaches', async ({ page }) => {
    await page.goto('/', { waitUntil: 'networkidle' });
    await page.keyboard.press('Tab');

    const focused = await page.evaluate(() => ({
        tag: document.activeElement?.tagName.toLowerCase() ?? '',
        href: document.activeElement?.getAttribute('href') ?? '',
    }));

    expect(focused.tag).toBe('a');
    expect(focused.href).toBe('#public-main');
    await expect(page.locator('#public-main')).toHaveCount(1);
});

for (const locale of LOCALES) {
    test(`the drawer opens in ${locale.code} on a touch viewport`, async ({ page }, testInfo) => {
        const width = testInfo.project.use.viewport?.width ?? 0;
        test.skip(width >= 1024, 'drawer is desktop-absent');

        await page.goto(`${locale.prefix}/`, { waitUntil: 'networkidle' });
        await page.locator('header button[aria-controls="public-mobile-nav"]').click();

        await expect(page.locator('#public-mobile-nav')).toBeVisible();
        await expectNoHorizontalOverflow(page);
    });
}
