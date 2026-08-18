<script setup lang="ts">
withDefaults(defineProps<{ title?: string; description?: string; padded?: boolean }>(), { title: undefined, description: undefined, padded: true });
</script>

<template>
    <section class="mh-card overflow-hidden">
        <header v-if="title" class="border-b border-line px-6 py-4">
            <h2 class="font-display text-base font-semibold text-ink">{{ title }}</h2>
            <p v-if="description" class="mt-1 text-sm text-ink-muted">{{ description }}</p>
        </header>
        <div :class="padded ? 'p-6' : ''">
            <slot />
        </div>
        <footer v-if="$slots.footer" class="border-t border-line bg-surface-sunken px-6 py-3">
            <slot name="footer" />
        </footer>
    </section>
</template>
