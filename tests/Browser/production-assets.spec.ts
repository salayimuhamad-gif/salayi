import { test, expect } from './support/harness';

/*
 * The compiled bundle, as the browser actually loads it.
 *
 * A missing Vite manifest entry produces a blank page and a 200, so this
 * watches the network rather than the markup. It also refuses any development
 * URL, because a localhost reference that survives into a production build
 * fails only on the customer's machine.
 */
test('every asset the page requests is served', async ({ page }) => {
    const failures: string[] = [];
    const notFound: string[] = [];

    page.on('requestfailed', (request) => failures.push(`${request.method()} ${request.url()}`));
    page.on('response', (response) => {
        if (response.status() >= 400 && !response.url().includes('favicon')) {
            notFound.push(`${response.status()} ${response.url()}`);
        }
    });

    await page.goto('/', { waitUntil: 'networkidle' });

    expect(failures, 'failed requests').toEqual([]);
    expect(notFound, 'error responses').toEqual([]);
});

test('the compiled JS and CSS actually load', async ({ page }) => {
    const loaded: string[] = [];

    page.on('response', (response) => {
        if (/\/build\/assets\/.+\.(js|css)$/.test(response.url()) && response.status() === 200) {
            loaded.push(response.url());
        }
    });

    await page.goto('/', { waitUntil: 'networkidle' });

    expect(loaded.some((url) => url.endsWith('.js')), 'a compiled JS bundle').toBe(true);
    expect(loaded.some((url) => url.endsWith('.css')), 'a compiled CSS bundle').toBe(true);
});

test('no development URL survives into the rendered page', async ({ page }) => {
    await page.goto('/', { waitUntil: 'networkidle' });

    const html = await page.content();
    for (const marker of ['localhost:5173', '127.0.0.1:5173', '@vite/client']) {
        expect(html.includes(marker), `dev marker ${marker}`).toBe(false);
    }
});

test('Vue mounted and the shell is interactive', async ({ page }) => {
    await page.goto('/', { waitUntil: 'networkidle' });

    // The scoped theme class is applied by the Vue layout, so its presence in
    // the live DOM proves hydration completed rather than merely that HTML
    // arrived.
    await expect(page.locator('.mh-luxury-public')).toBeVisible();
});
