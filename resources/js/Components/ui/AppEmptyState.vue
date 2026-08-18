<script setup lang="ts">
defineProps<{ title: string; description?: string }>();
</script>

<template>
    <div class="flex flex-col items-center justify-center px-6 py-14 text-center">
        <div class="mb-4 flex h-12 w-12 items-center justify-center rounded-panel bg-surface-sunken text-ink-faint" aria-hidden="true">
            <svg viewBox="0 0 24 24" fill="none" class="h-6 w-6" stroke="currentColor" stroke-width="1.5">
                <path d="M3 7h18M3 12h18M3 17h10" stroke-linecap="round" />
            </svg>
        </div>
        <h3 class="font-display text-base font-semibold text-ink">{{ title }}</h3>
        <p v-if="description" class="mt-1 max-w-sm text-sm text-ink-muted">{{ description }}</p>
        <div v-if="$slots.action" class="mt-5"><slot name="action" /></div>
    </div>
</template>
