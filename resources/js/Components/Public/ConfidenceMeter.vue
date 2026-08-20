<script setup lang="ts">
import { computed } from 'vue';

/*
 * Thin confidence gauge (redesign §13.3). `role="meter"` with real ARIA
 * values; the adjacent label carries the meaning — the fill is never the
 * only signal. Renders ONLY from a real backend-supplied value; a section
 * with no confidence figure simply does not mount this.
 */
const props = withDefaults(defineProps<{
    value: number;
    label?: string;
}>(), { label: undefined });

const clamped = computed(() => Math.max(0, Math.min(100, props.value)));
</script>

<template>
    <div class="flex items-center gap-2">
        <div
            role="meter"
            :aria-valuenow="clamped"
            aria-valuemin="0"
            aria-valuemax="100"
            :aria-label="label"
            class="h-1.5 w-full max-w-[8rem] overflow-hidden rounded-pill bg-ai-soft"
        >
            <div class="h-full rounded-pill bg-ai" :style="{ inlineSize: `${clamped}%` }" />
        </div>
        <span v-if="label" class="text-xs text-ai-ink">{{ label }}</span>
    </div>
</template>
