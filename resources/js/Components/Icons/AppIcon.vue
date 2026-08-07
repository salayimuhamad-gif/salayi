<script setup lang="ts">
import { computed } from 'vue';
import { useLocale } from '@/Composables/useLocale';
import { iconPaths, type IconName } from '@/Components/Icons/icons';

/*
 * One <path> on a 24-unit grid. The set and the reason for keeping it local
 * live in ./icons.ts.
 *
 * DIRECTION. `mirror` marks the glyphs that point somewhere. An arrow meaning
 * "onward" must aim left in Sorani and Arabic, and a chevron that opens a panel
 * must follow it; a glyph meaning "up" or "down" must NOT be touched, or a
 * rising index reads as a falling one. Mirroring is therefore opt-in per use
 * rather than applied to the whole set, and it keys off the real `direction`
 * shared prop rather than a CSS guess.
 */
const props = withDefaults(
    defineProps<{
        name: IconName;
        mirror?: boolean;
    }>(),
    { mirror: false },
);

const { direction } = useLocale();

const flipped = computed(() => props.mirror && direction.value === 'rtl');
</script>

<template>
    <svg
        viewBox="0 0 24 24"
        fill="none"
        stroke="currentColor"
        stroke-width="1.6"
        stroke-linecap="round"
        stroke-linejoin="round"
        aria-hidden="true"
        focusable="false"
        :class="flipped ? '-scale-x-100' : ''"
    >
        <path :d="iconPaths[name]" />
    </svg>
</template>
