import { onMounted, ref, type Ref } from 'vue';

/*
 * Draw-on helper for SVG polylines (redesign §9): measures the path length,
 * then flips `drawn` on first intersection so the CSS `.mh-chart-draw`
 * dashoffset transition runs once. Under reduced motion (or when drawOn is
 * off) the chart renders fully drawn immediately — the animation is
 * presentation, never information.
 */
export function useChartDraw(
    polyline: Ref<SVGPolylineElement | null>,
    drawOn: Ref<boolean>,
): { pathLength: Ref<number>; drawn: Ref<boolean> } {
    const pathLength = ref(0);
    const drawn = ref(false);
    let observer: IntersectionObserver | null = null;

    onMounted(() => {
        const el = polyline.value;
        if (!el) {
            drawn.value = true;
            return;
        }

        try {
            pathLength.value = el.getTotalLength();
        } catch {
            // jsdom and detached nodes have no geometry — render drawn.
            drawn.value = true;
            return;
        }

        const reduce = typeof window !== 'undefined'
            && window.matchMedia('(prefers-reduced-motion: reduce)').matches;

        if (!drawOn.value || reduce || typeof IntersectionObserver === 'undefined') {
            drawn.value = true;
            return;
        }

        observer = new IntersectionObserver(([entry]) => {
            if (entry.isIntersecting) {
                drawn.value = true;
                observer?.disconnect();
            }
        }, { threshold: 0.4 });
        observer.observe(el);
    });

    return { pathLength, drawn };
}
