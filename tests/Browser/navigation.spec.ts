import { test, expect, expectNoHorizontalOverflow } from './support/harness';
import { fixtures, signIn, signInAdmin } from './support/fixtures';

/*
 * The shell's navigations: the desktop rail, the mobile drawer, and — since
 * redesign Wave 1 — the header's language and account menus.
 *
 * The drawer assertions are the reason this file exists. Escape-to-close and
 * focus restoration are invisible to every other kind of test — PHPUnit cannot
 * see them, and an SSR render cannot either — yet without them a keyboard user
 * who opens the drawer has no way out and lands back at the top of the document
 * when it closes. The Wave 1 menus are held to the same bar: they are the same
 * class of disclosure, so they get the same class of proof.
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

/*
 * The Wave 1 language menu. Every test runs on every viewport: unlike the rail
 * and the drawer, the header controls exist on both sides of lg, so a skip here
 * would be hiding a real surface.
 */
test.describe('language menu', () => {
    const trigger = (page: import('@playwright/test').Page) =>
        page.locator('header [data-testid="language-menu"] > button');

    async function openMenu(page: import('@playwright/test').Page) {
        const button = trigger(page);
        await button.click();

        const panelId = await button.getAttribute('aria-controls');
        expect(panelId, 'aria-controls id').toBeTruthy();

        return page.locator(`[id="${panelId}"]`);
    }

    test('lists all three languages and marks the active one', async ({ page, diagnostics }) => {
        await page.goto('/en/projects', { waitUntil: 'networkidle' });

        await expect(trigger(page)).toHaveAttribute('aria-expanded', 'false');

        const panel = await openMenu(page);
        await expect(trigger(page)).toHaveAttribute('aria-expanded', 'true');

        await expect(panel.locator('button')).toHaveCount(3);
        await expect(panel.getByRole('button', { name: 'کوردی' })).toBeVisible();
        await expect(panel.getByRole('button', { name: 'العربية' })).toBeVisible();
        await expect(panel.getByRole('button', { name: 'English' })).toBeVisible();

        // The active language is marked, not merely coloured.
        await expect(panel.locator('[aria-current="true"]')).toHaveText(/English/);

        expect(diagnostics.consoleErrors).toEqual([]);
    });

    test('switches Sorani → English through the sibling URL', async ({ page }) => {
        await page.goto('/projects', { waitUntil: 'networkidle' });

        const panel = await openMenu(page);
        await panel.getByRole('button', { name: 'English' }).click();

        await page.waitForURL((url) => url.pathname === '/en/projects');
        await expect(page.locator('html')).toHaveAttribute('lang', 'en');
        await expect(page.locator('html')).toHaveAttribute('dir', 'ltr');
    });

    test('switches English → Arabic preserving path and query', async ({ page }) => {
        await page.goto('/en/projects?page=1', { waitUntil: 'networkidle' });

        const panel = await openMenu(page);
        await panel.getByRole('button', { name: 'العربية' }).click();

        // The existing switchTo() contract: sibling URL, query intact.
        await page.waitForURL((url) => url.pathname === '/ar/projects' && url.search === '?page=1');
        await expect(page.locator('html')).toHaveAttribute('lang', 'ar');
        await expect(page.locator('html')).toHaveAttribute('dir', 'rtl');
    });

    test('switches Arabic → Sorani back to the unprefixed URL', async ({ page }) => {
        await page.goto('/ar/projects', { waitUntil: 'networkidle' });

        const panel = await openMenu(page);
        await panel.getByRole('button', { name: 'کوردی' }).click();

        await page.waitForURL((url) => url.pathname === '/projects');
        await expect(page.locator('html')).toHaveAttribute('lang', 'ckb');
        await expect(page.locator('html')).toHaveAttribute('dir', 'rtl');
    });

    test('closes on Escape and returns focus to the trigger', async ({ page }) => {
        await page.goto('/projects', { waitUntil: 'networkidle' });

        const panel = await openMenu(page);
        await expect(panel).toBeVisible();

        await page.keyboard.press('Escape');
        await expect(panel).toBeHidden();
        await expect(trigger(page)).toHaveAttribute('aria-expanded', 'false');
        await expect(trigger(page)).toBeFocused();
    });

    test('closes on an outside click without stealing focus', async ({ page }) => {
        await page.goto('/projects', { waitUntil: 'networkidle' });

        const panel = await openMenu(page);
        await expect(panel).toBeVisible();

        /*
         * The outside target is the footer's version line: a plain paragraph
         * that exists on every public page and can never sit under the
         * top-anchored panel. The page h1 was the first choice and failed on
         * phone widths for the right reason — the open panel overlaps it
         * there, and a tap on the panel must NOT close the menu, so
         * Playwright correctly refused to deliver that click.
         */
        await page.locator('footer .numeral').first().click();
        await expect(panel).toBeHidden();
        await expect(trigger(page)).toHaveAttribute('aria-expanded', 'false');
    });

    test('opens from the keyboard onto the active language', async ({ page }) => {
        await page.goto('/en/projects', { waitUntil: 'networkidle' });

        await trigger(page).focus();
        await page.keyboard.press('Enter');

        const panelId = await trigger(page).getAttribute('aria-controls');
        const active = page.locator(`[id="${panelId}"] [aria-current="true"]`);

        // Focus lands on the language you are in, not on the first row.
        await expect(active).toBeFocused();
        await expect(active).toHaveText(/English/);
    });

    test('holds the RTL layout with the menu open', async ({ page }) => {
        await page.goto('/ar/projects', { waitUntil: 'networkidle' });

        const panel = await openMenu(page);
        await expect(panel).toBeVisible();
        await expectNoHorizontalOverflow(page);
    });
});

