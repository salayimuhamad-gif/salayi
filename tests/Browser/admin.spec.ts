import { test, expect } from './support/harness';
import { fixtures, signIn, signInAdmin } from './support/fixtures';

/*
 * Admin authentication and the permission boundary.
 *
 * The boundary tests matter more than the happy path. `admin/*` carries
 * `permission:*` middleware, and a middleware that is present but not enforcing
 * looks exactly like one that is — from outside, both return a page. The only
 * way to tell is to sign in as somebody who should be refused and confirm they
 * are.
 *
 * Every account is disposable, created by seed-browser-fixtures.php with a
 * random password written to a gitignored file. No credential appears in this
 * spec, in Clean Source, or in any archive.
 */
test.describe.configure({ mode: 'serial' });

test.beforeEach(async ({}, testInfo) => {
    // One browser is enough to prove an authorisation rule; running the whole
    // admin flow five times over adds minutes and no information.
    test.skip(testInfo.project.name !== 'desktop-1440x900', 'admin flow runs once, on desktop');
});

test('the login page renders a usable form', async ({ page }) => {
    await page.goto('/login', { waitUntil: 'networkidle' });

    await expect(page.locator('input[type="email"], input[name="email"]').first()).toBeVisible();
    await expect(page.locator('input[type="password"]').first()).toBeVisible();
    await expect(page.locator('button[type="submit"]').first()).toBeVisible();
});

test('an invalid password is refused and leaks nothing', async ({ page }) => {
    const f = fixtures();

    await signIn(page, f.admin.email, 'definitely-not-the-password', { expectSuccess: false });

    // Still on /login, still unauthenticated.
    expect(new URL(page.url()).pathname).toBe('/login');

    const body = (await page.textContent('body')) ?? '';
    expect(body).not.toContain('SQLSTATE');
    expect(body).not.toContain('/home/');
});

test('a valid admin signs in, clears MFA and reaches a protected admin page', async ({ page }) => {
    await signInAdmin(page);

    await page.goto('/admin/branding', { waitUntil: 'domcontentloaded' });

    expect(new URL(page.url()).pathname).toBe('/admin/branding');
    await expect(page.locator('h1, h2').first()).toBeVisible();
});

test('an account with no roles is refused the same page', async ({ page }) => {
    const f = fixtures();

    await signIn(page, f.plain.email, f.password);

    const response = await page.goto('/admin/branding');

    // 403 is the correct answer. A redirect to /login would mean the app forgot
    // the session; a 200 would mean the permission middleware is decorative.
    expect(response?.status(), 'permission boundary').toBe(403);
});

test('a signed-out visitor cannot reach an admin page', async ({ page }) => {
    /*
     * Serial mode shares one context across the file, so the session from the
     * preceding sign-in is still live. Clearing cookies is what makes this test
     * about anonymous access rather than about test ordering — without it the
     * assertion silently measured an authenticated request.
     */
    await page.context().clearCookies();

    await page.goto('/admin/branding');

    /*
     * Asserted on the FINAL URL, not the status code. `page.goto` follows
     * redirects, so a guard that correctly bounces an anonymous visitor to
     * /login still reports 200 — the login page's own status. Checking where
     * the browser ended up is both the accurate test and the stronger one: it
     * proves which guard fired, not merely that something did.
     */
    expect(new URL(page.url()).pathname).toBe('/login');
    await expect(page.locator('input[type="password"]').first()).toBeVisible();
});

test('logout ends the session through the visible control', async ({ page, diagnostics }) => {
    await signInAdmin(page);
    await page.goto('/admin/branding', { waitUntil: 'domcontentloaded' });

    /*
     * Driven through the real control in the admin sidebar rather than a
     * synthetic POST. The earlier synthetic version answered 419 because the
     * request context did not carry the page's session/CSRF pairing, and the
     * fix for that is not to weaken CSRF — it is to log out the way a person
     * does, which exercises the same protection the product ships.
     */
    await page.locator('aside button[type="button"]').last().click();

    await page.waitForURL((url) => !url.pathname.startsWith('/admin'), { timeout: 20_000 });

    // The session must be gone, not merely navigated away from.
    await page.goto('/admin/branding');
    expect(new URL(page.url()).pathname, 'protected route after logout').toBe('/login');

    /*
     * Back navigation must not get the visitor back in.
     *
     * The assertion is on a REVISIT, not on the history entry itself. Chromium's
     * back/forward cache can restore the previous document's pixels without
     * asking the server, so a URL check alone would fail on a correctly secured
     * app — and passing it by deleting the check would prove nothing. Reloading
     * forces the server to decide, which is the property that actually matters:
     * the session is gone, so the page is refused.
     *
     * (Worth noting separately: the app does not send `no-store` on
     * authenticated HTML, so the restored snapshot can remain briefly visible.
     * That is recorded as a limitation rather than fixed here — changing
     * response headers is a product change, and this task is not authorised to
     * make one.)
     */
    await page.goBack().catch(() => undefined);

    /*
     * SETTLE THE BACK NAVIGATION BEFORE FORCING THE ROUND-TRIP.
     *
     * `goBack()` can resolve while the restored document is still committing —
     * a bfcache restore in particular. A `reload()` issued into that window is
     * aborted by the navigation already in flight, which surfaced in CI as
     * `page.reload: net::ERR_ABORTED; maybe frame was detached?` on a run
     * where every other assertion in this file passed.
     *
     * Waiting for the restore to settle removes the race without touching the
     * assertion below: the reload still forces the server to decide, and the
     * server must still answer /login.
     */
    await page.waitForLoadState('domcontentloaded').catch(() => undefined);
    await page.reload({ waitUntil: 'domcontentloaded' });

    expect(new URL(page.url()).pathname, 'revisit after logout').toBe('/login');

    expect(diagnostics.pageErrors).toEqual([]);
});
