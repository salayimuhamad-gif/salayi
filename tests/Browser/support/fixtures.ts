import { execFileSync } from 'node:child_process';
import { createHmac } from 'node:crypto';
import { readFileSync } from 'node:fs';
import path from 'node:path';

/*
 * Resolved from the runner's working directory, not from `__dirname`.
 * Playwright loads specs as ES modules, where `__dirname` does not exist; the
 * config pins the project root as cwd, so this is both defined and stable.
 */
const SUPPORT_DIR = path.join(process.cwd(), 'tests', 'Browser', 'support');

/*
 * Access to the disposable accounts created by seed-browser-fixtures.php.
 *
 * The file is written at seed time and gitignored, so no credential ever enters
 * the repository, the Clean Source archive or the Production Deployment archive.
 * A spec that needs an account calls `fixtures()`, which throws a directive
 * rather than a stack trace when the seeder has not been run — the failure mode
 * that would otherwise cost somebody twenty minutes.
 */
export interface BrowserFixtures {
    password: string;
    admin: { email: string; secret: string };
    plain: { email: string };
    mfa: { email: string; secret: string };
    /** The Wave 5 member whose four seeded properties the portfolio spec asserts. */
    portfolio: { email: string; property_ids: Record<string, number> };
    /** The Wave 6 member whose one apartment the valuation-rules spec drives. */
    valuation: { email: string; property_id: number };
    /** An admin-owned wizard draft whose Location step is directly reachable. */
    wizard_draft_id: number;
    flags: Record<string, boolean>;
}

let cached: BrowserFixtures | null = null;

export function fixtures(): BrowserFixtures {
    if (cached !== null) return cached;

    const file = path.join(SUPPORT_DIR, 'fixtures.json');

    try {
        cached = JSON.parse(readFileSync(file, 'utf8')) as BrowserFixtures;
    } catch {
        throw new Error(
            'tests/Browser/support/fixtures.json is missing. Run:\n' +
                '  php tests/Browser/support/seed-browser-fixtures.php --confirm-disposable-database',
        );
    }

    return cached;
}

/*
 * RFC 6238 TOTP, computed in the test rather than read from the application.
 *
 * Asking the app for the code it expects would make the test agree with the
 * implementation by construction — it would pass even if both were wrong. Thirty
 * lines of standard algorithm against the shared secret is an independent
 * check, which is the only kind worth having on an authentication factor.
 */
export function totp(secret: string, atSeconds: number = Math.floor(Date.now() / 1000)): string {
    const key = base32Decode(secret);
    const counter = Math.floor(atSeconds / 30);

    const buffer = Buffer.alloc(8);
    buffer.writeUInt32BE(Math.floor(counter / 0x100000000), 0);
    buffer.writeUInt32BE(counter >>> 0, 4);

    const digest = createHmac('sha1', key).update(buffer).digest();
    const offset = digest[digest.length - 1] & 0x0f;

    const binary =
        ((digest[offset] & 0x7f) << 24) |
        ((digest[offset + 1] & 0xff) << 16) |
        ((digest[offset + 2] & 0xff) << 8) |
        (digest[offset + 3] & 0xff);

    return String(binary % 1_000_000).padStart(6, '0');
}

function base32Decode(input: string): Buffer {
    const alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    const clean = input.replace(/=+$/, '').toUpperCase();

    let bits = 0;
    let value = 0;
    const out: number[] = [];

    for (const character of clean) {
        const index = alphabet.indexOf(character);

        if (index === -1) continue;

        value = (value << 5) | index;
        bits += 5;

        if (bits >= 8) {
            out.push((value >>> (bits - 8)) & 0xff);
            bits -= 8;
        }
    }

    return Buffer.from(out);
}

/**
 * Clears the login rate limiter's counter store.
 *
 * `throttle:login` is correct and must stay. But this suite deliberately submits
 * a wrong password, and a serial run across the admin, MFA and Advisor specs
 * makes a dozen sign-in attempts from one address inside a minute — enough to
 * trip the limiter, after which EVERY sign-in returns 429 and the symptom is
 * indistinguishable from broken authentication. That is what it looked like for
 * two sessions.
 *
 * This resets a counter between attempts. It does not raise the limit, remove
 * the middleware, or change what production does. `PLAYWRIGHT_ARTISAN_DIR` lets
 * the same helper drive a deployed archive, whose application root is not the
 * runner's working directory.
 */
