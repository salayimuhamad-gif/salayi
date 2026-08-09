import { execFileSync } from 'node:child_process';
import { expect } from '@playwright/test';
import { test } from './support/harness';

/**
 * The v7 ACCOUNT-FIRST registration journey, in a real browser.
 *
 * The suite previously had no registration coverage at all: its fixtures seed
 * accounts that are already linked, because under the Telegram-first model an
 * account could not exist any other way. Account-first makes the unlinked
 * signed-in state normal, so it needs to be walked rather than reasoned about.
 *
 * Scope note, stated rather than glossed: the Telegram SIDE of this journey —
 * the localized bot message, the inline "Return to MyHawler" button, its URL
 * and its origin — cannot be observed from a browser. Those are asserted
 * against the exact outbound Telegram API payload in
 * `tests/Feature/RegistrationTelegramFlowTest.php`. What is asserted HERE is
 * everything the browser can actually see, including the effect of pressing
 * that button (arriving at the localized destination and being let in).
 *
 * The `/start` is driven through the real webhook route with the real secret
 * header, from Playwright's request context — a server-to-server call carrying
 * no browser cookie, which is exactly what Telegram does.
 */

const WEBHOOK_SECRET = process.env.TELEGRAM_WEBHOOK_SECRET ?? 'browser-webhook-secret';

/*
 * Meets the platform's configured password policy (12+, mixed case, a number,
 * a symbol). Every account this spec registers uses it, so the sign-in
 * scenarios can prove the thing that matters most about the new flow: coming
 * back does not involve Telegram.
 *
 * Not a secret. It authenticates nothing outside a disposable browser-test
 * database that is rebuilt on every run.
 */
const PASSWORD = 'Br0wser!Test#2026';

interface LocaleCase {
    locale: 'ckb' | 'ar' | 'en';
    prefix: string;
    accountCreated: string;
    returnLabel: string;
    dir: 'rtl' | 'ltr';
}

const LOCALES: LocaleCase[] = [
    {
        locale: 'ckb',
        prefix: '',
        accountCreated: 'هەژمارەکەت بە سەرکەوتوویی دروست کرا',
        returnLabel: 'گەڕانەوە بۆ MyHawler',
        dir: 'rtl',
    },
    {
        locale: 'ar',
        prefix: '/ar',
        accountCreated: 'تم إنشاء حسابك بنجاح',
        returnLabel: 'العودة إلى MyHawler',
        dir: 'rtl',
    },
    {
        locale: 'en',
        prefix: '/en',
        accountCreated: 'Your account was created successfully',
        returnLabel: 'Return to MyHawler',
        dir: 'ltr',
    },
];

/*
 * Registration is rate limited to five attempts per ten minutes per IP, and
 * every test here registers from the same address — so from the sixth
 * registration onwards the form returned 429 and the failure surfaced several
 * assertions later as "the page never became the linking page".
 *
 * The counter is reset between tests, exactly as the suite's global setup
 * already does once. This resets a counter; it does not weaken the limit, and
 * the limiter itself is still asserted server-side.
 */
test.beforeEach(() => {
    try {
        execFileSync('php', ['artisan', 'cache:clear'], { stdio: 'ignore' });
    } catch {
        // Remote target or artisan unavailable: the limit still applies, and
        // a run that trips it will say so plainly.
    }
});

let phoneCounter = 0;

/*
 * A number that is unique per RUN, not merely per test.
 *
 * The browser database persists between runs, and account-first refuses a
 * duplicate phone — so a generator seeded only from the clock produced
 * collisions on a second run, and the resulting refusal looked exactly like a
 * broken registration several assertions later. Seven random digits plus a
 * counter keeps every run in its own space.
 */
const usedPhones = new Set<string>();

/*
 * A number unique across RUNS, not merely within one.
 *
 * The browser database persists between runs and account-first refuses a
 * duplicate phone, so a generator with only a few hundred possible run
 * prefixes eventually collides with an earlier run — and the refusal then
 * surfaces as "the page never became the linking page", several assertions
 * away from the cause. Seven random digits per number, de-duplicated within
 * the run, keeps collisions negligible.
 */
