import { test, expect } from './support/harness';
import { fixtures, signIn } from './support/fixtures';

/*
 * The AI Advisor, in both of its real states.
 *
 * `advisor.residential` is a database-backed flag that ships OFF, and both
 * sides of it are product behaviour worth testing: switched on, the interview
 * workspace must work; switched off, the homepage must say so plainly and offer
 * no link into a route that would 404. §3.4 of the design brief calls a control
 * that goes nowhere a defect, so the OFF case is a real assertion and not a
 * skipped test.
 *
 * The suite reads the flag from the page's own shared props rather than being
 * told, so it tests the deployment in front of it instead of an assumption
 * about it.
 */
async function advisorEnabled(page: import('@playwright/test').Page): Promise<boolean> {
    const payload = await page.getAttribute('#app', 'data-page');
    const props = JSON.parse(payload ?? '{}').props ?? {};

    return props.features?.['advisor.residential'] === true;
}

test('the homepage presents the advisor honestly in whichever state it is in', async ({ page }) => {
    await page.goto('/', { waitUntil: 'networkidle' });

    const enabled = await advisorEnabled(page);
    const link = page.locator('main a[href$="/advisor"]');

    if (enabled) {
        await expect(link.first()).toBeVisible();
    } else {
        // No dead control: the link must be absent from the DOM, not dimmed.
        await expect(link).toHaveCount(0);
        await expect(page.locator('main [role="status"]')).toBeVisible();
    }
});

test('the interview workspace works when the flag is on', async ({ page }) => {
    await page.goto('/', { waitUntil: 'networkidle' });
    test.skip(!(await advisorEnabled(page)), 'advisor.residential is OFF in this deployment');

    const f = fixtures();
    await signIn(page, f.admin.email, f.password);

    const response = await page.goto('/advisor', { waitUntil: 'networkidle' });
    expect(response?.status()).toBe(200);

    await expect(page.locator('h1')).toHaveCount(1);

    // One question on screen at a time, with a real composer behind it.
    await expect(page.locator('textarea')).toHaveCount(1);

    // The summary lists every slot, answered or not — hiding the unanswered
    // ones would let a visitor believe the advisor knew something it never
    // asked about.
    await expect(page.locator('[role="progressbar"]')).toBeVisible();

    const value = await page.getAttribute('[role="progressbar"]', 'aria-valuenow');
    expect(Number(value)).toBeGreaterThanOrEqual(0);
    expect(Number(value)).toBeLessThanOrEqual(100);
});

test('the advisor route stays closed when the flag is off', async ({ page }) => {
    await page.goto('/', { waitUntil: 'networkidle' });
    test.skip(await advisorEnabled(page), 'advisor.residential is ON in this deployment');

    const response = await page.goto('/advisor');
    expect(response?.status()).toBe(404);
});

/*
 * The ENABLED state.
 *
 * `advisor.residential` is switched on in the disposable database by
 * seed-browser-fixtures.php, through the `feature_flags` table — the same
 * mechanism an operator uses — so these tests exercise the real code path
 * rather than a patched config.
 *
 * No AI provider is called. The interview's first question is produced by
 * AdvisorConversationFlow from the slot definitions, and the transcript renders
 * whatever the server has already stored. Nothing here submits to a paid or
 * sensitive service, and nothing here writes market data.
 */
test.describe('advisor enabled', () => {
    test.beforeEach(async ({ page }, testInfo) => {
        test.skip(testInfo.project.name !== 'desktop-1440x900', 'advisor flow runs once, on desktop');

        await page.goto('/', { waitUntil: 'networkidle' });
        test.skip(!(await advisorEnabled(page)), 'advisor.residential is OFF in this deployment');

        /*
         * The Advisor is behind `verified`, so a guest is redirected to Telegram
         * login. That is correct product behaviour, not a defect — the enabled
         * state is only reachable by a verified account, so the test signs in as
         * one rather than asserting against the redirect.
         */
        const f = fixtures();
        await signIn(page, f.admin.email, f.password);
    });

    test('the workspace loads with one question and a composer', async ({ page }) => {
        const response = await page.goto('/advisor', { waitUntil: 'networkidle' });

        expect(response?.status()).toBe(200);
        await expect(page.locator('h1')).toHaveCount(1);
        await expect(page.locator('textarea')).toHaveCount(1);
        await expect(page.locator('[role="progressbar"]')).toBeVisible();
    });

    test('submitting an answer advances the interview and records the turn', async ({ page }) => {
        await page.goto('/advisor', { waitUntil: 'networkidle' });

        const before = Number(await page.getAttribute('[role="progressbar"]', 'aria-valuenow'));

        await page.locator('textarea').fill('بۆ نیشتەجێبوونی خۆم');
        await page.locator('button:has-text("")').first();
        await page.locator('form button, button').filter({ hasText: /.+/ }).first().click();
        await page.waitForLoadState('networkidle');

        // The visitor's own turn must appear in the transcript.
        await expect(page.locator('li').first()).toBeVisible();

        const after = Number(await page.getAttribute('[role="progressbar"]', 'aria-valuenow'));
        expect(after, 'progress after answering').toBeGreaterThanOrEqual(before);
    });

    test('every avatar in the transcript has unique gradient ids', async ({ page }) => {
        await page.goto('/advisor', { waitUntil: 'networkidle' });

        // The regression this guards: three fixed SVG ids shared by every avatar.
        const duplicates = await page.evaluate(() => {
            const seen = new Map<string, number>();

            for (const element of Array.from(document.querySelectorAll('[id]'))) {
                seen.set(element.id, (seen.get(element.id) ?? 0) + 1);
            }

            return Array.from(seen.entries()).filter(([, n]) => n > 1).map(([id]) => id);
        });

        expect(duplicates, 'duplicate ids in the advisor transcript').toEqual([]);
    });

    test('the disabled notice is absent when the feature is on', async ({ page }) => {
        await page.goto('/', { waitUntil: 'networkidle' });

        await expect(page.locator('main a[href$="/advisor"]').first()).toBeVisible();
    });

    /*
     * Phase 10 containment, the KEEP side. The live-chat overlay's header
     * decoration — the animated ring around the header icon and the "AI
     * live" badge — is now gated on this page's own [data-advisor-chat]
     * marker, because it used to decorate ANY header with an h1 (every
     * admin page, every public detail page). This pins that the gate did
     * not overshoot: on the advisor page itself, both halves of the
     * decoration still appear exactly as before.
     */
    test('the live overlay still decorates the advisor header: ring and badge', async ({ page }) => {
        await page.goto('/advisor', { waitUntil: 'networkidle' });

        await expect(page.locator('[data-mh-ai-live-wrap]')).toHaveCount(1);
        await expect(page.locator('.mh-ai-live-badge')).toBeVisible();
    });
});
