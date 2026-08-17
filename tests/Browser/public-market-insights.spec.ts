import { execFileSync } from 'node:child_process';
import { test, expect } from './support/harness';
import { signInAdmin } from './support/fixtures';

/*
 * Phase 13: public market-insight surfacing, end to end through the real
 * product — no AI anywhere.
 *
 * The desktop flow drives the complete editorial lifecycle in the real admin
 * (create → review → approve → publish) and watches the public area page
 * tell the truth at every step: an approved-but-unpublished insight is AI
 * evidence, NOT a public statement, and must stay invisible; a draft stays
 * invisible; a published-but-lapsed insight stays invisible even before the
 * hourly sweep flips its status; a published insight appears with its
 * evidence-class label, source and as-of date; a prediction wears its own
 * caution treatment and expected date, never a fact's clothing.
 *
 * The mobile flow pins that the timeline holds a 360px viewport with no
 * horizontal overflow, against the seeded fixture insight (the admin flow
 * runs on desktop only, and mobile viewports run first).
 */

/** ckb label for admin_observation — 'recorded by the team'. */
const OBSERVATION_CKB = 'تێبینیی تیم';

/** ckb label for prediction. */
const PREDICTION_CKB = 'پێشبینی';

/** ar label for admin_observation, asserted on the /ar visit. */
const OBSERVATION_AR = 'ملاحظة الفريق';

/** An ISO date `offset` days from now — for rows that must be freshly lapsed. */
function day(offset: number): string {
    return new Date(Date.now() + offset * 86_400_000).toISOString().slice(0, 10);
}

/**
 * Remove THIS SPEC'S leftovers from earlier runs against a persistent local
 * database. The admin knowledge index is newest-effective-first with a
 * 30-row page and no delete UI, and date ties resolve toward OLDER rows —
 * so accumulated token rows from previous runs eventually push the current
 * run's rows off page one, where the transition helper cannot reach them.
 * Same artisan-through-the-app pattern as clearRateLimiter(); scoped
 * strictly to rows this spec's own token format created.
 */
function purgeEarlierTokenRows(): void {
    try {
        execFileSync('php', [
            'artisan', 'tinker', '--execute',
            'App\\Modules\\Knowledge\\Models\\KnowledgeEvent::query()->where("title_ckb", "like", "%p13-%")->forceDelete();',
        ], {
            cwd: process.env.PLAYWRIGHT_ARTISAN_DIR ?? process.cwd(),
            stdio: 'ignore',
        });
    } catch {
        // Remote target or no artisan: accumulation may then bite on a very
        // reused database, and the create step reports it explicitly.
    }
}

async function areasEnabled(page: import('@playwright/test').Page): Promise<boolean> {
    const payload = await page.getAttribute('#app', 'data-page');
    const props = JSON.parse(payload ?? '{}').props ?? {};

    return props.features?.['geography.areas'] === true;
}

/** Fill and save one knowledge event through the real admin form. */
async function createEvent(
    page: import('@playwright/test').Page,
    fields: {
        title: string;
        summary?: string;
        evidenceClass: string;
        effectiveDate: string;
        expectedDate?: string;
        expiresOn?: string;
    },
): Promise<void> {
    await page.getByRole('button', { name: 'دروستکردنی ڕووداو' }).click();

    await page.getByLabel('ناو (کوردی)').first().fill(fields.title);
    if (fields.summary !== undefined) {
        await page.getByLabel('کورتە').first().fill(fields.summary);
    }
    await page.getByLabel('جۆری ڕووداو').first().selectOption('demand');
    await page.getByLabel('بابەتی پەیوەندیدار').first().selectOption('area');
    await page.getByLabel('ناوچەکان').first().selectOption({ label: 'ئەنکاوە' });
    await page.getByLabel('جۆری بەڵگە').first().selectOption(fields.evidenceClass);
    await page.getByLabel('بەرواری ڕوودان').first().fill(fields.effectiveDate);
    if (fields.expectedDate !== undefined) {
        await page.getByLabel('بەرواری چاوەڕوانکراو').first().fill(fields.expectedDate);
    }
    if (fields.expiresOn !== undefined) {
        await page.getByLabel('بەسەرچوون').first().fill(fields.expiresOn);
    }
    await page.getByLabel('سەرچاوە').first().fill('تیمی چاودێری بازاڕ');

    await page.getByRole('button', { name: 'پاشەکەوت' }).click();
    await expect(page.locator('li', { hasText: fields.title }).first()).toBeVisible({ timeout: 15_000 });
}

/** Walk one event's row through the given workflow transitions, in order. */
async function transition(
    page: import('@playwright/test').Page,
    title: string,
    statuses: string[],
): Promise<void> {
    const row = page.locator('li', { hasText: title }).first();

    for (const status of statuses) {
        await expect(row.getByRole('button', { name: status })).toBeVisible({ timeout: 15_000 });
        await row.getByRole('button', { name: status }).click();
    }
}

