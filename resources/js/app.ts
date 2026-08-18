import { createApp, h, type DefineComponent } from 'vue';
import { createInertiaApp, router } from '@inertiajs/vue3';
import { resolvePageComponent } from 'laravel-vite-plugin/inertia-helpers';
import { createPinia } from 'pinia';

/*
 * MapLibre's stylesheet, in the ONE entry every page shares. The library's
 * JS is lazy-loaded by the map adapter, but its CSS cannot be: it carries
 * the container/canvas positioning, controls, markers and popups, and its
 * absence was the root cause of every MapLibre surface rendering as an
 * unstyled grey rectangle in production (docs/MAP_PRODUCTION_AUDIT.md,
 * RC1). It lives here, exactly once, so no page can forget it — and the
 * frontend guard asserts it survives the build.
 */
import 'maplibre-gl/dist/maplibre-gl.css';

const appName = import.meta.env.VITE_APP_NAME ?? 'Mulkihawler';

/**
 * Keep <html lang> and <html dir> in step with the page being shown.
 *
 * The Blade root renders those attributes once, on the FIRST load. Every later
 * Inertia visit swaps the page component without touching the document — so a
 * visit that changes language left the document in the previous one. That was
 * invisible while language only ever changed via a full page load, and became
 * reachable on the main signup path when registration started landing people
 * on a page in the language they had just chosen: an Arabic page still marked
 * `lang="en" dir="ltr"`, so the whole layout stayed left-to-right.
 *
 * The values come from the shared props the server already sends, so this
 * follows the server's resolution rather than guessing from the URL.
 */
function syncDocumentLocale(pageProps: Record<string, unknown>): void {
    const locale = pageProps.locale as { current?: string; direction?: string } | undefined;

    if (locale?.current) {
        document.documentElement.lang = locale.current;
    }

    if (locale?.direction === 'rtl' || locale?.direction === 'ltr') {
        document.documentElement.dir = locale.direction;
    }
}

void createInertiaApp({
    title: (title) => (title ? `${title} — ${appName}` : appName),

    resolve: (name) =>
        resolvePageComponent(
            `./Pages/${name}.vue`,
            import.meta.glob<DefineComponent>('./Pages/**/*.vue'),
        ),

    setup({ el, App, props, plugin }) {
        syncDocumentLocale(props.initialPage.props as Record<string, unknown>);

        router.on('success', (event) => {
            syncDocumentLocale(event.detail.page.props as Record<string, unknown>);
        });

        createApp({ render: () => h(App, props) })
            .use(plugin)
            .use(createPinia())
            .mount(el);
    },

    progress: {
        // Champagne, matching the accent token's shipped default. Visible on
        // the ivory surface and inside dark bands without a second colour.
        color: 'rgb(185 142 47)',
        showSpinner: false,
    },
});
