import { test, expect } from './support/harness';
import { clearRateLimiter, fixtures, signIn, totp } from './support/fixtures';

/*
 * The MFA challenge.
 *
 * MFA is not enabled by default — it is per-account, and the seeder enrols one
 * disposable admin by writing `mfa_secret` and `mfa_confirmed_at` directly,
 * exactly as the enrolment controller does. It is therefore tested rather than
 * marked NOT APPLICABLE.
 *
 * The code is computed here from the shared secret with a local RFC 6238
 * implementation rather than requested from the application. Asking the app what
 * it expects would make the assertion agree with the implementation by
 * construction, which is worth nothing on an authentication factor.
 */
test.describe.configure({ mode: 'serial' });

test.beforeEach(async ({}, testInfo) => {
    test.skip(testInfo.project.name !== 'desktop-1440x900', 'MFA flow runs once, on desktop');
});

test('an enrolled account is stopped at the challenge', async ({ page }) => {
    const f = fixtures();

    await signIn(page, f.mfa.email, f.password);

    /*
     * Password alone must not reach an admin page. Asserted on the destination
     * rather than the status: EnsureMfaConfirmed redirects to the challenge, and
     * `page.goto` follows that redirect, so the response is a perfectly ordinary
     * 200 for the challenge page. The URL is what says the guard fired.
     */
    await page.goto('/admin/branding');

    expect(new URL(page.url()).pathname, 'admin page before the second factor')
        .toBe('/admin/mfa/challenge');

    await expect(page.locator('input').first()).toBeVisible();
});

test('an incorrect code is rejected', async ({ page }) => {
    const f = fixtures();

    await signIn(page, f.mfa.email, f.password);
    await page.goto('/admin/mfa/challenge', { waitUntil: 'networkidle' });

    await page.locator('form input').first().fill('000000');
    await page.locator('form button[type="submit"]').first().click();
    await page.waitForLoadState('domcontentloaded');

    // Still challenged: the guard must not have been satisfied by a wrong code.
    await page.goto('/admin/branding');

    expect(new URL(page.url()).pathname, 'admin page after a wrong code')
        .toBe('/admin/mfa/challenge');
});

test('a correct code completes the session and reaches an admin page', async ({ page }) => {
    const f = fixtures();

    await signIn(page, f.mfa.email, f.password);
    await page.goto('/admin/mfa/challenge', { waitUntil: 'networkidle' });

    /*
     * The preceding test submits a wrong code on purpose, and
     * `POST admin/mfa/challenge` carries `throttle:mfa`. Without this the
     * correct code is answered 429 and the test reads as "valid TOTP rejected",
     * which is the most alarming possible way to be wrong about your own
     * harness. The PHP and JS implementations were verified to agree on the
     * same secret and timestamp before this was concluded.
     */
    clearRateLimiter();

    await page.locator('form input').first().fill(totp(f.mfa.secret));
    await page.locator('form button[type="submit"]').first().click();
    await page.waitForURL((url) => !url.pathname.includes('/mfa/challenge'), { timeout: 20_000 });

    await page.goto('/admin/branding', { waitUntil: 'domcontentloaded' });

    expect(new URL(page.url()).pathname, 'admin page after the second factor')
        .toBe('/admin/branding');
});
