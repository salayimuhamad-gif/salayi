<script setup lang="ts">
import { computed } from 'vue';
import { usePage } from '@inertiajs/vue3';
import LanguageMenu from '@/Components/LanguageMenu.vue';
import type { SharedPageProps } from '@/Types/inertia';

defineProps<{ title: string; subtitle?: string }>();

const page = usePage<SharedPageProps>();
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

                <!-- Wave 1: the shared language menu replaces this layout's own
                     inline copy of the switcher, so the three surfaces cannot
                     drift. Same `switchTo` underneath, so the session-POST
                     behaviour of these non-localized routes is unchanged. -->
                <div class="mt-10 flex items-center justify-center border-t border-line pt-6">
                    <LanguageMenu />
                </div>
            </div>
        </div>
    </div>
</template>
