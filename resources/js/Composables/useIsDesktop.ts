import { onScopeDispose, ref, type Ref } from 'vue';

/*
 * The lg breakpoint (min-width: 1024px) as REACTIVE state — the split the map
 * surfaces use between the desktop selection popover and the mobile bottom
 * sheet. A render-time `matchMedia(...).matches` call reads the viewport once
 * per re-render and never on its own: rotating a tablet or dragging the
 * window across 1024px with a selection open left the wrong presentation
 * mounted, including the bottom sheet's body scroll-lock. One `change`
 * listener per consuming component, removed with the component's scope; no
 * window resize listener, so nothing fires on every pixel of a drag.
 */
export function useIsDesktop(): Ref<boolean> {
    if (typeof window === 'undefined' || typeof window.matchMedia !== 'function') {
        // Server render / bare test DOM: settle on the mobile presentation,
        // which every surface treats as the safe default.
        return ref(false);
    }

    const media = window.matchMedia('(min-width: 1024px)');
    const isDesktop = ref(media.matches);

    const onChange = (event: MediaQueryListEvent): void => {
        isDesktop.value = event.matches;
    };

    media.addEventListener('change', onChange);
    onScopeDispose(() => media.removeEventListener('change', onChange));

    return isDesktop;
}
