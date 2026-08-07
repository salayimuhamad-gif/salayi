<script setup lang="ts">
import { computed } from 'vue';

const props = withDefaults(defineProps<{
    variant?: 'primary' | 'secondary' | 'ghost' | 'danger';
    size?: 'sm' | 'md';
    type?: 'button' | 'submit';
    disabled?: boolean;
    loading?: boolean;
    block?: boolean;
}>(), { variant: 'primary', size: 'md', type: 'button', disabled: false, loading: false, block: false });

/*
 * Focus rings use the brass accent rather than the brand indigo: against a
 * deep indigo button the indigo ring is invisible, and a focus indicator that
 * disappears on the primary action is an accessibility failure that only shows
 * up under keyboard use.
 */
const classes = computed(() => [
    'inline-flex items-center justify-center gap-2 rounded-card font-medium',
    'transition-[background-color,color,box-shadow] duration-200 ease-calm',
    'focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-accent focus-visible:ring-offset-2',
    'focus-visible:ring-offset-surface disabled:cursor-not-allowed disabled:opacity-50',
    props.size === 'sm' ? 'px-3 py-1.5 text-sm' : 'px-4 py-2.5 text-sm',
    props.block ? 'w-full' : '',
    {
        primary: 'bg-brand text-white shadow-card hover:bg-brand-strong',
        secondary: 'border border-line bg-surface-raised text-ink hover:bg-surface-sunken',
        ghost: 'text-ink-muted hover:bg-surface-sunken hover:text-ink',
        danger: 'bg-negative text-white hover:opacity-90',
    }[props.variant],
]);
</script>

<template>
    <button :type="type" :class="classes" :disabled="disabled || loading" :aria-busy="loading">
        <svg v-if="loading" class="h-4 w-4 animate-spin" viewBox="0 0 24 24" fill="none" aria-hidden="true">
            <circle cx="12" cy="12" r="10" stroke="currentColor" stroke-width="3" class="opacity-25" />
            <path d="M12 2a10 10 0 0 1 10 10" stroke="currentColor" stroke-width="3" stroke-linecap="round" />
        </svg>
        <slot />
    </button>
</template>
