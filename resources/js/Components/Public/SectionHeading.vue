<script setup lang="ts">
/*
 * Standard section heading rhythm: dir-conditional eyebrow (the champagne
 * hairline carries the identity under RTL, where uppercase/tracking do not
 * exist — public.css owns those rules), display title, optional lead, and
 * an `actions` slot on the end side. Presentation only; all copy arrives
 * already translated.
 */
withDefaults(defineProps<{
    eyebrow?: string;
    title: string;
    lead?: string;
    align?: 'start' | 'center';
}>(), { eyebrow: undefined, lead: undefined, align: 'start' });
</script>

<template>
    <div
        class="mb-6 flex flex-wrap items-end justify-between gap-4 md:mb-8"
        :class="align === 'center' ? 'text-center' : 'text-start'"
    >
        <div :class="align === 'center' ? 'mx-auto' : 'min-w-0'">
            <p v-if="eyebrow" class="mh-eyebrow mb-2 flex items-center" :class="align === 'center' ? 'justify-center' : ''">
                <span class="inline-block h-px w-6 bg-accent me-3" aria-hidden="true"></span>
                {{ eyebrow }}
            </p>
            <h2 class="font-display text-2xl font-semibold text-ink md:text-3xl">{{ title }}</h2>
            <p v-if="lead" class="mt-2 max-w-2xl text-sm text-ink-muted md:text-base">{{ lead }}</p>
        </div>
        <div v-if="$slots.actions" class="shrink-0">
            <slot name="actions" />
        </div>
    </div>
</template>
