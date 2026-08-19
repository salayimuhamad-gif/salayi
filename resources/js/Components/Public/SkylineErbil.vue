<script setup lang="ts">
/*
 * The skyline silhouette as an INLINE SVG component (same geometry as
 * resources/js/Assets/erbil-skyline.svg).
 *
 * Inline rather than <img> for two binding reasons: the browser acceptance
 * suite asserts the hermetic homepage renders zero `main img` elements (the
 * data-honesty heuristic against invented photography), and currentColor
 * only resolves for inline SVG — the silhouette takes the ink of whatever
 * band it sits in (charcoal footer, ivory hero) from one source of truth.
 * Decorative only: aria-hidden, no title, colour via `class` at the call
 * site (e.g. `text-ink opacity-[.07]`).
 */
withDefaults(defineProps<{ flip?: boolean }>(), { flip: false });
</script>

<template>
    <svg
        viewBox="0 0 1440 240"
        fill="none"
        aria-hidden="true"
        focusable="false"
        preserveAspectRatio="xMidYMax slice"
        :class="{ '-scale-y-100': flip }"
    >
        <path
            fill="currentColor"
            d="M0 240V200h36v-26h10v26h22v-44h12v-14h10v14h10v44h30v-70h14v70h24v-32h34v32h26V148h12v-10h14v10h12v52h30v-84h16v-12h12v12h16v84h28v-38h40v38h22v-58h12v58h26v-96h18V92h14v12h18v96h36v-30h30v30h22 v-24h394 l8-14 12-9 16-6 44-24 16-6 12-9 8 14v0h8 l6-12 10-8 14-5 40-22 14-5 10-8 6 12h6v88h24v-30h30v30h36V104h18V92h14v12h18v96h26v-58h12v58h22v-38h40v38h28v-84h16v-12h12v12h16v84h30v-52h12v-10h14v10h12v62h26v-32h34v32h24v-70h14v70h30v-44h10v-14h10v14h12v44h22v-26h10v26h36v40H0Z"
        />
        <path fill="currentColor" fill-opacity=".5" d="M520 216h400v6H520z" />
    </svg>
</template>