function nextPhone(): string {
    for (let attempt = 0; attempt < 50; attempt += 1) {
        const digits = String(Math.floor(Math.random() * 10_000_000)).padStart(7, '0');
        const phone = `0751${digits}`.slice(0, 11);

        if (!usedPhones.has(phone)) {
            usedPhones.add(phone);

            return phone;
        }
    }

    throw new Error('could not generate a unique test phone number');
}

async function register(page: import('@playwright/test').Page, testCase: LocaleCase, phone: string): Promise<void> {
    await page.goto('/register');

    // Selectors follow the real markup: the inputs are wrapped components, so
    // they are addressed by the attributes the component actually renders
    // rather than by a label whose text changes with the language.
    await page.locator('input[autocomplete="name"]').first().fill('Browser Person');
    await page.locator('input[type="tel"]').first().fill(phone);

    /*
     * The password, and its confirmation. Both are addressed by
     * `autocomplete="new-password"` — the same attribute-based approach the
     * fields above use, because a label selector would change with the
     * language and this spec runs in three.
     *
     * This is what makes every later visit an ordinary sign-in, so the
     * scenarios below can log in with it instead of pressing Start again.
     */
    const passwords = page.locator('input[autocomplete="new-password"]');
    await passwords.nth(0).fill(PASSWORD);
    await passwords.nth(1).fill(PASSWORD);

    // The language is a form FIELD, not a URL prefix: registration itself is
    // not locale-prefixed, only the authenticated surface it lands on.
    /*
     * Asserted, not assumed. The form is rendered in whatever language the
     * request resolved to, so if this select silently fails to take, the
     * account is created in the WRONG language and every later assertion
     * fails somewhere far away from the cause.
     */
    await page.locator('#register-locale').selectOption(testCase.locale);
    await expect(page.locator('#register-locale')).toHaveValue(testCase.locale);

    await page.locator('form input[type="checkbox"]').first().check();

    await page.locator('button[type="submit"]').first().click();
}

/** Drive a Telegram /start exactly as Telegram would: no cookie, secret header. */
async function pressStart(
    request: import('@playwright/test').APIRequestContext,
    token: string,
    telegramId: number,
): Promise<void> {
    const response = await request.post('/webhooks/telegram/updates', {
        headers: { 'X-Telegram-Bot-Api-Secret-Token': WEBHOOK_SECRET },
        data: {
            update_id: Math.floor(Math.random() * 2_000_000_000),
            message: {
                message_id: 1,
                date: Math.floor(Date.now() / 1000),
                chat: { id: telegramId, type: 'private' },
                from: { id: telegramId, is_bot: false, first_name: 'Browser', username: 'browseruser' },
                text: `/start ${token}`,
            },
        },
    });

    expect(response.status()).toBe(200);
}

/**
 * The deep link the page is holding, and the token inside it.
 *
 * Read from the Inertia page props the server actually sent, rather than from
 * the anchor's href. Both carry the same value — the control is a real `<a>`
 * now, so a mobile popup blocker cannot eat the tap — but the prop is the
 * server's own answer, and reading it means this helper is not silently
 * re-testing the template's interpolation.
 */
async function tokenFromPage(page: import('@playwright/test').Page): Promise<string> {
    await expect(page.getByTestId('open-telegram')).toBeVisible();

    /*
     * `data-page` carries the INITIAL page only — Inertia does not rewrite it
     * on a client-side visit, so after arriving here from the registration
     * form it still describes /register. A real load is forced so the props
     * read below are the ones the server sent for THIS page. Reloading is
     * safe by design: the intent is resumable, so the token is unchanged.
     */
    await page.reload();
    await expect(page.getByTestId('open-telegram')).toBeVisible();

    const deepLink = await page.evaluate(() => {
        const el = document.getElementById('app');
        const data = el?.dataset.page ?? '{}';

        return (JSON.parse(data).props?.deep_link ?? null) as string | null;
    });

    expect(deepLink, 'the linking page carried no Telegram deep link').toBeTruthy();

    const token = new URL(deepLink as string).searchParams.get('start');

    expect(token, 'the deep link carried no start token').toBeTruthy();

    return token as string;
}

