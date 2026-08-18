<script setup lang="ts">
import { computed, ref } from 'vue';
import { useChartDraw } from '@/Composables/useChartDraw';

/*
 * The upgraded hand-rolled sparkline (redesign §13.3/§14h) — same
 * `series[{period,value,is_limited}]` shape the server already sends, no
 * chart library, and HONESTY IN THE VIZ ITSELF: limited-sample segments are
 * re-drawn as dashed caution overlays at the same coordinates, with hollow
 * markers on every limited point. The draw-on dasharray lives ONLY on the
 * main polyline, so the overlays' own dashing is never overridden by the
 * entrance animation (and vice versa).
 *
 * Charts are time-ordered and are NEVER mirrored under RTL (§8.4): the x
 * axis keeps physical direction in every locale.
 */
const props = withDefaults(defineProps<{
    series: Array<{ period: string; value: string | number; is_limited: boolean }>;
    height?: number;
    drawOn?: boolean;
}>(), { height: 32, drawOn: true });

const W = 200;

interface Pt {
    x: number;
    y: number;
    limited: boolean;
    period: string;
}

const polyline = ref<SVGPolylineElement | null>(null);
const drawOnRef = computed(() => props.drawOn);
const { pathLength, drawn } = useChartDraw(polyline, drawOnRef);

/* One scale drives every geometry, so overlays land exactly on the line. */
const pts = computed<Pt[]>(() => {
    const values = props.series.map((s) => Number(s.value));
    const min = Math.min(...values);
    const max = Math.max(...values);
    const span = max - min || 1;

    return props.series.map((s, i) => ({
        x: (i / Math.max(1, props.series.length - 1)) * W,
        y: props.height - 2 - ((Number(s.value) - min) / span) * (props.height - 4),
        limited: s.is_limited,
        period: s.period,
    }));
});

const points = computed(() => pts.value.map((p) => `${p.x.toFixed(1)},${p.y.toFixed(1)}`).join(' '));

/*
 * Contiguous limited runs, extended one neighbour each side where possible
 * so the dashed segment visually connects to the solid line.
 */
const limitedSegments = computed<string[]>(() => {
    const segments: string[] = [];
    const p = pts.value;
    let i = 0;

    while (i < p.length) {
        if (!p[i].limited) {
            i += 1;
            continue;
        }

        let j = i;
        while (j + 1 < p.length && p[j + 1].limited) j += 1;

        const from = Math.max(0, i - 1);
        const to = Math.min(p.length - 1, j + 1);

        if (to > from) {
            segments.push(p.slice(from, to + 1).map((q) => `${q.x.toFixed(1)},${q.y.toFixed(1)}`).join(' '));
        }

        i = j + 1;
    }

    return segments;
});

const limitedPoints = computed(() => pts.value.filter((p) => p.limited));
</script>

<template>
    <svg
        :viewBox="`0 0 ${W} ${height}`"
        :style="{ height: `${height}px` }"
        preserveAspectRatio="none"
        class="w-full"
        role="img"
    >
        <!-- Main trend line — the only element carrying the draw-on class. -->
        <polyline
            ref="polyline"
            :points="points"
            fill="none"
            stroke="rgb(var(--mh-brand-soft))"
            stroke-width="2"
            stroke-linecap="round"
            stroke-linejoin="round"
            vector-effect="non-scaling-stroke"
            :class="{ 'mh-chart-draw': drawOn, 'is-drawn': drawn }"
            :style="drawOn ? { '--len': pathLength } : undefined"
        />
        <!-- Honesty overlay: limited runs dashed at the SAME coordinates.
             caution-bright is correct here — graphics-scale usage (§11.1). -->
        <polyline
            v-for="segment in limitedSegments"
            :key="segment"
            :points="segment"
            fill="none"
            stroke="rgb(var(--mh-caution-bright))"
            stroke-width="2"
            stroke-dasharray="4 3"
            stroke-linecap="round"
            stroke-linejoin="round"
            vector-effect="non-scaling-stroke"
        />
        <circle
            v-for="p in limitedPoints"
            :key="p.period"
            :cx="p.x"
            :cy="p.y"
            r="2.5"
            fill="rgb(var(--mh-surface-raised))"
            stroke="rgb(var(--mh-caution-bright))"
            stroke-width="1.5"
        />
    </svg>
</template>
