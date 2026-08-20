<script setup lang="ts">
/*
 * `compact` (Wave 2B polish) is additive: the default rendering is
 * byte-identical for every existing call site. The compact form carries the
 * SAME honest title/description — only smaller and tighter — for surfaces
 * (the homepage strips) where a no-data notice must not become the page's
 * dominant panel.
 */
withDefaults(defineProps<{ title: string; description?: string; compact?: boolean }>(), { compact: false });
</script>

<template>
    <div
        class="flex flex-col items-center justify-center text-center"
        :class="compact ? 'px-5 py-6' : 'px-6 py-14'"
    >
        <div
            class="flex items-center justify-center rounded-panel bg-surface-sunken text-ink-faint"
            :class="compact ? 'mb-2.5 h-9 w-9' : 'mb-4 h-12 w-12'"
            aria-hidden="true"
        >
            <svg viewBox="0 0 24 24" fill="none" :class="compact ? 'h-4 w-4' : 'h-6 w-6'" stroke="currentColor" stroke-width="1.5">
                <path d="M3 7h18M3 12h18M3 17h10" stroke-linecap="round" />
            </svg>
        </div>
        <h3 class="font-display font-semibold text-ink" :class="compact ? 'text-sm' : 'text-base'">{{ title }}</h3>
        <p v-if="description" class="mt-1 max-w-sm text-ink-muted" :class="compact ? 'text-xs' : 'text-sm'">{{ description }}</p>
        <div v-if="$slots.action" :class="compact ? 'mt-3' : 'mt-5'"><slot name="action" /></div>
    </div>
</template>
