<script setup lang="ts">
import { computed, useId } from 'vue';

/*
 * The AI orb (homepage re-architecture §1/§6): the hero's compact visual
 * identity mark — a teal sphere with two slow orbit rings.
 *
 * It is DECORATION, exactly like AiAvatar before it: it represents no data,
 * carries aria-hidden, and the elements around it own the accessible names.
 * Original inline-SVG geometry — circles, one ellipse highlight, gradient
 * fills; never <img>, never <polyline> (the public-home acceptance suite
 * counts both inside main and expects zero).
 *
 * Colour comes from the AI-teal tokens plus one champagne satellite, so the
 * orb keeps following the palette instead of pinning literals. The only
 * motion is the two counter-rotating orbit groups — transform-only,
 * 26s/40s periods far below anything that competes with reading — and the
 * global prefers-reduced-motion clamp in app.css stops both.
 *
 * Per-instance gradient ids via useId(): SVG ids are document-global, and a
 * second orb (or any future reuse) must not silently resolve against the
 * first one's <defs>.
 */
const uid = useId();

const glowId = computed(() => `${uid}-glow`);
const coreId = computed(() => `${uid}-core`);
const rimId = computed(() => `${uid}-rim`);
</script>

<template>
    <svg viewBox="0 0 160 160" fill="none" aria-hidden="true" focusable="false">
        <defs>
            <radialGradient :id="glowId" cx="50%" cy="50%" r="50%">
                <stop offset="0%" stop-color="rgb(var(--mh-ai))" stop-opacity="0.26" />
                <stop offset="65%" stop-color="rgb(var(--mh-ai))" stop-opacity="0.08" />
                <stop offset="100%" stop-color="rgb(var(--mh-ai))" stop-opacity="0" />
            </radialGradient>
            <radialGradient :id="coreId" cx="38%" cy="30%" r="80%">
                <stop offset="0%" stop-color="#9BE8DF" />
                <stop offset="45%" stop-color="rgb(var(--mh-ai))" />
                <stop offset="100%" stop-color="rgb(var(--mh-ai-ink))" />
            </radialGradient>
            <linearGradient :id="rimId" x1="0" y1="0" x2="1" y2="1">
                <stop offset="0%" stop-color="rgb(var(--mh-ai))" stop-opacity="0.85" />
                <stop offset="100%" stop-color="rgb(var(--mh-ai))" stop-opacity="0.1" />
            </linearGradient>
        </defs>

        <!-- Soft halo grounding the orb on the ivory page. -->
        <circle cx="80" cy="80" r="78" :fill="`url(#${glowId})`" />

        <!-- The sphere and its specular highlight. -->
        <circle cx="80" cy="80" r="42" :fill="`url(#${coreId})`" />
        <ellipse cx="66" cy="63" rx="14" ry="9" fill="#FFFFFF" fill-opacity="0.28" />

        <!-- Inner orbit: dashed teal ring with a champagne satellite. -->
        <g class="mh-orb-spin">
            <circle cx="80" cy="80" r="57" :stroke="`url(#${rimId})`" stroke-width="1.5" stroke-dasharray="6 10" />
            <circle cx="137" cy="80" r="3.5" fill="rgb(var(--mh-accent))" />
        </g>

        <!-- Outer orbit: fainter, counter-rotating, teal satellite. -->
        <g class="mh-orb-spin-reverse">
            <circle cx="80" cy="80" r="68" stroke="rgb(var(--mh-ai))" stroke-opacity="0.3" stroke-width="1" stroke-dasharray="2 12" />
            <circle cx="80" cy="12" r="2.5" fill="rgb(var(--mh-ai))" />
        </g>
    </svg>
</template>

<style scoped>
/* Transform-only rotation; the global reduced-motion rule clamps both. */
.mh-orb-spin,
.mh-orb-spin-reverse {
    transform-origin: 80px 80px;
}

.mh-orb-spin {
    animation: mh-orb-rotate 26s linear infinite;
}

.mh-orb-spin-reverse {
    animation: mh-orb-rotate 40s linear infinite reverse;
}

@keyframes mh-orb-rotate {
    to {
        transform: rotate(360deg);
    }
}
</style>
