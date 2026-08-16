import { test, expect } from './support/harness';
import { fixtures, signInAdmin } from './support/fixtures';

/*
 * Phase 11: the create-project flow, end to end, in the shape it failed in
 * production.
 *
 * Four defects conspired against the first real project. (1) The legacy
 * form's `source` field carried a template-only `required` inside a
 * v-show-hidden section, so the browser's native constraint validation
 * silently vetoed EVERY submission — no request, no message. (2) No locale
 * shipped a validation.php while both locale and fallback are ckb, so the
 * failures that did reach the server rendered raw keys: `validation.required`,
 * `validation.regex`. (3) The slug field was labelled "web address"
 * (ناونیشانی وێب), inviting the site URL to be pasted into it, where the
 * (correct) slug regex rejects it. (4) The wizard's project-type select
 * translated its options through `projects.project_types.*`, a key group
 * that has never existed — raw keys as option labels.
 *
 * Pre-fix, every test below fails: the create submission goes nowhere, the
 * slug error is a raw key, and the wizard options are raw keys.
 */

/**
 * The legacy form is sectioned with v-show; a field outside the active
 * section exists but is invisible. Walk the section nav until the target
 * input is visible, then fill it.
 */
async function fillSectioned(
    page: import('@playwright/test').Page,
    labelText: string,
    value: string,
): Promise<void> {
    const input = page.getByLabel(labelText).first();

    if (!(await input.isVisible())) {
        const nav = page.locator('nav.mb-6 button');
        const count = await nav.count();

        for (let i = 0; i < count; i += 1) {
            await nav.nth(i).click();
            if (await input.isVisible()) break;
        }
    }

    await input.fill(value);
}

async function revealSection(
    page: import('@playwright/test').Page,
    locator: import('@playwright/test').Locator,
): Promise<void> {
    if (await locator.isVisible()) return;

    const nav = page.locator('nav.mb-6 button');
    const count = await nav.count();

    for (let i = 0; i < count; i += 1) {
        await nav.nth(i).click();
        if (await locator.isVisible()) return;
    }
}

test.describe('admin project creation', () => {
    test.beforeEach(async ({ page }, testInfo) => {
        testInfo.skip(
            testInfo.project.name !== 'desktop-1440x900',
            'admin creation flow runs once, on desktop-1440x900 only',
        );
        await signInAdmin(page);
    });

    test('a real project is created through the legacy form and every value persists', async ({ page }) => {
        const slug = `p11-e2e-${Date.now()}`;

        await page.goto('/admin/projects/create', { waitUntil: 'networkidle' });

        await page.getByLabel('ناو (کوردی)').first().fill('پڕۆژەی تاقیکردنەوەی فۆرم');
        await page.getByLabel('ناو (عەرەبی)').first().fill('مشروع اختبار النموذج');
        await page.getByLabel('ناو (ئینگلیزی)').first().fill('Form Truthfulness Tower');
        await page.getByLabel('ناسنامەی بەستەر (slug)').first().fill(slug);
        await fillSectioned(page, 'ماڵپەڕی فەرمی', 'https://myhawler.com/');

        /*
         * `source` is deliberately left empty: the server says nullable, and
         * pre-fix a stray template `required` on it — hidden in a v-show'd
         * section — made the browser veto this submit silently. This click
         * producing a real request IS the regression pin.
         */
        await page.locator('form button[type=submit]').last().click();

        await page.waitForURL(/\/admin\/projects\/\d+\/edit/, { timeout: 15_000 });

        // A fresh load proves persistence, not form-state leftovers.
        await page.reload({ waitUntil: 'networkidle' });

        await expect(page.getByLabel('ناو (کوردی)').first()).toHaveValue('پڕۆژەی تاقیکردنەوەی فۆرم');
        await expect(page.getByLabel('ناو (عەرەبی)').first()).toHaveValue('مشروع اختبار النموذج');
        await expect(page.getByLabel('ناو (ئینگلیزی)').first()).toHaveValue('Form Truthfulness Tower');
        await expect(page.getByLabel('جۆری پڕۆژە').first()).toHaveValue('residential');

        const official = page.getByLabel('ماڵپەڕی فەرمی').first();
        await revealSection(page, official);
        await expect(official).toHaveValue('https://myhawler.com/');
    });

    test('a URL pasted into the slug field fails in words, not in raw keys', async ({ page }) => {
        await page.goto('/admin/projects/create', { waitUntil: 'networkidle' });

        await page.getByLabel('ناو (کوردی)').first().fill('پڕۆژەی هەڵەی ناسنامە');
        await page.getByLabel('ناسنامەی بەستەر (slug)').first().fill('https://myhawler.com/');
        await page.locator('form button[type=submit]').last().click();

        // The rejection must be a sentence a person can act on…
        await expect(page.getByText('شێوازی خانەی').first()).toBeVisible({ timeout: 15_000 });

        // …and NOTHING on the page may leak a raw translation key. This is
        // the app-wide contract the missing validation.php files broke.
        await expect(page.locator('body')).not.toContainText('validation.');
    });

    test('the wizard names every project type in Kurdish, never in raw keys', async ({ page }) => {
        await page.goto(
            `/admin/projects/wizard/${fixtures().wizard_draft_id}/identity`,
            { waitUntil: 'networkidle' },
        );

        const select = page.locator('select').first();
        await expect(select).toBeVisible({ timeout: 15_000 });

        const labels = await select.evaluate((el) =>
            Array.from((el as HTMLSelectElement).options).map((option) => option.textContent?.trim() ?? ''));

        expect(labels.join(' ')).not.toContain('projects.project_types');
        expect(labels).toContain('نیشتەجێبوون');

        // The submitted VALUE stays the raw enum — only the label was broken.
        const residential = await select.evaluate((el) =>
            Array.from((el as HTMLSelectElement).options).find((option) => option.textContent?.trim() === 'نیشتەجێبوون')?.value);
        expect(residential).toBe('residential');

        /*
         * The wizard's slug field has its OWN label key
         * (projects.wizard.creation.field_slug), separate from the legacy
         * form's projects.fields.slug — and the Kurdish value of that second
         * key ALSO read "web address" (ناونیشانی وێب). Fixing one key and not
         * the other left the flagship step-by-step flow inviting the site
         * URL into the slug field; this pin covers the second door.
         */
        await expect(page.getByLabel('ناسنامەی بەستەر (slug)').first()).toBeVisible();
        await expect(page.getByText('ناونیشانی وێب')).toHaveCount(0);
    });
});
