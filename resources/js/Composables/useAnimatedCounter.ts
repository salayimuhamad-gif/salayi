import { onBeforeUnmount, onMounted, ref, watch, type Ref } from 'vue';
import { formatNumber } from '@/lib/i18n';

/*
 * Tweens 0 → value once, when `el` first intersects (redesign §9).
 *
 * The initial render is ALWAYS the final value — that is what reduced-motion
 * users, no-JS clients and everyone before intersection must see (§9.3). The
 * zeroing happens only after the matchMedia check confirms motion is allowed.
 * Formatting is the product's own `formatNumber` (en-GB, Latin digits) so a
 * counter can never invent a different numeral presentation.
 */
export function useAnimatedCounter(
    el: Ref<HTMLElement | null>,
    value: Ref<number>,
    options: { duration?: number; format?: (n: number) => string } = {},
): { display: Ref<string> } {
    const { duration = 900, format = (n: number) => formatNumber(Math.round(n)) } = options;
    const display = ref(format(value.value));
    let raf = 0;
    let observer: IntersectionObserver | null = null;

    const run = (): void => {
        const start = performance.now();
        const tick = (now: number): void => {
            const progress = Math.min(1, (now - start) / duration);
            const eased = 1 - Math.pow(1 - progress, 3);
            display.value = format(value.value * eased);
            if (progress < 1) raf = requestAnimationFrame(tick);
        };
        raf = requestAnimationFrame(tick);
    };

    onMounted(() => {
        const target = el.value;
        if (!target) return;
        if (typeof IntersectionObserver === 'undefined') return;
        if (window.matchMedia('(prefers-reduced-motion: reduce)').matches) return;

        display.value = format(0);
        observer = new IntersectionObserver(
            ([entry]) => {
                if (entry.isIntersecting) {
                    run();
                    observer?.disconnect();
                }
            },
            { threshold: 0.4 },
        );
        observer.observe(target);
    });

    // A late prop change (new payload) re-renders the final value directly —
    // the entrance tween is a one-shot, not a live-update animation.
    watch(value, (next) => {
        cancelAnimationFrame(raf);
        display.value = format(next);
    });

    onBeforeUnmount(() => {
        cancelAnimationFrame(raf);
        observer?.disconnect();
    });

    return { display };
}
