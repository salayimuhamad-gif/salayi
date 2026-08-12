<script setup lang="ts">
import { useLocale } from '@/Composables/useLocale';

/*
 * The real language switcher, extracted so the top bar and the mobile drawer
 * cannot drift.
 *
 * The switching MECHANISM is untouched — `switchTo` still decides between a
 * navigation and a session POST depending on whether the route is locale-
 * prefixed, which is the distinction useLocale exists to make. Only the
 * presentation moved.
 *
 * `aria-current` rather than colour alone marks the active language, and each
 * control keeps its native name as its label: a two-letter code is not a
 * language name to a reader who does not already know the platform.
 */
withDefaults(defineProps<{ block?: boolean }>(), { block: false });

const { current, available, switchTo } = useLocale();
</script>

<template>
    <div
        class="inline-flex items-center gap-0.5 rounded-card border border-line p-0.5"
        :class="block ? 'w-full' : ''"
    >
        <button
            v-for="locale in available"
            :key="locale.code"
            type="button"
            class="flex min-h-11 min-w-11 items-center justify-center rounded-[0.6rem] px-2.5 text-xs font-medium
                   transition-colors duration-200 ease-calm
                   focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-accent
                   lg:min-h-9 lg:min-w-0"
            :class="[
                locale.code === current
                    ? 'bg-accent text-ink'
                    : 'text-ink-muted hover:bg-surface-sunken hover:text-ink',
                block ? 'flex-1' : '',
            ]"
            :aria-current="locale.code === current ? 'true' : undefined"
            @click="switchTo(locale.code)"
        >
            {{ locale.native }}
        </button>
    </div>
</template>