/*
 * The glass-shell controls (UI refinement): the theme choice and the
 * priority navigation. Both are assertions about behavior the harness's
 * page-level overflow probe cannot see — a scrollbar INSIDE the nav never
 * widened the document, and a palette class is invisible to every other
 * check.
 */
test.describe('glass shell controls', () => {
    test('theme toggle follows the operator setting and persists the choice', async ({ page }) => {
        await page.goto('/', { waitUntil: 'networkidle' });

        const raw = (await page.locator('#app').getAttribute('data-page')) ?? '{}';
        const branding = (JSON.parse(raw).props?.branding ?? {}) as Record<string, unknown>;
        const offered = [true, '1', 'true'].includes(branding['branding.dark_mode_enabled'] as string | boolean);

        const toggle = page.locator('header [data-testid="theme-toggle"]');

        if (!offered) {
            // The operator switched visitor theming off: the control must be
            // absent from the DOM, not merely hidden, and the shell keeps its
            // night-first default.
            await expect(toggle).toHaveCount(0);
            await expect(page.locator('.mh-luxury-public')).toHaveClass(/mh-midnight/);

            return;
        }

        await expect(toggle).toBeVisible();
        // Night-first: with no stored choice the public shell is midnight.
        await expect(page.locator('.mh-luxury-public')).toHaveClass(/mh-midnight/);

        await toggle.click();
        await expect(page.locator('.mh-luxury-public')).toHaveClass(/mh-day/);

        // The choice survives a full reload (localStorage + boot script).
        await page.reload({ waitUntil: 'networkidle' });
        await expect(page.locator('.mh-luxury-public')).toHaveClass(/mh-day/);

        await page.locator('header [data-testid="theme-toggle"]').click();
        await expect(page.locator('.mh-luxury-public')).toHaveClass(/mh-midnight/);
    });

    test('the horizontal navigation clips nothing and overflows into More', async ({ page }, testInfo) => {
        const width = testInfo.project.use.viewport?.width ?? 0;
        test.skip(width < 1024, 'the horizontal nav exists only from lg');

        await page.goto('/', { waitUntil: 'networkidle' });

        const nav = page.locator('header nav');

        if ((await nav.count()) === 0) {
            return; // every flag-gated destination is off in this deployment
        }

        // How many destinations the shared flags actually enable — the same
        // definition PublicLayout resolves (pinned by NavigationDestinationsTest).
        const raw = (await page.locator('#app').getAttribute('data-page')) ?? '{}';
        const features = (JSON.parse(raw).props?.features ?? {}) as Record<string, boolean>;
        const definition: Array<[string, string | null]> = [
            ['market', 'market.intelligence'],
            ['map', 'map.explorer'],
            ['invest', 'map.investment'],
            ['projects', null],
            ['areas', 'geography.areas'],
            ['advisor', 'advisor.residential'],
            ['offers', 'marketplace.offers'],
            ['news', 'content.news'],
        ];
        const expected = definition.filter(([, flag]) => flag === null || features[flag] === true).length;

        // Every rendered item sits fully inside the nav box — no scrollbar,
        // no clipped label, in RTL and LTR alike.
        const navBox = await nav.boundingBox();
        expect(navBox).not.toBeNull();

        const links = nav.locator('> a');
        const visible = await links.count();

        for (let i = 0; i < visible; i += 1) {
            const box = await links.nth(i).boundingBox();
            expect(box).not.toBeNull();
            expect(box!.x).toBeGreaterThanOrEqual(navBox!.x - 1);
            expect(box!.x + box!.width).toBeLessThanOrEqual(navBox!.x + navBox!.width + 1);
        }

        if (visible < expected) {
            // The rest live behind the More disclosure, every one reachable.
            const more = nav.locator('button[aria-haspopup="true"]');
            await expect(more).toBeVisible();

            await more.click();
            const panelId = await more.getAttribute('aria-controls');
            await expect(page.locator(`[id="${panelId}"] a`)).toHaveCount(expected - visible);

            await page.keyboard.press('Escape');
        } else {
            expect(visible).toBe(expected);
        }
    });
});

