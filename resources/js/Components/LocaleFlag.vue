<script setup lang="ts">
import type { LocaleCode } from '@/Types/inertia';

/*
 * The language menu's flag glyphs (redesign Wave 1 §2): the Kurdistan Region
 * for Sorani, Iraq for Arabic, the United Kingdom for English.
 *
 * Inline SVG, local, decorative. Every flag is `aria-hidden` because the
 * NATIVE LANGUAGE NAME beside it carries the meaning — a flag is a visual
 * anchor, not a label, and two of these languages are spoken far beyond the
 * flag drawn here. Inline rather than an <img> for the same reasons the icon
 * set gives: no extra request on Erbil mobile data, and the hermetic
 * acceptance suite stubs every off-origin fetch anyway.
 *
 * Drawn at 3:2 on a 30×20 grid, simplified to what survives 16 pixels: the
 * Iraqi takbir is suggested as a calligraphic stroke rather than lettered
 * (illegible type is worse than an honest abstraction), and the Kurdish sun
 * carries eight rays instead of twenty-one. A hairline inset keeps the white
 * stripes from dissolving into the ivory surface.
 */
defineProps<{ code: LocaleCode }>();
</script>

<template>
    <!-- Kurdistan Region: red, white, green; golden sun. -->
    <svg
        v-if="code === 'ckb'"
        viewBox="0 0 30 20"
        class="overflow-hidden rounded-[3px]"
        aria-hidden="true"
        focusable="false"
    >
        <rect width="30" height="7" fill="#ee2a35" />
        <rect y="7" width="30" height="6" fill="#ffffff" />
        <rect y="13" width="30" height="7" fill="#278e43" />
        <g stroke="#febd11" stroke-width="1.3" stroke-linecap="round">
            <path d="M15 5.4v1.4M15 13.2v1.4M10.4 10h1.4M18.2 10h1.4" />
            <path d="M11.7 6.7l1 1M17.3 12.3l1 1M18.3 6.7l-1 1M12.7 12.3l-1 1" />
        </g>
        <circle cx="15" cy="10" r="2.5" fill="#febd11" />
        <rect x="0.5" y="0.5" width="29" height="19" rx="2.5" fill="none" class="stroke-ink/15" stroke-width="1" />
    </svg>

    <!-- Iraq: red, white, black; the takbir suggested in green. -->
    <svg
        v-else-if="code === 'ar'"
        viewBox="0 0 30 20"
        class="overflow-hidden rounded-[3px]"
        aria-hidden="true"
        focusable="false"
    >
        <rect width="30" height="7" fill="#ce1126" />
        <rect y="7" width="30" height="6" fill="#ffffff" />
        <rect y="13" width="30" height="7" fill="#000000" />
        <g stroke="#007a3d" stroke-width="1.1" fill="none" stroke-linecap="round">
            <path d="M9.6 11.4c1 .6 2 .6 2.8-.2M13.6 8.8v2.8" />
            <path d="M16 11.2c.8.6 1.8.5 2.4-.2M19.6 8.8v2.8" />
        </g>
        <rect x="0.5" y="0.5" width="29" height="19" rx="2.5" fill="none" class="stroke-ink/15" stroke-width="1" />
    </svg>

    <!-- United Kingdom: the union flag, simplified to its construction lines. -->
    <svg
        v-else
        viewBox="0 0 30 20"
        class="overflow-hidden rounded-[3px]"
        aria-hidden="true"
        focusable="false"
    >
        <rect width="30" height="20" fill="#012169" />
        <path d="M0 0l30 20M30 0L0 20" stroke="#ffffff" stroke-width="4" />
        <path d="M0 0l30 20M30 0L0 20" stroke="#c8102e" stroke-width="1.6" />
        <path d="M15 0v20M0 10h30" stroke="#ffffff" stroke-width="6.6" />
        <path d="M15 0v20M0 10h30" stroke="#c8102e" stroke-width="4" />
        <rect x="0.5" y="0.5" width="29" height="19" rx="2.5" fill="none" class="stroke-ink/15" stroke-width="1" />
    </svg>
</template>
