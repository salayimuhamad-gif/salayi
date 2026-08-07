import { test, expect, LOCALES } from './support/harness';

/*
 * URL-based locale routing.
 *
 * `prefix_except_default`: ckb is unprefixed, ar and en carry a prefix, and an
 * explicit /ckb prefix redirects to the canonical form so one address never
 * serves two languages. /ar and /en previously 404'd on the homepage alone,
 * which made the top bar's own brand link a dead end in two of three languages.
 */
for (const locale of LOCALES) {
    test(`the homepage resolves at ${locale.prefix || '/'} [${locale.code}]`, async ({ page }) => {
        const response = await page.goto(`${locale.prefix}/`);

        expect(response?.status()).toBe(200);
        await expect(page.locator('html')).toHaveAttribute('lang', locale.code);
        await expect(page.locator('html')).toHaveAttribute('dir', locale.direction);
    });

    test(`the projects index resolves at ${locale.prefix}/projects [${locale.code}]`, async ({ page }) => {
        const response = await page.goto(`${locale.prefix}/projects`);

        expect(response?.status()).toBe(200);
    });
}

test('the default-locale prefix redirects to the canonical path', async ({ page }) => {
    await page.goto('/ckb/projects');

    expect(new URL(page.url()).pathname).toBe('/projects');
});

test('the brand link in the top bar is not a dead end', async ({ page }) => {
    // The exact regression: localized('/') returns /ar on an Arabic page.
    await page.goto('/ar/projects', { waitUntil: 'networkidle' });

    const href = await page.getAttribute('header a[href]', 'href');
    expect(href).toBe('/ar');

    const response = await page.goto('/ar');
    expect(response?.status()).toBe(200);
});
