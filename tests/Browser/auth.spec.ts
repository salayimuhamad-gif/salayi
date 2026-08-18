import { test, expect } from './support/harness';

/*
 * Authentication, in a browser.
 *
 * This exists because of a specific defect: routes/auth.php was registered
 * outside the `web` group, so /login rendered an Inertia component with no
 * session, no CSRF token and none of the shared props it reads. The page
 * returned HTTP 200 the whole time. Only a browser sees the difference between
 * "200" and "a usable login form", which is exactly the white-screen class of
 * regression this spec is here to prevent from returning.
 */
test.describe('login', () => {
    test('renders a usable form rather than a white screen', async ({ page, diagnostics }) => {
        await page.goto('/login', { waitUntil: 'networkidle' });

        await expect(page.locator('.mh-luxury-public, form, main')).toBeVisible();
        expect(await page.locator('input').count(), 'input fields').toBeGreaterThan(0);
        expect((await page.textContent('body'))?.trim().length ?? 0).toBeGreaterThan(50);
        expect(diagnostics.failedRequests).toEqual([]);
    });

    test('issues a CSRF token and a session cookie', async ({ page, context }) => {
        await page.goto('/login', { waitUntil: 'networkidle' });

        const token = await page.getAttribute('meta[name="csrf-token"]', 'content');
        expect(token, 'csrf-token meta').toBeTruthy();
        expect((token ?? '').length).toBeGreaterThan(20);

        const names = (await context.cookies()).map((cookie) => cookie.name);
        expect(names).toContain('XSRF-TOKEN');
        expect(names.some((name) => name.includes('session'))).toBe(true);
    });

    test('exposes the Inertia shared props the page depends on', async ({ page }) => {
        await page.goto('/login', { waitUntil: 'networkidle' });

        const payload = await page.getAttribute('#app', 'data-page');
        expect(payload, 'inertia payload').toBeTruthy();

        const props = JSON.parse(payload ?? '{}').props ?? {};
        for (const key of ['locale', 'features', 'auth', 'translations']) {
            expect(props, `shared prop ${key}`).toHaveProperty(key);
        }
    });
});
