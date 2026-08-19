<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import AppIcon from '@/Components/Icons/AppIcon.vue';
import { t } from '@/lib/i18n';

/*
 * The homepage's Investment Map preview (§4 of the map brief).
 *
 * A PREVIEW, not a map: no map library, no tiles, no fetch — pure CSS over
 * the existing decorative-field classes, so it costs the homepage nothing.
 * The grid ghost and pulsing gold markers say "map" honestly without
 * pretending to be data; the CTA opens the real experience, which is where
 * the heavy chunk loads. Marker pulses are motion-safe only.
 */
defineProps<{ href: string }>();
</script>

<template>
    <section :aria-label="t('home.invest_map.title')">
        <Link
            :href="href"
            class="mh-lux-panel mh-lux-gilded mh-lux-card-interactive relative block overflow-hidden focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-accent"
        >
            <div class="mh-lux-grid-field mh-lux-field relative min-h-[220px] p-6 sm:min-h-[260px] sm:p-8">
                <!-- Decorative marker constellation. -->
                <div aria-hidden="true">
                    <span class="mh-invest-teaser-dot" style="inset-inline-start: 22%; top: 30%" />
                    <span class="mh-invest-teaser-dot" style="inset-inline-start: 58%; top: 52%; animation-delay: 0.9s" />
                    <span class="mh-invest-teaser-dot" style="inset-inline-start: 74%; top: 26%; animation-delay: 1.7s" />
                </div>

                <div class="relative max-w-xl">
                    <p class="mh-lux-eyebrow mb-2">{{ t('nav.public.invest') }}</p>
                    <h2 class="font-display text-xl font-semibold text-ink sm:text-2xl">
                        {{ t('home.invest_map.title') }}
                    </h2>
                    <p class="mt-2 text-sm text-ink-muted">{{ t('home.invest_map.sub') }}</p>

                    <span class="mh-lux-btn mh-lux-btn-primary mt-5 inline-flex text-sm">
                        {{ t('home.invest_map.cta') }}
                        <AppIcon name="arrow-end" class="h-4 w-4 rtl:-scale-x-100" />
                    </span>
                </div>
            </div>
        </Link>
    </section>
</template>
