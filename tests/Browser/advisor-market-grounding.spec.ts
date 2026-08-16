import { test, expect } from './support/harness';
import { signInAdmin } from './support/fixtures';

/*
 * Phase 12: market intelligence, end to end through the real product — no
 * AI provider anywhere (the deployment under test runs AI_PROVIDER=null, and
 * the deterministic grounded path is a first-class product contract).
 *
 * One flow, two roles:
 *
 *   1. An administrator records a market insight about the seeded Ankawa
 *      area through the real Knowledge form — evidence class chosen, AI
 *      usage permitted — and walks it through the real approval workflow
 *      (draft → review → approved).
 *   2. A signed-in member asks the advisor why prices rose in Ankawa, and
 *      the answer is built FROM that insight: the recorded summary is
 *      visible, the evidence-class stance is visible, and the generic
 *      "you can ask about the recommendations…" hint — the pre-Phase-12
 *      dead end — never appears.
 *
 * The insight summary carries a per-run token, so the assertion proves THIS
 * run's admin-recorded row reached the reply, not a leftover from an
 * earlier run against a persistent database.
 */

/** The ckb generic catch-all that Phase 12 closed for market questions. */
const CONTINUE_HINT_CKB = 'دەتوانیت پرسیار لەسەر پێشنیارەکان بکەیت';

/** The ckb admin_observation stance ("our team has recorded…"). */
const OBSERVATION_STANCE_CKB = 'تیمەکەمان ئەم تێبینییەی تۆمار کردووە';

async function advisorEnabled(page: import('@playwright/test').Page): Promise<boolean> {
    const payload = await page.getAttribute('#app', 'data-page');
    const props = JSON.parse(payload ?? '{}').props ?? {};

    return props.features?.['advisor.residential'] === true;
}

/** Send one chat turn and wait for its assistant reply to be rendered. */
async function sendChat(page: import('@playwright/test').Page, message: string): Promise<void> {
    const transcript = page.locator('ul.space-y-5 > *');
    const before = await transcript.count();

    // The composer is Vue-bound; assert the value actually stuck before
    // clicking, so an Inertia DOM swap between fill and click cannot turn
    // the send into a silent empty-message no-op.
    await page.locator('textarea').fill(message);
    await expect(page.locator('textarea')).toHaveValue(message);
    await page.getByRole('button', { name: 'ناردن', exact: true }).click();

    // The user turn and the assistant turn both land in the transcript.
    await expect.poll(async () => transcript.count(), { timeout: 20_000 }).toBeGreaterThanOrEqual(before + 2);
}

test.describe('advisor market grounding', () => {
    test.beforeEach(async ({ page }, testInfo) => {
        testInfo.skip(
            testInfo.project.name !== 'desktop-1440x900',
            'the market-grounding flow runs once, on desktop-1440x900 only',
        );

        await page.goto('/', { waitUntil: 'networkidle' });
        testInfo.skip(!(await advisorEnabled(page)), 'advisor.residential is OFF in this deployment');
    });

    test('an admin-recorded, approved insight grounds the advisor market answer', async ({ page }) => {
        const token = `p12-${Date.now()}`;
        const title = `بەرزبوونەوەی نرخەکان لە ئەنکاوە ${token}`;

        await signInAdmin(page);

        /* -------- 1. record the insight through the real Knowledge form */

        await page.goto('/admin/knowledge', { waitUntil: 'networkidle' });
        await page.getByRole('button', { name: 'دروستکردنی ڕووداو' }).click();

        await page.getByLabel('ناو (کوردی)').first().fill(title);
        await page.getByLabel('کورتە').first().fill(`نرخی موڵک لە ئەنکاوە بەرزبووەتەوە بەهۆی داواکاری زیاتر ${token}`);
        await page.getByLabel('جۆری ڕووداو').first().selectOption('price');
        await page.getByLabel('بابەتی پەیوەندیدار').first().selectOption('area');
        await page.getByLabel('ناوچەکان').first().selectOption({ label: 'ئەنکاوە' });
        await page.getByLabel('جۆری بەڵگە').first().selectOption('admin_observation');
        await page.getByLabel('بەرواری ڕوودان').first().fill('2026-08-01');
        await page.getByLabel('سەرچاوە').first().fill('تیمی چاودێری بازاڕ');
        await page.getByRole('switch', { name: 'ڕێگەپێدانی بەکارهێنانی AI' }).click();

        await page.getByRole('button', { name: 'پاشەکەوت' }).click();

        const row = page.locator('li', { hasText: token }).first();
        await expect(row).toBeVisible({ timeout: 15_000 });

        /* ------------------- 2. the real approval workflow, two steps */

        await row.getByRole('button', { name: 'لە پێداچوونەوەدا' }).click();
        await expect(row.getByRole('button', { name: 'پەسەندکراو' })).toBeVisible({ timeout: 15_000 });
        await row.getByRole('button', { name: 'پەسەندکراو' }).click();

        // The three-condition AI gate, evaluated by the server, shows ACTIVE.
        await expect(row.getByText('AI بەکاری دەهێنێت')).toBeVisible({ timeout: 15_000 });

        /* -------------------- 3. the advisor grounds on it, AI off */

        await page.goto('/advisor', { waitUntil: 'networkidle' });

        // A persistent database may carry an older conversation; start clean
        // so the transcript below is exactly this run's. The reset is an
        // Inertia visit — wait for the EMPTIED transcript, not for network
        // idle, or the first send races the reload.
        await page.getByRole('button', { name: 'دەستپێکردنەوە' }).click();
        await expect(page.locator('ul.space-y-5 > *')).toHaveCount(0, { timeout: 15_000 });
        // A hard reload after the reset visit: the interview below must run
        // against a settled page, not the tail of an Inertia swap.
        await page.goto('/advisor', { waitUntil: 'networkidle' });

        await sendChat(page, 'بۆ نیشتەجێبوون');
        await sendChat(page, '١٥٠ هەزار دۆلار');
        await sendChat(page, 'شوقە');
        await sendChat(page, 'ئەنکاوە');
        await sendChat(page, 'بەسە');

        await sendChat(page, 'بۆچی نرخەکان لە ئەنکاوە بەرزبوونەوە؟');

        // The answer is built from THIS run's recorded insight…
        await expect(page.getByText(token).last()).toBeVisible();
        // …in the voice of its evidence class…
        await expect(page.getByText(OBSERVATION_STANCE_CKB).first()).toBeVisible();
        // …and the pre-Phase-12 generic dead end is gone from the whole
        // conversation.
        await expect(page.getByText(CONTINUE_HINT_CKB)).toHaveCount(0);
    });
});
