import { test, expect } from './support/harness';
import { signInAdmin } from './support/fixtures';

/*
 * Phase 10: the advisor live-chat overlay stays on the advisor page.
 *
 * The overlay script is loaded globally (app.blade.php) because Inertia can
 * enter the advisor without a full page load — but through v7 its
 * `enhanceLiveAvatar()` decorated the first <header> containing an <h1> on
 * WHATEVER page it found itself on. On every admin page that is the admin
 * chrome: the sidebar toggle's icon was wrapped in the animated "AI live"
 * ring (inflating the button ~36px → ~81px) and a ~74px "AI live" badge was
 * appended beside the crushed page title. The header's unshrinkable content
 * then totalled ~471px, so every phone-width admin page scrolled sideways
 * from first paint (measured pre-fix: 360×800 → scrollWidth 471 with the
 * pose scrolled to −111; 390×844 → 472/−81), skewing pointer coordinates —
 * the "sticky scrollWidth artifact" that Phase 7 could only work around,
 * misattributed at the time to input focus. Public detail headers (a
 * project page's own <header><h1>) were decorated the same way.
 *
 * v8 gates the decoration on the advisor page's own [data-advisor-chat]
 * marker. These tests pin the CLOSED side of that gate on both phone
 * viewports and on a public detail page; advisor.spec.ts pins the KEEP side
 * (ring + badge still present on the advisor page itself).
 */

test.describe('admin chrome integrity on phones', () => {
    test.beforeEach(async ({ page }, testInfo) => {
        testInfo.skip(
            testInfo.project.name !== 'mobile-360x800' && testInfo.project.name !== 'mobile-390x844',
            'the overflow artifact is a phone-width defect; the two phone viewports pin it',
        );
        await signInAdmin(page);
    });

    test('an admin page neither scrolls sideways nor carries advisor chrome', async ({ page }, testInfo) => {
        await page.goto('/admin/areas/create', { waitUntil: 'domcontentloaded' });
        await expect(page.locator('header').first()).toBeVisible();

        const width = testInfo.project.use.viewport!.width;

        /*
         * The document is exactly one viewport wide and sits at its origin.
         * Pre-fix this fails immediately (471/−111 at 360, 472/−81 at 390).
         * The poll doubles as the settle window for the overlay script,
         * which enhances on DOMContentLoaded, a 0ms timer and every Inertia
         * event — so the absence assertions after it are meaningful, not a
         * race won against the injection.
         */
        await expect
            .poll(() => page.evaluate(() => document.documentElement.scrollWidth), {
                timeout: 10_000,
                message: 'the admin document must be exactly one viewport wide',
            })
            .toBe(width);
        expect(await page.evaluate(() => document.documentElement.scrollLeft)).toBe(0);

        await expect(page.locator('.mh-ai-live-badge')).toHaveCount(0);
        await expect(page.locator('[data-mh-ai-live-wrap]')).toHaveCount(0);
    });
});

test.describe('public detail chrome stays undecorated', () => {
    test.beforeEach(async ({}, testInfo) => {
        testInfo.skip(
            testInfo.project.name !== 'desktop-1440x900',
            'one deterministic fixture page proves the mutation class is closed',
        );
    });

    /*
     * The negative pin outside admin: a project detail page has its own
     * <header><h1> (the project name) — exactly the shape the v7 finder
     * matched. On CURRENT markup this header carries no <svg>, and v7's
     * svg guard sat before the badge append, so the decoration happened to
     * be unreachable here — this pin therefore has no fail-before of its
     * own; the admin pins are the fail-before sites. It exists so the
     * mutation CLASS stays closed: if the gate ever regresses to generic
     * header matching AND any detail header gains an icon, this fails
     * alongside the admin pins instead of the leak returning silently.
     */
    test('a project page header carries no advisor ring or badge', async ({ page }) => {
        await page.goto('/projects/browser-invest-tower', { waitUntil: 'networkidle' });
        await expect(page.locator('h1').first()).toBeVisible();

        await expect(page.locator('.mh-ai-live-badge')).toHaveCount(0);
        await expect(page.locator('[data-mh-ai-live-wrap]')).toHaveCount(0);
    });
});