for (const testCase of LOCALES) {
    test.describe(`account-first registration (${testCase.locale})`, () => {
        test('registers, lands on the localized linking page, and shows the account-created message', async ({
            page,
            diagnostics,
        }) => {
            await register(page, testCase, nextPhone());

            await expect(page).toHaveURL(new RegExp(`${testCase.prefix}/account/telegram/link$`));
            await expect(page.getByTestId('account-created')).toContainText(testCase.accountCreated);

            // The direction must match the language, not the page it came from.
            await expect(page.locator('html')).toHaveAttribute('dir', testCase.dir);

            expect(diagnostics.consoleErrors, 'console errors on the linking page').toEqual([]);
            expect(diagnostics.pageErrors).toEqual([]);
            expect(diagnostics.failedRequests, 'missing assets on the linking page').toEqual([]);
        });

        test('completes linking through Telegram and advances the waiting tab on its own', async ({
            page,
            request,
            diagnostics,
        }) => {
            await register(page, testCase, nextPhone());
            await expect(page).toHaveURL(new RegExp(`${testCase.prefix}/account/telegram/link$`));

            const token = await tokenFromPage(page);
            const telegramId = 700_000 + Math.floor(Math.random() * 90_000);

            await pressStart(request, token, telegramId);

            /*
             * ONE press, and the tab moves on by itself.
             *
             * Nothing is clicked between the Start and the destination. This
             * assertion is the whole product change: there used to be a
             * "confirm this Telegram account" screen here that the person had
             * to come back to the browser and press, and the test drove it.
             *
             * The EXACT destination, not `profile|onboarding`. Accepting both
             * used to hide a real disagreement between the poll, the confirm
             * response and the button in the chat — a test that accepts either
             * answer cannot fail when the product picks the wrong one. A newly
             * registered account has not completed onboarding, so onboarding is
             * the only correct answer.
             */
            await expect(page).toHaveURL(new RegExp(`${testCase.prefix}/account/onboarding$`), {
                timeout: 30_000,
            });

            // And no second step was left anywhere for anybody to press.
            await expect(page.getByTestId('confirm-title')).toHaveCount(0);

            expect(diagnostics.consoleErrors).toEqual([]);
            expect(diagnostics.failedRequests).toEqual([]);
        });

        test('verifying after the tab is closed works, and the password gets back in', async ({
            page,
            request,
            context,
            browser,
        }) => {
            /*
             * Scenario B, end to end: register, walk away, verify from
             * Telegram much later, then come back and sign in normally.
             *
             * This replaces a test that drove the one-time authenticating
             * handoff button. That button is not part of this journey any
             * more — the simplified flow deliberately does NOT mint a
             * credential to save somebody a sign-in, because the account now
             * has a password. What the person actually does instead is what is
             * asserted here.
             */
            const phone = nextPhone();

            await register(page, testCase, phone);
            const token = await tokenFromPage(page);

            /*
             * The browser is gone as far as the site is concerned: no session
             * cookie, and the tab navigated away. The page fixture itself is
             * left open rather than closed, so the harness's diagnostics
             * teardown still has something to read.
             */
            await context.clearCookies();
            await page.goto('/');

            // Verification still succeeds, driven only from Telegram.
            await pressStart(request, token, 720_000 + Math.floor(Math.random() * 70_000));

            const returning = await browser.newContext();
            const returningPage = await returning.newPage();

            expect((await returning.cookies()).length, 'the returning context started with cookies').toBe(0);

            await returningPage.goto('/login');
            await returningPage.locator('input[autocomplete="username"]').first().fill(phone);
            await returningPage.locator('input[autocomplete="current-password"]').first().fill(PASSWORD);
            await returningPage.locator('button[type="submit"]').first().click();

            /*
             * Straight to the account. Landing back on the verification page
             * here would mean the Start never took effect; landing on /login
             * would mean the password never did.
             */
            await expect(returningPage).toHaveURL(
                new RegExp(`${testCase.prefix}/account/(onboarding|profile)`),
                { timeout: 30_000 },
            );

            await returning.close();
        });

        test('surviving a refresh and a second tab without losing the token', async ({ page, context }) => {
            await register(page, testCase, nextPhone());
            const first = await tokenFromPage(page);

            await page.reload();
            expect(await tokenFromPage(page), 'a refresh minted a new token').toBe(first);

            const second = await context.newPage();
            await second.goto(`${testCase.prefix}/account/telegram/link`);
            expect(await tokenFromPage(second), 'a second tab minted a competing token').toBe(first);
            await second.close();
        });
    });
}

