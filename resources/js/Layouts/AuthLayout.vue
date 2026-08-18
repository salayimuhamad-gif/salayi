<script setup lang="ts">
import { computed } from 'vue';
import { usePage } from '@inertiajs/vue3';
import { useLocale } from '@/Composables/useLocale';
import type { SharedPageProps } from '@/Types/inertia';

defineProps<{ title: string; subtitle?: string }>();

const page = usePage<SharedPageProps>();
const { current, available, switchTo } = useLocale();
const siteName = computed(() => page.props.branding['branding.site_name'] ?? page.props.app.name);
</script>

<template>
    <div class="flex min-h-screen flex-col bg-surface">
        <!--
          A single centred column rather than the split-screen hero that every
          admin template ships with. There is no marketing to do here: the only
          people who reach this page already work for the company, and a
          half-screen photograph would push the form below the fold on the
          phones most of them use.
        -->
        <div class="flex flex-1 items-center justify-center px-4 py-12">
            <div class="w-full max-w-sm">
                <div class="mb-8 flex items-center gap-3">
                    <span class="h-8 w-1.5 rounded-full bg-accent" aria-hidden="true" />
                    <span class="font-display text-lg font-bold text-brand">{{ siteName }}</span>
                </div>

                <h1 class="font-display text-xl font-semibold text-ink">{{ title }}</h1>
                <p v-if="subtitle" class="mt-1.5 text-sm text-ink-muted">{{ subtitle }}</p>

                <div class="mt-7">
                    <slot />
                </div>

                <div class="mt-10 flex items-center justify-center gap-1 border-t border-line pt-6">
                    <button
                        v-for="locale in available"
                        :key="locale.code"
                        type="button"
                        class="inline-flex min-h-11 items-center rounded px-2.5 text-xs transition-colors focus-visible:outline-none
                               focus-visible:ring-2 focus-visible:ring-accent"
                        :class="locale.code === current ? 'bg-brand text-white' : 'text-ink-muted hover:bg-surface-sunken'"
                        @click="switchTo(locale.code)"
                    >
                        {{ locale.native }}
                    </button>
                </div>
            </div>
        </div>
    </div>
</template>
