import { onMounted, ref, watch } from 'vue';

type Theme = 'light' | 'dark' | 'system';

const theme = ref<Theme>('system');

/**
 * Dark mode.
 *
 * Deliberately not persisted to localStorage: artifacts of this project run in
 * environments where browser storage is unavailable, and more importantly a
 * signed-in administrator's preference belongs on their user record so it
 * follows them between the office desktop and their phone. Until that column
 * exists this honours the OS setting, which is the correct default anyway.
 */
export function useTheme() {
    const media = typeof window !== 'undefined' && window.matchMedia
        ? window.matchMedia('(prefers-color-scheme: dark)')
        : null;

    const apply = (value: Theme): void => {
        const root = document.documentElement;
        const dark = value === 'dark' || (value === 'system' && (media?.matches ?? false));
        root.classList.toggle('dark', dark);
    };

    onMounted(() => {
        apply(theme.value);
        media?.addEventListener('change', () => {
            if (theme.value === 'system') apply('system');
        });
    });

    watch(theme, apply);

    const cycle = (): void => {
        theme.value = theme.value === 'light' ? 'dark' : theme.value === 'dark' ? 'system' : 'light';
    };

    return { theme, cycle };
}
