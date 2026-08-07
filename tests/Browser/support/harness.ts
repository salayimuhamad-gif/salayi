import { test as base, expect, type Page } from '@playwright/test';

/*
 * Shared fixtures and probes for the acceptance suite.
 *
 * THE CONSOLE FIXTURE IS THE POINT OF THIS FILE.
 *
 * A Vue hydration mismatch, a duplicate-key warning or a failed asset does not
 * fail an assertion — the page still renders, the test still passes, and the
 * defect ships. Every test in this suite therefore fails if the browser logged
 * an error or a Vue warning while it ran, whether or not the test looked for
 * one. That is what makes this a real acceptance gate rather than a set of
 * screenshots.
 *
 * Known-benign noise is filtered by exact prefix, never by a broad pattern:
 * a filter wide enough to be convenient is wide enough to hide the next
 * regression.
 */
const IGNORED_CONSOLE = [
    // Chromium emits this for any favicon a dev server does not serve.
    'Failed to load resource: the server responded with a status of 404 (Not Found)',
];

export interface PageDiagnostics {
    consoleErrors: string[];
    pageErrors: string[];
    failedRequests: string[];
}

export const test = base.extend<{ hermeticNetwork: void; diagnostics: PageDiagnostics }>({
    /*
     * Automatic, not opt-in.
     *
     * This was originally folded into `diagnostics`, which meant any test that
     * did not destructure that fixture ran against the live internet — and
     * `production-assets.spec.ts`, the one test whose entire job is to watch
     * the network, was exactly such a test. It failed on an unreachable webfont
     * and reported it as a product defect. A hermetic network has to be a
     * property of the suite, not something each test remembers to ask for.
     */
    hermeticNetwork: [
        async ({ page }, use) => {
            const origin = new URL(process.env.PLAYWRIGHT_BASE_URL ?? 'http://127.0.0.1:8100').host;

            await page.route('**/*', (route) => {
                const url = new URL(route.request().url());

                if (url.host === origin) {
                    void route.continue();

                    return;
                }

                void route.fulfill({ status: 200, contentType: 'text/css', body: '' });
            });

            await use();
        },
        { auto: true },
    ],

    diagnostics: async ({ page }, use) => {
        const diagnostics: PageDiagnostics = {
            consoleErrors: [],
            pageErrors: [],
            failedRequests: [],
        };

        page.on('console', (message) => {
            const text = message.text();

            if (IGNORED_CONSOLE.some((prefix) => text.startsWith(prefix))) {
                return;
            }

            // Vue emits its warnings at `warning` level, so filtering to
            // `error` alone would let every hydration mismatch through.
            if (message.type() === 'error') {
                diagnostics.consoleErrors.push(text);

                return;
            }

            if (message.type() === 'warning' && /\[Vue warn\]|hydration/i.test(text)) {
                diagnostics.consoleErrors.push(text);
            }
        });

        page.on('pageerror', (error) => {
            diagnostics.pageErrors.push(error.message);
        });

        page.on('requestfailed', (request) => {
            diagnostics.failedRequests.push(`${request.method()} ${request.url()}`);
        });

        await use(diagnostics);

        expect(diagnostics.pageErrors, 'uncaught page errors').toEqual([]);
        expect(diagnostics.consoleErrors, 'console errors and Vue warnings').toEqual([]);
    },
});

export { expect };

/** The three shipped locales and the path prefix each one uses. */
export const LOCALES = [
    { code: 'ckb', prefix: '', direction: 'rtl' },
    { code: 'ar', prefix: '/ar', direction: 'rtl' },
    { code: 'en', prefix: '/en', direction: 'ltr' },
] as const;

/**
 * The document must not scroll sideways.
 *
 * One pixel of tolerance, because sub-pixel layout rounding produces a
 * scrollWidth a fraction over the viewport on perfectly correct pages, and a
 * zero-tolerance check would fail on arithmetic rather than on a defect.
 */
export async function expectNoHorizontalOverflow(page: Page): Promise<void> {
    const overflow = await page.evaluate(() => ({
        scrollWidth: document.documentElement.scrollWidth,
        clientWidth: document.documentElement.clientWidth,
        offender: (() => {
            const limit = document.documentElement.clientWidth;

            for (const element of Array.from(document.querySelectorAll('*'))) {
                const box = element.getBoundingClientRect();

                if (box.width > 0 && box.right > limit + 1) {
                    return `${element.tagName.toLowerCase()}.${(element.className || '').toString().slice(0, 60)}`;
                }
            }

            return null;
        })(),
    }));

    expect(
        overflow.scrollWidth,
        `horizontal overflow; first offending element: ${overflow.offender ?? 'none identified'}`,
    ).toBeLessThanOrEqual(overflow.clientWidth + 1);
}

