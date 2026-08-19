<script setup lang="ts">
import { computed } from 'vue';

/*
 * `message` exists because ten call sites across the Map Explorer, the Wizard,
 * the Places index and the acting-company switcher were already passing it —
 * and the component only rendered a <slot>, so every one of them produced a
 * coloured box with an icon and NO TEXT. A visitor saw an alert that said
 * nothing.
 *
 * Adding the prop fixes all of them at once and leaves slot usage working:
 * the slot wins when it has content, so a caller can still pass markup.
 */
const props = withDefaults(defineProps<{
    variant?: 'info' | 'success' | 'warning' | 'danger';
    message?: string | null;
}>(), { variant: 'info', message: null });

const tone = computed(() => ({
    info: 'border-brand/25 bg-brand/5 text-ink',
    success: 'border-positive/30 bg-positive/5 text-ink',
    warning: 'border-caution/30 bg-caution/5 text-ink',
    danger: 'border-negative/30 bg-negative/5 text-ink',
}[props.variant]));

// assertive for danger so a screen reader interrupts; polite otherwise.
const live = computed(() => (props.variant === 'danger' ? 'assertive' : 'polite'));
</script>

<template>
    <div :class="['flex items-start gap-3 rounded-card border p-4 text-sm', tone]" role="status" :aria-live="live">
        <span class="mt-0.5 select-none" aria-hidden="true">
            {{ { info: 'i', success: '✓', warning: '!', danger: '×' }[variant] }}
        </span>
        <div class="min-w-0 flex-1">
            <slot>{{ message }}</slot>
        </div>
    </div>
</template>
