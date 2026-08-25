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

/*
 * Component classes rather than inline utility pairs (glass-UI refinement):
 * the :root values in app.css are byte-identical to the former utilities,
 * so admin and auth alerts render unchanged, while the public palettes can
 * raise the tints where a 5% fill vanishes on night glass (public.css).
 */
const tone = computed(() => ({
    info: 'mh-alert-info text-ink',
    success: 'mh-alert-success text-ink',
    warning: 'mh-alert-warning text-ink',
    danger: 'mh-alert-danger text-ink',
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
