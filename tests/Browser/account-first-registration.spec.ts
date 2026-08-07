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

interface LocaleCase {
    locale: 'ckb' | 'ar' | 'en';
    prefix: string;
    accountCreated: string;
    returnLabel: string;
    /** A distinctive phrase from the guest-safe pre-confirmation page. */
    returnToTabPhrase: string;
    dir: 'rtl' | 'ltr';
}

const LOCALES: LocaleCase[] = [
    {
        locale: 'ckb',
        prefix: '',
        accountCreated: 'هەژمارەکەت بە سەرکەوتوویی دروست کرا',
        returnLabel: 'گەڕانەوە بۆ MyHawler',
        returnToTabPhrase: 'بگەڕێوە بۆ ئەو تابەی وێبگەڕ',
        dir: 'rtl',
    },
    {
        locale: 'ar',
        prefix: '/ar',
        accountCreated: 'تم إنشاء حسابك بنجاح',
        returnLabel: 'العودة إلى MyHawler',
        returnToTabPhrase: 'ارجع إلى تبويب المتصفح',
        dir: 'rtl',
    },
    {
        locale: 'en',
        prefix: '/en',
        accountCreated: 'Your account was created successfully',
        returnLabel: 'Return to MyHawler',
        returnToTabPhrase: 'Go back to the browser tab',
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
 * "Open Telegram" is a BUTTON that opens the bot, not an anchor, so the link
 * is read from the Inertia page props the server actually sent — which is
 * also the honest place to read it: that prop is what the button will use.
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

            // The tab polls on its own — nothing is clicked to make this happen.
            await expect(page.getByTestId('confirm-title')).toBeVisible({ timeout: 30_000 });

            await page.getByTestId('confirm-candidate').first().click();

            /*
             * The EXACT destination. Accepting `profile|onboarding` here used
             * to hide a real disagreement between the poll, the confirm
             * response and the button in the chat — a test that accepts both
             * answers cannot fail when the product picks the wrong one.
             * A newly registered account has not completed onboarding, so
             * onboarding is the only correct answer.
             */
            await expect(page).toHaveURL(new RegExp(`${testCase.prefix}/account/onboarding$`), {
                timeout: 30_000,
            });

            expect(diagnostics.consoleErrors).toEqual([]);
            expect(diagnostics.failedRequests).toEqual([]);
        });

        test('the Telegram return button authenticates a cold browser and lands on localized onboarding', async ({
            page,
            request,
            browser,
        }) => {
            await register(page, testCase, nextPhone());
            const token = await tokenFromPage(page);
            await pressStart(request, token, 720_000 + Math.floor(Math.random() * 70_000));

            await expect(page.getByTestId('confirm-title')).toBeVisible({ timeout: 30_000 });
            await page.getByTestId('confirm-candidate').first().click();
            await expect(page).toHaveURL(new RegExp(`${testCase.prefix}/account/onboarding$`), { timeout: 30_000 });

            /*
             * Telegram's inline button opens Telegram's in-app browser, which
             * carries NONE of this site's cookies. Testing it from the page
             * above would prove only that an already-authenticated browser can
             * open onboarding — which is not the journey anybody takes.
             *
             * So: a brand-new context with zero MyHawler cookies, exactly like
             * the WebView.
             */
            const handoffUrl = await page.evaluate(async () => {
                const res = await fetch('/__testing__/last-return-handoff');
                return res.ok ? (await res.json()).url : null;
            });

            if (handoffUrl === null) {
                test.skip(true, 'no handoff introspection hook in this environment');
            }

            const cold = await browser.newContext();
            const coldPage = await cold.newPage();

            expect((await cold.cookies()).length, 'the cold context started with cookies').toBe(0);

            await coldPage.goto(handoffUrl as string);

            await expect(coldPage).toHaveURL(new RegExp(`${testCase.prefix}/account/onboarding$`));
            await expect(coldPage.locator('html')).toHaveAttribute('dir', testCase.dir);

            // Replay from a second cold context must be refused, not silently
            // land on login while a report claims success.
            const replayContext = await browser.newContext();
            const replayPage = await replayContext.newPage();
            await replayPage.goto(handoffUrl as string);
            await expect(replayPage).toHaveURL(/return-expired/);
            await expect(replayPage.getByTestId('return-expired-title')).toBeVisible();

            await cold.close();
            await replayContext.close();
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
    test.describe(`Telegram buttons in a cold browser (${testCase.locale})`, () => {
        test('the FIRST button opens a guest-safe page, the SECOND authenticates once', async ({
            page,
            request,
            browser,
        }) => {
            await register(page, testCase, nextPhone());
            await expect(page).toHaveURL(new RegExp(`${testCase.prefix}/account/telegram/link$`));

            const token = await tokenFromPage(page);
            const telegramId = 810_000 + Math.floor(Math.random() * 80_000);

            await pressStart(request, token, telegramId);

            /*
             * ---- the FIRST message, sent before any confirmation ----
             *
             * At this moment no handoff exists and none should: authenticating
             * a cold browser before the original tab has confirmed the
             * candidate would hand the anti-hijack gate to whoever holds the
             * link. So this button must lead somewhere that signs nobody in.
             */
            const first = await page.evaluate(async () => {
                const res = await fetch('/__testing__/last-telegram-button/return_to_tab');

                return res.ok ? await res.json() : null;
            });

            expect(first, 'no pre-confirmation button was recorded').not.toBeNull();
            expect(first.text).toBe(testCase.returnLabel);
            expect(first.url).toContain(`${testCase.prefix}/account/return-to-tab`);

            const cold = await browser.newContext();
            const coldPage = await cold.newPage();

            expect((await cold.cookies()).length, 'the cold context started with cookies').toBe(0);

            await coldPage.goto(first.url as string);

            // It renders — it does not bounce to login...
            await expect(coldPage).toHaveURL(new RegExp(`${testCase.prefix}/account/return-to-tab$`));

            // ...it authenticates nobody: a gated page still refuses this context.
            await coldPage.goto(`${testCase.prefix}/account/onboarding`);
            await expect(coldPage).toHaveURL(/\/login/);

            await coldPage.goto(first.url as string);

            // ...it tells the person what to do, in their language...
            await expect(coldPage.locator('body')).toContainText(testCase.returnToTabPhrase);

            // ...it reveals nothing about an account or the linking state...
            const body = (await coldPage.locator('body').innerText()).toLowerCase();
            expect(body).not.toContain('linked');
            expect(body).not.toContain(String(telegramId));

            // ...and it is laid out in the right direction.
            await expect(coldPage.locator('html')).toHaveAttribute('dir', testCase.dir);

            await cold.close();

            /*
             * ---- confirm in the ORIGINAL browser ----
             */
            await expect(page.getByTestId('confirm-title')).toBeVisible({ timeout: 30_000 });
            await page.getByTestId('confirm-candidate').first().click();
            await expect(page).toHaveURL(
                new RegExp(`${testCase.prefix}/account/(onboarding|profile)`),
                { timeout: 30_000 },
            );

            /*
             * ---- the SECOND message: the secure one-time handoff ----
             */
            const second = await page.evaluate(async () => {
                const res = await fetch('/__testing__/last-return-handoff');

                return res.ok ? (await res.json()).url : null;
            });

            expect(second, 'no post-confirmation handoff was minted').not.toBeNull();
            expect(second).toContain('/account/return/');

            const warm = await browser.newContext();
            const warmPage = await warm.newPage();

            expect((await warm.cookies()).length).toBe(0);

            await warmPage.goto(second as string);

            // Authenticates, exactly once, and lands on the canonical localized
            // destination for a brand-new account: onboarding.
            await expect(warmPage).toHaveURL(new RegExp(`${testCase.prefix}/account/onboarding$`));
            await expect(warmPage.locator('html')).toHaveAttribute('dir', testCase.dir);

            await warm.close();

            // A second use of the same token is refused, neutrally.
            const replay = await browser.newContext();
            const replayPage = await replay.newPage();
            await replayPage.goto(second as string);
            await expect(replayPage).toHaveURL(/return-expired/);
            await replay.close();
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

        // Refused, and nobody was signed in as the existing account.
        await page.goto('/account/onboarding');
        await expect(page).toHaveURL(/\/login|\/register/);
    });

    test('losing the session before linking leaves the account unreachable rather than open', async ({ page }) => {
        await register(page, LOCALES[0], nextPhone());
        await expect(page).toHaveURL(/\/account\/telegram\/link$/);

        await page.context().clearCookies();

        await page.goto('/account/telegram/link');
        await expect(page).toHaveURL(/\/login/);
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
