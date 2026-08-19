import { test, expect } from './support/harness';

/*
 * The shell's two navigations: the desktop rail and the mobile drawer.
 *
 * The drawer assertions are the reason this file exists. Escape-to-close and
 * focus restoration are invisible to every other kind of test — PHPUnit cannot
 * see them, and an SSR render cannot either — yet without them a keyboard user
 * who opens the drawer has no way out and lands back at the top of the document
 * when it closes.
 */
test.describe('desktop rail', () => {
    test('is present from the lg breakpoint and marks the current page', async ({ page }, testInfo) => {
        const width = testInfo.project.use.viewport?.width ?? 0;
        test.skip(width < 1024, 'the rail is intentionally absent below lg');

        await page.goto('/projects', { waitUntil: 'networkidle' });

        const rail = page.locator('aside nav');
        await expect(rail).toBeVisible();
        await expect(rail.locator('[aria-current="page"]')).toHaveCount(1);
    });
});

test.describe('mobile drawer', () => {
    test.beforeEach(async ({ page }, testInfo) => {
        const width = testInfo.project.use.viewport?.width ?? 0;
        test.skip(width >= 1024, 'the drawer is intentionally absent from lg upwards');

        await page.goto('/', { waitUntil: 'networkidle' });
    });

    test('opens, closes on Escape, and returns focus to its trigger', async ({ page }) => {
        const trigger = page.locator('header button[aria-controls="public-mobile-nav"]');
        await expect(trigger).toBeVisible();
        await expect(trigger).toHaveAttribute('aria-expanded', 'false');

        await trigger.click();

        const drawer = page.locator('#public-mobile-nav');
        await expect(drawer).toBeVisible();
        await expect(trigger).toHaveAttribute('aria-expanded', 'true');

        await page.keyboard.press('Escape');
        await expect(drawer).toBeHidden();

        // Without restoration, focus falls to <body> and the next Tab restarts
        // at the top of the document.
        await expect(trigger).toBeFocused();
    });

    test('announces itself as a modal dialog with a name', async ({ page }) => {
        await page.locator('header button[aria-controls="public-mobile-nav"]').click();

        const drawer = page.locator('#public-mobile-nav');
        await expect(drawer).toHaveAttribute('role', 'dialog');
        await expect(drawer).toHaveAttribute('aria-modal', 'true');

        const labelledBy = await drawer.getAttribute('aria-labelledby');
        expect(labelledBy).toBeTruthy();
        await expect(page.locator(`#${labelledBy}`)).toBeVisible();
    });

    test('locks the page behind it and releases the lock on close', async ({ page }) => {
        const overflowNow = () => page.evaluate(() => document.body.style.overflow);

        const before = await overflowNow();
        await page.locator('header button[aria-controls="public-mobile-nav"]').click();
        expect(await overflowNow()).toBe('hidden');

        await page.keyboard.press('Escape');
        await expect(page.locator('#public-mobile-nav')).toBeHidden();
        expect(await overflowNow()).toBe(before);
    });
});
