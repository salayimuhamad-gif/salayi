import { onBeforeUnmount, onMounted, type Ref } from 'vue';

/*
 * Reveals `.mh-reveal` descendants of `root` on intersection, with sibling
 * stagger (redesign §9).
 *
 * THE HIDDEN STATE IS ARMED BY JS, NEVER BY CSS ALONE: elements are given
 * `.mh-reveal-ready` (the opacity-0 state in public.css) only after a
 * matchMedia check confirms motion is allowed. Reduced-motion users and
 * no-JS clients therefore never receive invisible content — they simply see
 * everything, statically. Reveal is vertical-only (translateY): nothing to
 * mirror, no RTL slide hazard.
 */
export function useScrollReveal(
    root: Ref<HTMLElement | null>,
    options: { stagger?: number; threshold?: number } = {},
): void {
    const { stagger = 60, threshold = 0.15 } = options;
    let observer: IntersectionObserver | null = null;

    onMounted(() => {
        const el = root.value;
        if (!el) return;
        if (typeof IntersectionObserver === 'undefined') return;
        if (window.matchMedia('(prefers-reduced-motion: reduce)').matches) return;

        const targets = Array.from(el.querySelectorAll<HTMLElement>('.mh-reveal'));
        if (targets.length === 0) return;

        targets.forEach((target, index) => {
            target.style.setProperty('--stagger', `${(index % 8) * stagger}ms`);
            target.classList.add('mh-reveal-ready');
        });

        observer = new IntersectionObserver(
            (entries) => {
                for (const entry of entries) {
                    if (entry.isIntersecting) {
                        entry.target.classList.add('is-revealed');
                        observer?.unobserve(entry.target);
                    }
                }
            },
            { threshold, rootMargin: '0px 0px -8% 0px' },
        );

        targets.forEach((target) => observer?.observe(target));
    });

    onBeforeUnmount(() => observer?.disconnect());
}