/**
 * An `id` is document-global. Two elements sharing one is invalid HTML and
 * breaks every `url(#…)`, `<label for>` and `aria-labelledby` pointing at it —
 * which is exactly the class of defect that put duplicate SVG gradient ids in
 * this codebase in the first place.
 */
export async function expectNoDuplicateIds(page: Page): Promise<void> {
    const duplicates = await page.evaluate(() => {
        const seen = new Map<string, number>();

        for (const element of Array.from(document.querySelectorAll('[id]'))) {
            const id = element.id;

            if (id !== '') {
                seen.set(id, (seen.get(id) ?? 0) + 1);
            }
        }

        return Array.from(seen.entries())
            .filter(([, count]) => count > 1)
            .map(([id, count]) => `${id} ×${count}`);
    });

    expect(duplicates, 'duplicate DOM ids').toEqual([]);
}

/**
 * Visible text must not be cut off by a fixed-size box.
 *
 * `.truncate` is excluded deliberately: an ellipsis is a designed outcome, not
 * a clipping defect. Everything else that hides its own overflow is a bug.
 */
export async function expectNoTextClipping(page: Page): Promise<void> {
    const clipped = await page.evaluate(() => {
        const offenders: string[] = [];
        const candidates = document.querySelectorAll(
            'h1, h2, h3, p, span, a, button, label, dt, dd',
        );

        for (const element of Array.from(candidates)) {
            if (element.classList.contains('truncate')) continue;

            // The visually-hidden pattern (Tailwind's `sr-only`) is a 1×1 box
            // with hidden overflow BY DESIGN — that is how a skip link stays
            // available to a screen reader without occupying the page. Reading
            // it as clipped text is reading the technique as the bug.
            if (element.classList.contains('sr-only')) continue;

            const style = window.getComputedStyle(element);

            if (style.overflow === 'visible' && style.overflowY === 'visible') continue;
            if (element.scrollHeight <= element.clientHeight + 1) continue;
            if (element.clientHeight === 0) continue;

            offenders.push(
                `${element.tagName.toLowerCase()}: "${(element.textContent ?? '').trim().slice(0, 40)}"`,
            );
        }

        return offenders;
    });

    expect(clipped, 'clipped text').toEqual([]);
}

/**
 * Interactive controls need a 44px target on a touch viewport.
 *
 * Measured from the rendered box, not from the class list — a `min-h-11` that
 * loses a specificity fight is exactly the failure this is here to catch.
 * Elements inside running text are exempt: an inline link in a paragraph
 * cannot be 44px tall without breaking the paragraph.
 */
export async function expectTouchTargets(page: Page): Promise<void> {
    const small = await page.evaluate(() => {
        const offenders: string[] = [];
        const controls = document.querySelectorAll('button, a[href], [role="button"], input, select');

        for (const element of Array.from(controls)) {
            const style = window.getComputedStyle(element);

            if (style.display === 'inline') continue;
            if (style.visibility === 'hidden' || style.display === 'none') continue;

            // Not a pointer target until it is focused, and when focused it is
            // sized by `focus:not-sr-only`. Measuring it at rest measures the
            // hiding technique.
            if (element.classList.contains('sr-only')) continue;

            /*
             * A checkbox is 16px because the platform draws it at 16px. What
             * the finger actually hits is the label, so that is what gets
             * measured — WCAG sizes the TARGET, not the glyph. A control with
             * no label falls through and is measured directly, which is the
             * correct answer for one nobody can tap by its text either.
             */
            const type = (element as HTMLInputElement).type ?? '';
            const measured =
                type === 'checkbox' || type === 'radio'
                    ? (element.closest('label') ??
                       (element.id === ''
                           ? element
                           : document.querySelector(`label[for="${CSS.escape(element.id)}"]`) ?? element))
                    : element;

            const box = measured.getBoundingClientRect();

            if (box.width === 0 || box.height === 0) continue;
            if (box.height >= 44) continue;

            offenders.push(
                `${element.tagName.toLowerCase()}[${Math.round(box.width)}×${Math.round(box.height)}] "${(element.textContent ?? '').trim().slice(0, 30)}"`,
            );
        }

        return offenders;
    });

    expect(small, 'controls below the 44px touch target').toEqual([]);
}
