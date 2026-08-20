<script setup lang="ts">
/*
 * The Midnight Amber atmosphere (Wave 2B) — the colored light the glass
 * panes need behind them. Faithful to the approved reference: deep-blue and
 * violet fields over the block-start half, muted purple depth below, three
 * large blurred blobs (violet, warm umber, amber), a warm horizon and the
 * quiet skyline silhouette with a handful of amber windows.
 *
 * Every layer is fixed, viewport-anchored, aria-hidden and
 * pointer-events-none: pure decoration behind z-1 content. The silhouette is
 * abstract skyline art (inline paths, never <img>, never <polyline>) and
 * carries no geographic or data meaning. Colours are fixed design constants
 * of the approved direction, like the dark-band family — not DB palette.
 * Nothing here animates, so reduced-motion needs no special casing, and the
 * blobs are blurred once by the compositor rather than per-frame.
 */
</script>

<template>
    <div class="mh-atmos" aria-hidden="true">
        <div class="mh-atmos-fields" />
        <span class="mh-atmos-blob mh-atmos-blob-a" />
        <span class="mh-atmos-blob mh-atmos-blob-b" />
        <span class="mh-atmos-blob mh-atmos-blob-c" />
        <div class="mh-atmos-horizon" />
        <svg class="mh-atmos-skyline" viewBox="0 0 400 120" preserveAspectRatio="none" focusable="false">
            <path
                fill="#0c1230"
                opacity=".75"
                d="M0,120 V72 h22 V54 h12 v18 h18 V64 h14 v22 h24 V48 h12 v12 h10 V38 h8 v22 h12 v22 h18 q4,-38 32,-42 q28,4 32,42 h18 V56 h10 v16 h8 V44 h8 v28 h14 v14 h18 V52 h12 v16 h10 v18 h16 V60 h14 v60 Z"
            />
            <path
                fill="#04060f"
                d="M0,120 V86 h16 V68 h10 v18 h12 V74 h14 v20 h20 V58 h10 v10 h8 V50 h6 v18 h10 v22 h18 q3,-32 28,-36 q25,4 28,36 h16 V68 h9 v14 h8 V60 h6 v22 h12 v10 h16 V66 h10 v12 h8 v14 h14 V74 h12 v46 Z"
            />
            <g fill="#f3c56f" opacity=".85">
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
.mh-atmos {
    position: fixed;
    inset: 0;
    z-index: 0;
    pointer-events: none;
    overflow: hidden;
}

/* Physical percentages inside gradient geometry — direction-neutral
   decoration, the same documented exception the contour motif uses. */
.mh-atmos-fields {
    position: absolute;
    inset: 0;
    background:
        radial-gradient(90% 55% at 18% 0%, #151d3a 0%, transparent 60%),
        radial-gradient(75% 45% at 92% 8%, #241a4d 0%, transparent 55%),
        radial-gradient(130% 55% at 50% 108%, #1b1230 0%, transparent 62%);
}

.mh-atmos-blob {
    position: absolute;
    border-radius: 50%;
    filter: blur(80px);
}

.mh-atmos-blob-a {
    inline-size: 460px;
    block-size: 460px;
    inset-inline-start: -160px;
    inset-block-start: -160px;
    background: #3b2f7a;
    opacity: 0.5;
}

.mh-atmos-blob-b {
    inline-size: 380px;
    block-size: 380px;
    inset-inline-end: -140px;
    inset-block-start: 180px;
    background: #7a4a1f;
    opacity: 0.4;
}

.mh-atmos-blob-c {
    inline-size: 520px;
    block-size: 300px;
    inset-inline-start: calc(50% - 260px);
    inset-block-end: -140px;
    background: #b3722c;
    opacity: 0.3;
}

.mh-atmos-horizon {
    position: absolute;
    inset-inline: 0;
    inset-block-end: 0;
    block-size: 280px;
    background: linear-gradient(180deg, transparent, rgb(214 138 52 / 0.16) 60%, rgb(214 138 52 / 0.04));
}

.mh-atmos-skyline {
    position: absolute;
    inset-inline: 0;
    inset-block-end: -4px;
    inline-size: 100%;
    block-size: 180px;
    filter: blur(2px);
    opacity: 0.55;
}
</style>
