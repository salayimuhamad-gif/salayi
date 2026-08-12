<script setup lang="ts">
/*
 * Tokenized glass container — for MAP OVERLAYS ONLY, where live tiles moving
 * beneath earn the blur. It is the componentized `.mh-invest-glass` voice;
 * the rest of the site stays on flat surfaces (blur over a flat background
 * is blur for blur's sake).
 */
withDefaults(defineProps<{ as?: string }>(), { as: 'div' });
</script>

<template>
    <component :is="as" class="mh-invest-glass rounded-card">
        <slot />
    </component>
</template>