/*
 * The Wave 1 account control. Visibility is asserted on every viewport; the
 * signed-in journeys run once, on desktop-1440x900, exactly as the admin and
 * MFA suites do — a sign-in per viewport would spend five runs proving one
 * fact.
 */
test.describe('account controls', () => {
    test('a visitor gets a sign-in action in the header', async ({ page, diagnostics }) => {
        await page.goto('/projects', { waitUntil: 'networkidle' });

        const signInLink = page.locator('header [data-testid="sign-in-link"]');
        await expect(signInLink).toBeVisible();
        await expect(signInLink).toHaveAttribute('href', '/login');

        // The locales suite pins the brand link as the header's FIRST anchor;
        // the sign-in link must never move ahead of it.
        const firstHref = await page.getAttribute('header a[href]', 'href');
        expect(firstHref).toBe('/');

        expect(diagnostics.consoleErrors).toEqual([]);
    });

    test('a signed-in member sees the account menu without an admin entry', async ({ page }, testInfo) => {
        const width = testInfo.project.use.viewport?.width ?? 0;
        test.skip(width < 1024, 'signed-in menu journeys run once, on desktop');

        const f = fixtures();
        await signIn(page, f.plain.email, f.password);
        await page.goto('/projects', { waitUntil: 'networkidle' });

        await expect(page.locator('header [data-testid="sign-in-link"]')).toHaveCount(0);

        const trigger = page.locator('header [data-testid="account-menu"] > button');
        await trigger.click();

        const panelId = await trigger.getAttribute('aria-controls');
        const panel = page.locator(`[id="${panelId}"]`);

        await expect(panel.locator('a[href*="/account/profile"]')).toBeVisible();
        await expect(panel.locator('[data-testid="account-menu-signout"]')).toBeVisible();

        // The admin destination must be ABSENT for an ordinary member — the
        // server enforces /admin regardless; this pins the honest menu.
        await expect(page.locator('[data-testid="account-menu-admin"]')).toHaveCount(0);
    });

    test('an administrator receives the admin destination', async ({ page }, testInfo) => {
        const width = testInfo.project.use.viewport?.width ?? 0;
        test.skip(width < 1024, 'signed-in menu journeys run once, on desktop');

        await signInAdmin(page);
        await page.goto('/projects', { waitUntil: 'networkidle' });

        const trigger = page.locator('header [data-testid="account-menu"] > button');
        await trigger.click();

        const adminLink = page.locator('[data-testid="account-menu-admin"]');
        await expect(adminLink).toBeVisible();
        await expect(adminLink).toHaveAttribute('href', '/admin');
    });

    test('signs out from the account menu', async ({ page }, testInfo) => {
        const width = testInfo.project.use.viewport?.width ?? 0;
        test.skip(width < 1024, 'signed-in menu journeys run once, on desktop');

        const f = fixtures();
        await signIn(page, f.plain.email, f.password);
        await page.goto('/projects', { waitUntil: 'networkidle' });

        await page.locator('header [data-testid="account-menu"] > button').click();
        await page.locator('[data-testid="account-menu-signout"]').click();

        // destroy() redirects to the login route once the session is gone.
        await page.waitForURL((url) => url.pathname.endsWith('/login'));

        await page.goto('/projects', { waitUntil: 'networkidle' });
        await expect(page.locator('header [data-testid="sign-in-link"]')).toBeVisible();
    });
});
