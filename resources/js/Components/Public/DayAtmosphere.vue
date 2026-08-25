<script setup lang="ts">
/*
 * The daylight atmosphere — the light-theme counterpart of
 * MidnightAtmosphere, deliberately built from the SAME composition grammar
 * so switching themes reads as the same place at a different hour, never as
 * a different product: soft sky fields over the block-start half, three
 * large blurred blobs (pale petrol, champagne, warm amber), a warm horizon
 * and the identical Erbil skyline silhouette (same paths) rendered as a
 * faint morning haze with its amber windows kept.
 *
 * Everything is fixed, viewport-anchored, aria-hidden and
 * pointer-events-none: decoration behind z-1 content. The tints stay very
 * quiet on purpose — the day palette's glass panes are lighter than
 * midnight's, so a saturated backdrop here would eat the text contrast the
 * refinement exists to protect. Colours are fixed design constants of the
 * approved direction, like the midnight family — not DB palette. Nothing
 * animates, and the blobs are blurred once by the compositor.
 */
</script>

<template>
    <div class="mh-atmos-day" aria-hidden="true">
        <div class="mh-atmos-day-fields" />
        <span class="mh-atmos-day-blob mh-atmos-day-blob-a" />
        <span class="mh-atmos-day-blob mh-atmos-day-blob-b" />
        <span class="mh-atmos-day-blob mh-atmos-day-blob-c" />
        <div class="mh-atmos-day-horizon" />
        <svg class="mh-atmos-day-skyline" viewBox="0 0 400 120" preserveAspectRatio="none" focusable="false">
            <path
                fill="#9aa7bb"
                opacity=".5"
                d="M0,120 V72 h22 V54 h12 v18 h18 V64 h14 v22 h24 V48 h12 v12 h10 V38 h8 v22 h12 v22 h18 q4,-38 32,-42 q28,4 32,42 h18 V56 h10 v16 h8 V44 h8 v28 h14 v14 h18 V52 h12 v16 h10 v18 h16 V60 h14 v60 Z"
            />
            <path
                fill="#5d6b82"
                opacity=".55"
                d="M0,120 V86 h16 V68 h10 v18 h12 V74 h14 v20 h20 V58 h10 v10 h8 V50 h6 v18 h10 v22 h18 q3,-32 28,-36 q25,4 28,36 h16 V68 h9 v14 h8 V60 h6 v22 h12 v10 h16 V66 h10 v12 h8 v14 h14 V74 h12 v46 Z"
            />
            <g fill="#b9791e" opacity=".8">
                <rect x="34" y="80" width="2" height="2" />
                <rect x="92" y="68" width="2" height="2" />
                <rect x="200" y="62" width="2" height="2" />
                <rect x="286" y="78" width="2" height="2" />
                <rect x="352" y="84" width="2" height="2" />
            </g>
        </svg>
    </div>
</template>

<style scoped>
.mh-atmos-day {
    position: fixed;
    inset: 0;
    z-index: 0;
    pointer-events: none;
    overflow: hidden;
}

/* Physical percentages inside gradient geometry — direction-neutral
   decoration, the same documented exception the midnight fields use. */
.mh-atmos-day-fields {
    position: absolute;
    inset: 0;
    background:
        radial-gradient(90% 55% at 18% 0%, #e3ecf2 0%, transparent 60%),
        radial-gradient(75% 45% at 92% 8%, #f2e9d6 0%, transparent 55%),
        radial-gradient(130% 55% at 50% 108%, #f0ebe0 0%, transparent 62%);
}

.mh-atmos-day-blob {
    position: absolute;
    border-radius: 50%;
    filter: blur(80px);
}

.mh-atmos-day-blob-a {
    inline-size: 460px;
    block-size: 460px;
    inset-inline-start: -160px;
    inset-block-start: -160px;
    background: #c9dae6;
    opacity: 0.5;
}

.mh-atmos-day-blob-b {
    inline-size: 380px;
    block-size: 380px;
    inset-inline-end: -140px;
    inset-block-start: 180px;
    background: #ecd9ae;
    opacity: 0.45;
}

.mh-atmos-day-blob-c {
    inline-size: 520px;
    block-size: 300px;
    inset-inline-start: calc(50% - 260px);
    inset-block-end: -140px;
    background: #ecc990;
    opacity: 0.35;
}

.mh-atmos-day-horizon {
    position: absolute;
    inset-inline: 0;
    inset-block-end: 0;
    block-size: 280px;
    background: linear-gradient(180deg, transparent, rgb(214 138 52 / 0.1) 60%, rgb(214 138 52 / 0.03));
}

.mh-atmos-day-skyline {
    position: absolute;
    inset-inline: 0;
    inset-block-end: -4px;
    inline-size: 100%;
    block-size: 180px;
    filter: blur(2px);
    opacity: 0.4;
}
</style>