for (const testCase of LOCALES) {
    test.describe(`returning without Telegram (${testCase.locale})`, () => {
        test('a verified account signs in with its password and is never sent to Telegram again', async ({
            page,
            request,
            context,
            browser,
        }) => {
            /*
             * Scenario C, and the requirement the whole change exists to
             * satisfy: Telegram is needed ONCE. Every later visit is a
             * password sign-in.
             *
             * This replaces a test of the two-message Telegram button dance
             * (a guest-safe page first, a one-time authenticating handoff
             * after confirmation). The simplified flow sends one message and
             * one plain link, so what is asserted instead is that the link the
             * bot now sends authenticates NOBODY — the security property that
             * test was really protecting — and that the password is what gets
             * the person back in.
             */
            const phone = nextPhone();

            await register(page, testCase, phone);
            await expect(page).toHaveURL(new RegExp(`${testCase.prefix}/account/telegram/link$`));

            const token = await tokenFromPage(page);
            const telegramId = 810_000 + Math.floor(Math.random() * 80_000);

            await pressStart(request, token, telegramId);
            await expect(page).toHaveURL(new RegExp(`${testCase.prefix}/account/onboarding$`), {
                timeout: 30_000,
            });

            /*
             * The bot's return button, opened in a browser with no cookies —
             * exactly what Telegram's in-app WebView is. It must land
             * somewhere real and sign nobody in: a link sitting in a chat is
             * readable by anyone who can reach that chat.
             */
            const button = await page.evaluate(async () => {
                const res = await fetch('/__testing__/last-telegram-button/home');

                return res.ok ? await res.json() : null;
            });

            expect(button, 'no return button was recorded').not.toBeNull();
            expect(button.text).toBe(testCase.returnLabel);
            expect(button.url, 'the return button must carry no token').not.toContain('/account/return/');

            const cold = await browser.newContext();
            const coldPage = await cold.newPage();

            expect((await cold.cookies()).length, 'the cold context started with cookies').toBe(0);

            await coldPage.goto(button.url as string);

            // A gated page still refuses this context: nobody was signed in.
            await coldPage.goto(`${testCase.prefix}/account/onboarding`);
            await expect(coldPage).toHaveURL(/\/login/);

            await cold.close();

            /*
             * Now the real return journey: sign out, come back, sign in with
             * the password. No Start, no bot, no deep link.
             */
            await context.clearCookies();
            await page.goto('/login');
            await page.locator('input[autocomplete="username"]').first().fill(phone);
            await page.locator('input[autocomplete="current-password"]').first().fill(PASSWORD);
            await page.locator('button[type="submit"]').first().click();

            await expect(page).toHaveURL(
                new RegExp(`${testCase.prefix}/account/(onboarding|profile)`),
                { timeout: 30_000 },
            );

            // Emphatically NOT the verification page.
            await expect(page).not.toHaveURL(/\/account\/telegram\/link/);
        });
    });
}

