import { computed } from 'vue';
import { router, usePage } from '@inertiajs/vue3';
import { localizedPath, type UrlStrategy } from '@/lib/localeUrl';
import type { LocaleCode, SharedPageProps } from '@/Types/inertia';

/**
 * Locale state and switching.
 *
 * Two mechanisms, because there are two kinds of page.
 *
 * On a LOCALIZED PUBLIC route the URL is authoritative — the route declares its
 * own locale and SetLocale reads that before the session. So switching must
 * NAVIGATE to the sibling URL. A session POST there does nothing at all, which
 * is the defect this replaces: after locale-prefixed routing shipped, tapping
 * "کوردی" on an Arabic project page changed neither the URL nor the content.
 *
 * Everywhere else — the admin, which is not locale-routed — the session is the
 * only signal, so the POST remains correct.
 *
 * Both paths end in a full document load rather than an Inertia partial visit,
 * because `dir` and `lang` are rendered server-side to avoid a direction flash
 * and cannot be corrected by a client-side patch.
 */
export function useLocale() {
    const page = usePage<SharedPageProps>();

    const current = computed(() => page.props.locale.current);
    const direction = computed(() => page.props.locale.direction);
    const isRtl = computed(() => direction.value === 'rtl');
    const available = computed(() => page.props.locale.available);

    function switchTo(code: LocaleCode): void {
        if (code === current.value) {
            return;
        }

        const meta = page.props.locale;

        if (meta.route_is_localized) {
            const target = localizedPath(
                window.location.pathname + window.location.search + window.location.hash,
                code,
                meta.default,
                available.value.map((locale) => locale.code),
                meta.url_strategy as UrlStrategy,
            );

            window.location.assign(target);

            return;
        }

        // Admin and other non-localized routes: the session is the only signal.
        router.post(
            `/locale/${code}`,
            {},
            {
                preserveScroll: true,
                onSuccess: () => window.location.reload(),
            },
        );
    }

    /**
     * Format a number for display. Digits stay Latin and the string is wrapped
     * for bidi isolation at the component level (see the .numeral class).
     */
    function formatNumber(value: number | string, fractionDigits = 0): string {
        const numeric = typeof value === 'string' ? Number(value) : value;

        if (!Number.isFinite(numeric)) {
            return '—';
        }

        return new Intl.NumberFormat('en-GB', {
            minimumFractionDigits: fractionDigits,
            maximumFractionDigits: fractionDigits,
        }).format(numeric);
    }

    /**
     * The current-locale form of a bare public path.
     *
     * Components were writing `/market`, `/offers`, `/projects/${slug}` as
     * literals. Under `prefix_except_default` those are the *Sorani* URLs, so
     * every navigation from an Arabic or English page silently dropped the
     * visitor back into Sorani — the same class of defect as the hreflang
     * alternates, arriving through the front door instead.
     *
     * `localizedPath` already knows the rule and takes the strategy from the
     * server rather than assuming it, so this is a binding, not a second
     * implementation. It strips any prefix already present, which makes it
     * safe to wrap a path that is already localized.
     *
     * On a non-localized surface (the admin) the path is returned untouched:
     * those routes are not registered per locale and prefixing them would 404.
     */
    function localized(path: string): string {
        if (!page.props.locale.route_is_localized) {
            return path;
        }

        return localizedPath(
            path,
            current.value,
            page.props.locale.default,
            available.value.map((locale) => locale.code),
            page.props.locale.url_strategy as UrlStrategy,
        );
    }

    return { current, direction, isRtl, available, switchTo, formatNumber, localized };
}
