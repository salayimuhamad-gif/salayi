<script setup lang="ts">
/*
 * One avatar, two sources (spec §4.1–§4.2): the processed profile photo when
 * one exists, otherwise generated initials on a deterministic tint. The
 * fallback is a real render, not a broken-image glyph — an account without a
 * photo still looks like a person.
 *
 * The `alt` is the person's name for photos and empty (decorative) for the
 * initials fallback, whose letters are already visible text.
 */
const props = withDefaults(defineProps<{
    photo?: string | null;
    initials: string;
    name?: string;
    size?: 'sm' | 'md' | 'lg';
}>(), { photo: null, name: '', size: 'md' });

const sizeClass = { sm: 'h-9 w-9 text-xs', md: 'h-12 w-12 text-sm', lg: 'h-24 w-24 text-2xl' }[props.size];

/*
 * A stable tint from the initials so the same person is the same colour on
 * every visit — hue from a tiny hash, fixed saturation/lightness pairs that
 * keep contrast against white text in both themes.
 */
let hash = 0;
for (const ch of props.initials) hash = (hash * 31 + ch.codePointAt(0)!) % 360;
const tint = `hsl(${hash}, 45%, 38%)`;
</script>

<template>
    <img
        v-if="photo"
        :src="photo"
        :alt="name"
        class="shrink-0 rounded-full border border-line object-cover"
        :class="sizeClass"
        loading="lazy"
    >
    <span
        v-else
        class="flex shrink-0 select-none items-center justify-center rounded-full border border-line
               font-semibold text-white"
        :class="sizeClass"
        :style="{ backgroundColor: tint }"
        aria-hidden="true"
    >
        {{ initials }}
    </span>
</template>
