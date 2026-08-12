<script setup lang="ts">
import { computed } from 'vue';
import { Link } from '@inertiajs/vue3';

/*
 * ADDITIVE extension only (redesign §13.4): the original variant/size/type/
 * disabled/loading/block API — names, types, defaults — is untouched, so
 * every Admin/Auth/Account caller compiles and renders exactly as before.
 * New, all optional: `accent` and `ai` variants, the `lg` size, and `href`,
 * which renders an Inertia Link styled identically (callers pass an already
 * locale-resolved URL; this component resolves nothing).
 */
const props = withDefaults(defineProps<{
    variant?: 'primary' | 'secondary' | 'ghost' | 'danger' | 'accent' | 'ai';
    size?: 'sm' | 'md' | 'lg';
    type?: 'button' | 'submit';
    disabled?: boolean;
    loading?: boolean;
    block?: boolean;
    href?: string;
}>(), { variant: 'primary', size: 'md', type: 'button', disabled: false, loading: false, block: false, href: undefined });

/*
 * Focus rings use the champagne accent rather than the brand petrol: against
 * a deep petrol button the petrol ring is invisible, and a focus indicator
 * that disappears on the primary action is an accessibility failure that only
 * shows up under keyboard use.
 */
const classes = computed(() => [
    'inline-flex items-center justify-center gap-2 rounded-card font-medium select-none',
    'transition-[background-color,color,box-shadow,transform] duration-200 ease-calm',
    'focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-accent focus-visible:ring-offset-2',
    'focus-visible:ring-offset-surface disabled:cursor-not-allowed disabled:opacity-50',
    {
        sm: 'min-h-9 px-3 py-1.5 text-sm',
        md: 'min-h-11 px-4 py-2.5 text-sm',
        lg: 'min-h-12 px-6 py-3 text-base',
    }[props.size],
    props.block ? 'w-full' : '',
    {
        primary: 'bg-brand text-white shadow-card hover:bg-brand-strong active:scale-[.98]',
        secondary: 'border border-line-strong bg-surface-raised text-ink hover:bg-surface-sunken active:scale-[.98]',
        ghost: 'text-ink-muted hover:bg-surface-sunken hover:text-ink',
        danger: 'bg-negative text-white hover:opacity-90 active:scale-[.98]',
        /*
         * Champagne CTA — rare, premium. Deep ink on champagne is 5.9:1;
         * white on champagne would be 3.0:1 and is forbidden (§11.1).
         */
        accent: 'bg-accent text-ink shadow-card hover:brightness-105 active:scale-[.98]',
        /* The AI voice: white on teal is 5.5:1 (AA). */
        ai: 'bg-ai text-white shadow-card hover:bg-ai/90 active:scale-[.98]',
    }[props.variant],
]);
</script>

<template>
    <Link v-if="href && !disabled && !loading" :href="href" :class="classes">
        <slot />
    </Link>
    <button v-else :type="type" :class="classes" :disabled="disabled || loading" :aria-busy="loading">
        <svg v-if="loading" class="h-4 w-4 animate-spin" viewBox="0 0 24 24" fill="none" aria-hidden="true">
            <circle cx="12" cy="12" r="10" stroke="currentColor" stroke-width="3" class="opacity-25" />
            <path d="M12 2a10 10 0 0 1 10 10" stroke="currentColor" stroke-width="3" stroke-linecap="round" />
        </svg>
        <slot />
    </button>
</template>