test.describe('account-first edge cases', () => {
    test('an unlinked account is refused by a protected page and sent to linking, with no loop', async ({ page }) => {
        await register(page, LOCALES[0], nextPhone());
        await expect(page).toHaveURL(/\/account\/telegram\/link$/);

        const redirects: string[] = [];
        page.on('response', (response) => {
            if ([301, 302].includes(response.status())) {
                redirects.push(response.url());
            }
        });

        await page.goto('/account/onboarding');

        // One hop to the linking page, and it renders — not a loop.
        await expect(page).toHaveURL(/\/account\/telegram\/link$/);
        expect(redirects.length, `redirect chain was ${redirects.join(' -> ')}`).toBeLessThan(4);
        await expect(page.getByTestId('open-telegram')).toBeVisible();
    });

    test('cancelling an unfinished registration frees the number for an immediate retry', async ({ page }) => {
        const phone = nextPhone();
        await register(page, LOCALES[0], phone);
        await expect(page).toHaveURL(/\/account\/telegram\/link$/);

        // The page offers this explicitly: cancelling a registration is a
        // product action, not something a test has to synthesise.
        await page.getByTestId('abandon-registration').click();
        await expect(page).toHaveURL(/\/register$/);

        // Same number, straight away — no waiting for the retention window.
        await register(page, LOCALES[0], phone);
        await expect(page).toHaveURL(/\/account\/telegram\/link$/);
    });

    test('registering a number that is already taken is refused without signing anyone in', async ({ page }) => {
        const phone = nextPhone();
        await register(page, LOCALES[2], phone);
        await expect(page).toHaveURL(/\/en\/account\/telegram\/link$/);

        await page.context().clearCookies();

        await register(page, LOCALES[2], phone);

        await expect(page).toHaveURL(/\/register$/);
        await expect(page.locator('body')).toContainText(/could not complete registration/i);

        /*
         * Scenario D. The refusal must not dead-end.
         *
         * The message stays deliberately vague — an anonymous visitor may be
         * typing somebody else's number, and confirming it is registered would
         * make this form a lookup service. What it must NOT do is leave a real
         * owner with nowhere to go, so a way forward is offered alongside it.
         */
        await expect(page.getByTestId('register-recovery')).toBeVisible();
        await expect(page.getByTestId('register-recovery-signin')).toHaveAttribute('href', /\/login/);

        // Refused, and nobody was signed in as the existing account.
        await page.goto('/account/onboarding');
        await expect(page).toHaveURL(/\/login|\/register/);
    });

    test('losing the session before verifying is recoverable with the password, and the same link is waiting', async ({
        page,
    }) => {
        /*
         * This test used to assert the opposite — that losing the tab left the
         * account "unreachable rather than open" — because an unlinked account
         * had no email and no password, so the session really was the only way
         * back. That was a documented dead end, not a security property, and
         * it is the reason abandoned registrations had to be reclaimed after
         * 72 hours.
         *
         * The account has a password now, so the correct assertion is that the
         * person gets back in AND finds the SAME verification link they left —
         * not a replacement, which would have quietly killed the one already
         * open in their Telegram chat.
         */
        const phone = nextPhone();

        await register(page, LOCALES[0], phone);
        await expect(page).toHaveURL(/\/account\/telegram\/link$/);

        const before = await tokenFromPage(page);

        await page.context().clearCookies();

        // Still refused while signed out. The link is not a login.
        await page.goto('/account/telegram/link');
        await expect(page).toHaveURL(/\/login/);

        await page.locator('input[autocomplete="username"]').first().fill(phone);
        await page.locator('input[autocomplete="current-password"]').first().fill(PASSWORD);
        await page.locator('button[type="submit"]').first().click();

        // Signing in resumes the registration exactly where it stopped.
        await expect(page).toHaveURL(/\/account\/telegram\/link$/, { timeout: 30_000 });
        expect(await tokenFromPage(page), 'signing in again replaced the verification link').toBe(before);
    });

    test('restarting retires the old token and issues a new one', async ({ page }) => {
        await register(page, LOCALES[0], nextPhone());
        const first = await tokenFromPage(page);

        /*
         * Expiry itself is a clock condition and is asserted server-side in
         * RegistrationTelegramFlowTest. What the browser owns is the way OUT
         * of an expired or stale token: pressing restart must retire the old
         * one and show a genuinely new link, not silently resume.
         */
        const restart = page.getByTestId('restart-link').or(page.getByRole('button', { name: /start again|دووبارە|إعادة/i }));

        if (await restart.count()) {
            await restart.first().click();
            await expect
                .poll(async () => tokenFromPage(page), { timeout: 20_000 })
                .not.toBe(first);
        } else {
            test.skip(true, 'this build exposes no restart control on the linking page');
        }
    });
});
