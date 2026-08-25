import { computed, ref, watch } from 'vue';

type Theme = 'light' | 'dark' | 'system';

const STORAGE_KEY = 'mh-theme';

/*
 * Storage access is best-effort everywhere: this project runs in contexts
 * where browser storage throws (embedded webviews, storage-blocked browsers),
 * and a theme preference is a convenience, never a requirement. A visitor
 * whose storage is unavailable simply gets the defaults on each load.
 */
function readStored(): Theme {
    if (typeof window === 'undefined') {
        return 'system';
    }

    try {
        const value = window.localStorage.getItem(STORAGE_KEY);

        return value === 'light' || value === 'dark' ? value : 'system';
    } catch {
        return 'system';
    }
}

function writeStored(value: Theme): void {
    try {
        if (value === 'system') {
            window.localStorage.removeItem(STORAGE_KEY);
        } else {
            window.localStorage.setItem(STORAGE_KEY, value);
        }
    } catch {
        // Storage unavailable — the in-memory state still drives this visit.
    }
}

const theme = ref<Theme>(readStored());

const media = typeof window !== 'undefined' && window.matchMedia
    ? window.matchMedia('(prefers-color-scheme: dark)')
    : null;

function apply(value: Theme): void {
    const root = document.documentElement;
    const dark = value === 'dark' || (value === 'system' && (media?.matches ?? false));
    root.classList.toggle('dark', dark);

    /*
     * Keep the pre-paint marker the boot script set in step with the live
     * state, so a back/forward-cache restore of a public page paints the
     * palette the visitor last chose.
     */
    if (root.dataset.mhShell === 'public') {
        root.classList.toggle('mh-boot-night', value !== 'light');
    }
}

/*
 * Singleton wiring, once per document rather than once per consumer: the
 * composable used to arm its media listener inside every component that
 * called it, which was fine while the admin header was the only caller and
 * became a slow listener pile-up once the public shell (layout, topbar,
 * offline screen) joined. State changes re-render through the one shared
 * ref; the watcher persists the choice and repaints the document classes.
 */
if (typeof document !== 'undefined') {
    apply(theme.value);

    media?.addEventListener('change', () => {
        if (theme.value === 'system') apply('system');
    });

    watch(theme, (value) => {
        apply(value);
        writeStored(value);
    });
}

/** Night-first public palette: true unless the visitor chose light. */
const night = computed(() => theme.value !== 'light');

/**
 * Theme state, shared by the admin shell and the public shell.
 *
 * One state, one storage key, two consumers:
 *
 *   - The ADMIN shell keeps its original contract: `html.dark` remaps the
 *     core tokens, `system` follows the OS, `cycle` walks light → dark →
 *     system. Nothing an admin screen renders has changed.
 *
 *   - The PUBLIC shell reads the same state through `night`: the Midnight
 *     Amber palette is the site's designed default, so `night` is true for
 *     both 'dark' AND 'system' (no explicit choice) and false only when the
 *     visitor explicitly chose 'light'. `togglePublic` writes an explicit
 *     choice, which the admin shell then also honours — the preference is
 *     one preference, wherever it was expressed.
 *
 * An explicit choice persists to localStorage (guarded above) so the
 * selected appearance survives reload; the inline boot script in
 * app.blade.php reads the same key before first paint so neither themed
 * shell flashes the wrong scheme. A signed-in preference column remains the
 * better long-term home and would supersede this without changing the API.
 */
export function useTheme() {
    const cycle = (): void => {
        theme.value = theme.value === 'light' ? 'dark' : theme.value === 'dark' ? 'system' : 'light';
    };

    /** The public control is a plain two-state switch, always explicit. */
    const togglePublic = (): void => {
        theme.value = night.value ? 'light' : 'dark';
    };

    return { theme, cycle, night, togglePublic };
}