test.describe('public market insights', () => {
    test('the editorial lifecycle controls exactly what the public area page shows', async ({ page }, testInfo) => {
        testInfo.skip(
            testInfo.project.name !== 'desktop-1440x900',
            'the publish flow runs once, on desktop-1440x900 only',
        );

        await page.goto('/', { waitUntil: 'networkidle' });
        testInfo.skip(!(await areasEnabled(page)), 'geography.areas is OFF in this deployment');

        const token = `p13-${Date.now()}`;

        purgeEarlierTokenRows();
        await signInAdmin(page);
        await page.goto('/admin/knowledge', { waitUntil: 'networkidle' });

        /* ---- the observation, walked only as far as APPROVED for now */
        await createEvent(page, {
            title: `تێبینی بازاڕ ${token}`,
            summary: `داواکاری زیادی کردووە ${token}`,
            evidenceClass: 'admin_observation',
            effectiveDate: day(-11),
        });
        await transition(page, `تێبینی بازاڕ ${token}`, ['لە پێداچوونەوەدا', 'پەسەندکراو']);

        /*
         * The doctrine, live: approved is AI evidence, not a public
         * statement. The public page must not know this insight exists.
         */
        await page.goto('/areas/browser-ankawa', { waitUntil: 'networkidle' });
        await expect(page.getByText(token)).toHaveCount(0);

        /* -------------- publish it, and stage the three sibling states */
        await page.goto('/admin/knowledge', { waitUntil: 'networkidle' });
        await transition(page, `تێبینی بازاڕ ${token}`, ['بڵاوکراوە']);

        // A draft sibling: created, never advanced.
        await createEvent(page, {
            title: `ڕەشنووسی شاراوە ${token}`,
            evidenceClass: 'admin_observation',
            effectiveDate: day(-9),
        });

        // A prediction, fully published, carrying its expected date.
        await createEvent(page, {
            title: `پێشبینیی نرخ ${token}`,
            summary: `چاوەڕوان دەکرێت نرخەکان بەرز ببنەوە ${token}`,
            evidenceClass: 'prediction',
            effectiveDate: day(-10),
            expectedDate: '2026-12-01',
        });
        await transition(page, `پێشبینیی نرخ ${token}`, ['لە پێداچوونەوەدا', 'پەسەندکراو', 'بڵاوکراوە']);

        // A published-but-lapsed sibling: computed just-past dates, so it is
        // genuinely expired on ANY run date, sorts to the top of the admin
        // list (the index is newest-effective-first and paginated), and the
        // hourly sweep cannot have flipped it — the public query itself must
        // exclude it.
        await createEvent(page, {
            title: `بەسەرچووی شاراوە ${token}`,
            evidenceClass: 'admin_observation',
            effectiveDate: day(-2),
            expiresOn: day(-1),
        });
        await transition(page, `بەسەرچووی شاراوە ${token}`, ['لە پێداچوونەوەدا', 'پەسەندکراو', 'بڵاوکراوە']);

        /*
         * Phase 15: the admin search, through the real q input. The key is
         * derived from the titles now, so this run's draft title — token
         * included, unique to this run — must find exactly its own row,
         * and the prediction created seconds earlier must drop out of the
         * list. The input debounces into an Inertia GET, so the URL
         * carrying the query is the synchronization point, not a clock.
         */
        await page.locator('#q').fill(`ڕەشنووسی شاراوە ${token}`);
        await page.waitForURL((url) => String(url).includes('q='), { timeout: 10_000 });
        await expect(page.getByText(`ڕەشنووسی شاراوە ${token}`).first()).toBeVisible();
        await expect(page.getByText(`پێشبینیی نرخ ${token}`)).toHaveCount(0);

        /* ---------------------------------- the public page, in Kurdish */
        await page.goto('/areas/browser-ankawa', { waitUntil: 'networkidle' });

        // The published observation, complete with provenance.
        await expect(page.getByText(`داواکاری زیادی کردووە ${token}`)).toBeVisible();
        await expect(page.getByText(OBSERVATION_CKB).first()).toBeVisible();
        await expect(page.getByText('تیمی چاودێری بازاڕ').first()).toBeVisible();
        await expect(page.getByText(day(-11)).first()).toBeVisible();

        // The prediction, in caution clothing with its expected date.
        await expect(page.getByText(`پێشبینیی نرخ ${token}`)).toBeVisible();
        await expect(page.getByText(PREDICTION_CKB).first()).toBeVisible();
        await expect(page.getByText('چاوەڕوانکراو: 2026-12-01').first()).toBeVisible();

        // The draft and the lapsed sibling do not exist publicly.
        await expect(page.getByText(`ڕەشنووسی شاراوە ${token}`)).toHaveCount(0);
        await expect(page.getByText(`بەسەرچووی شاراوە ${token}`)).toHaveCount(0);

        /* ------------------------------------ the same page, in Arabic */
        await page.goto('/ar/areas/browser-ankawa', { waitUntil: 'networkidle' });

        // The seeded trilingual insight renders its Arabic text and the
        // Arabic evidence-class label.
        await expect(page.getByText('ارتفاع الطلب في عنكاوة')).toBeVisible();
        await expect(page.getByText(OBSERVATION_AR).first()).toBeVisible();
    });

    test('the area timeline holds a 360px viewport without horizontal overflow', async ({ page }, testInfo) => {
        testInfo.skip(
            testInfo.project.name !== 'mobile-360x800',
            'the overflow pin is a phone-width scenario',
        );

        await page.goto('/', { waitUntil: 'networkidle' });
        testInfo.skip(!(await areasEnabled(page)), 'geography.areas is OFF in this deployment');

        await page.goto('/areas/browser-ankawa', { waitUntil: 'networkidle' });

        // The seeded fixture insight is on screen — the measurement below
        // includes a real rendered timeline, not an empty section.
        await expect(page.getByText('داواکاری لەسەر موڵک لە ئەنکاوە بەرزبووەتەوە.')).toBeVisible();
        await expect(page.getByText(OBSERVATION_CKB).first()).toBeVisible();

        await expect
            .poll(async () => page.evaluate(() => document.documentElement.scrollWidth))
            .toBe(360);
        expect(await page.evaluate(() => document.documentElement.scrollLeft)).toBe(0);
    });
});
