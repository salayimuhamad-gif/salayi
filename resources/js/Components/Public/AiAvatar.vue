<script setup lang="ts">
import { computed, useId } from 'vue';

/*
 * The advisor's face (§10.2).
 *
 * Original geometry: a rounded silver shell, a dark visor, two soft blue eye
 * lights and an antenna. Nothing here is traced from the reference image and it
 * imitates no branded character — §4.3 and §10.2 both rule that out, and an
 * inline SVG is the only form of it that cannot quietly become a remote asset.
 *
 * It is DECORATION. It represents no data, so it carries aria-hidden and the
 * elements around it own the accessible names. The only motion is a slow pulse
 * on the eye lights, which `prefers-reduced-motion` already stops through the
 * global rule in app.css — §11 forbids a looping movement that competes with
 * reading, and a blink at this amplitude does not.
 *
 * Colours come from the scoped tokens rather than literals, so the face follows
 * the operator's accent instead of pinning a second palette into the page.
 */
withDefaults(defineProps<{ glow?: boolean }>(), { glow: true });

/*
 * PER-INSTANCE GRADIENT IDS.
 *
 * The three <defs> ids were fixed strings. An SVG id is document-global, so the
 * moment two avatars rendered — which the advisor transcript does on EVERY
 * assistant turn, plus the page header, plus the homepage hero — the document
 * carried duplicate ids and every `url(#…)` in the second avatar onwards
 * resolved against the FIRST one's definitions. Invalid HTML, and a real
 * rendering hazard the day the two instances stop being identical.
 *
 * `useId()` is Vue 3.5's SSR-stable generator: the same value is produced on
 * the server and on the client for the same component instance, so it does not
 * re-randomise between render and hydration the way `Math.random()` or a module
 * counter would. That stability is the whole point — an id that changes at
 * hydration breaks the reference it is there to carry.
 */
const uid = useId();

const shellId = computed(() => `${uid}-shell`);
const visorId = computed(() => `${uid}-visor`);
const haloId = computed(() => `${uid}-halo`);
</script>

<template>
    <svg viewBox="0 0 120 120" fill="none" aria-hidden="true" focusable="false">
        <defs>
            <linearGradient :id="shellId" x1="0" y1="0" x2="0" y2="1">
                <stop offset="0%" stop-color="#F4F7FA" />
                <stop offset="100%" stop-color="#C3CEDA" />
            </linearGradient>
            <linearGradient :id="visorId" x1="0" y1="0" x2="1" y2="1">
                <stop offset="0%" stop-color="#12283C" />
                <stop offset="100%" stop-color="#050F1B" />
            </linearGradient>
            <radialGradient :id="haloId" cx="50%" cy="50%" r="50%">
                <stop offset="0%" stop-color="rgb(var(--mh-accent))" stop-opacity="0.28" />
                <stop offset="100%" stop-color="rgb(var(--mh-accent))" stop-opacity="0" />
            </radialGradient>
        </defs>

        <circle v-if="glow" cx="60" cy="62" r="52" :fill="`url(#${haloId})`" />

        <!-- Antenna -->
        <path d="M60 16v10" stroke="#C3CEDA" stroke-width="3" stroke-linecap="round" />
        <circle cx="60" cy="13" r="4.5" fill="rgb(var(--mh-accent))" />

        <!-- Shoulders, so the head is not floating -->
        <path
            d="M30 106c0-11 13.4-19 30-19s30 8 30 19"
            :fill="`url(#${shellId})`"
            fill-opacity="0.55"
        />

        <!-- Ear pods -->
        <rect x="18" y="52" width="9" height="20" rx="4.5" fill="#C3CEDA" />
        <rect x="93" y="52" width="9" height="20" rx="4.5" fill="#C3CEDA" />

        <!-- Shell -->
        <rect x="26" y="28" width="68" height="62" rx="22" :fill="`url(#${shellId})`" />

        <!-- Visor -->
        <rect x="35" y="40" width="50" height="34" rx="15" :fill="`url(#${visorId})`" />

        <!-- Eye lights -->
        <g fill="#7FC4F5">
            <ellipse cx="50" cy="57" rx="5.4" ry="6" class="mh-ai-eye" />
            <ellipse cx="70" cy="57" rx="5.4" ry="6" class="mh-ai-eye" />
        </g>

        <!-- Mouth line: a single restrained stroke rather than a face -->
        <path d="M52 82h16" stroke="#8FA0B2" stroke-width="3" stroke-linecap="round" />
    </svg>
</template>

<style scoped>
.mh-ai-eye {
    animation: mh-ai-blink 5.5s cubic-bezier(0.22, 1, 0.36, 1) infinite;
}

@keyframes mh-ai-blink {
    0%,
    88%,
    100% {
        opacity: 1;
    }
    93% {
        opacity: 0.45;
    }
}
</style>