export function clearRateLimiter(): void {
    try {
        execFileSync('php', ['artisan', 'cache:clear'], {
            cwd: process.env.PLAYWRIGHT_ARTISAN_DIR ?? process.cwd(),
            stdio: 'ignore',
        });
    } catch {
        // Remote target, or no artisan reachable. The limiter may then bite, and
        // signIn() reports that explicitly rather than as a mystery.
    }
}

/**
 * Signs in through the real form, so the test exercises the real middleware
 * stack — session, CSRF, throttle and all.
 *
 * It verifies the outcome instead of assuming it. A silent failure here surfaces
 * later as "the admin page was not reachable", which sends you looking at
 * authorisation when the truth was a 429 or a typo in a fixture. The error
 * message carries whatever the page actually said.
 */
export async function signIn(
    page: import('@playwright/test').Page,
    email: string,
    password: string,
    options: { expectSuccess?: boolean } = {},
): Promise<void> {
    const expectSuccess = options.expectSuccess ?? true;

    clearRateLimiter();

    /*
     * `networkidle` is wrong here. The Telegram sign-in surface polls, so the
     * network never goes idle and the helper hung for the whole test timeout —
     * which then read as "login is broken" rather than "the wait condition can
     * never be satisfied". Waiting for the URL to leave /login is the actual
     * success condition, and it is bounded.
     */
    await page.goto('/login', { waitUntil: 'domcontentloaded' });

    /*
     * Addressed by `autocomplete`, not by `type`.
     *
     * The sign-in field takes an email OR a phone number now, so it is
     * `type="text"` — `type="email"` would make the browser reject a perfectly
     * good phone number before the request was sent. A selector keyed on the
     * type therefore matched nothing, and every spec that signs in (advisor,
     * mfa, admin) failed on a 90-second timeout that read as "login is broken".
     *
     * `autocomplete="username"` is the correct and stable handle for an
     * identifier field, and it does not change with the field's type.
     */
    await page.locator('input[autocomplete="username"]').first().fill(email);
    await page.locator('input[type="password"]').first().fill(password);
    await page.locator('form button[type="submit"]').first().click();

    try {
        await page.waitForURL((url) => !url.pathname.endsWith('/login'), { timeout: 20_000 });
    } catch {
        // Left on /login. Whether that is expected is decided below.
    }

    if (!expectSuccess) {
        return;
    }

    if (new URL(page.url()).pathname.endsWith('/login')) {
        const shown = ((await page.textContent('main')) ?? (await page.textContent('body')) ?? '')
            .replace(/\s+/g, ' ')
            .trim()
            .slice(0, 200);

        throw new Error(`sign-in did not leave /login for ${email}. Page said: ${shown}`);
    }
}

/**
 * Completes the second factor for an already-signed-in administrator.
 *
 * The code is generated immediately before submission and inside the same
 * browser context and page as the sign-in, so the session that receives it is
 * the session that was challenged.
 *
 * `throttle:mfa` allows five attempts per user before a five-minute lockout, and
 * the suite deliberately submits a wrong code elsewhere, so the counter is
 * cleared first. That resets a counter; the limiter still runs.
 */
export async function completeMfa(
    page: import('@playwright/test').Page,
    secret: string,
): Promise<void> {
    clearRateLimiter();

    await page.goto('/admin/mfa/challenge', { waitUntil: 'domcontentloaded' });
    await page.locator('form input').first().fill(totp(secret));
    await page.locator('form button[type="submit"]').first().click();

    await page.waitForURL((url) => !url.pathname.includes('/mfa/challenge'), { timeout: 20_000 });
}

/**
 * Signs in as the administrator fixture and clears the second factor, leaving
 * the browser on a fully authorised admin session.
 *
 * Administrators require MFA (`admin_mfa_required` defaults true), so a password
 * alone reaches only the challenge — a helper that stopped there would leave
 * every "protected admin page" assertion measuring the challenge screen.
 */
export async function signInAdmin(page: import('@playwright/test').Page): Promise<void> {
    const f = fixtures();

    await signIn(page, f.admin.email, f.password);
    await completeMfa(page, f.admin.secret);
}
