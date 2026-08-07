import { createApp, h, type DefineComponent } from 'vue';
import { createInertiaApp, router } from '@inertiajs/vue3';
import { resolvePageComponent } from 'laravel-vite-plugin/inertia-helpers';
import { createPinia } from 'pinia';

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
        // Brass, matching the accent token. Visible against both the light
        // and dark surface without a second colour.
        color: 'rgb(201 162 39)',
        showSpinner: false,
    },
});
