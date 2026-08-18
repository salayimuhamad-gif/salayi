<script setup lang="ts">
import { computed, ref } from 'vue';
import { useAnimatedCounter } from '@/Composables/useAnimatedCounter';

/*
 * rAF counter wrapper (redesign §13.2). Renders the FINAL value instantly
 * under reduced motion, before intersection, and for no-JS — the tween is
 * presentation, never information.
 */
const props = withDefaults(defineProps<{
    value: number;
    duration?: number;
    format?: (n: number) => string;
}>(), { duration: 900, format: undefined });

const el = ref<HTMLElement | null>(null);
const valueRef = computed(() => props.value);

const { display } = useAnimatedCounter(el, valueRef, {
    duration: props.duration,
    ...(props.format ? { format: props.format } : {}),
});
</script>

<template>
    <span ref="el">{{ display }}</span>
</template>
