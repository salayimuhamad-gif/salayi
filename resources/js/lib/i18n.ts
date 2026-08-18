import { usePage } from '@inertiajs/vue3';

/**
 * Translation lookup against the flat map shared by HandleInertiaRequests.
 *
 * Falls through to the KEY, never to English. That is the Step 1 decision
 * carried into the browser: a missing Sorani string must be a visible defect
 * that scripts/lang-parity.php catches in CI, not silent English on a Sorani
 * page that nobody reports because it looks intentional.
 */
export function t(key: string, replacements: Record<string, string | number> = {}): string {
    let value: string;

    try {
        const messages = (usePage().props as { translations?: Record<string, string> }).translations ?? {};
        value = messages[key] ?? key;
    } catch {
        // Called outside a component setup (a module-scope constant, a test).
        return key;
    }

    for (const [token, replacement] of Object.entries(replacements)) {
        value = value.replace(`:${token}`, String(replacement));
    }

    return value;
}

/** Latin digits with grouping, isolated from bidi reordering by `.numeral`. */
export function formatNumber(value: number | string, fractionDigits = 0): string {
    const numeric = typeof value === 'string' ? Number(value) : value;

    if (!Number.isFinite(numeric)) return '—';

    return new Intl.NumberFormat('en-GB', {
        minimumFractionDigits: fractionDigits,
        maximumFractionDigits: fractionDigits,
    }).format(numeric);
}
