<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import AppIcon from '@/Components/Icons/AppIcon.vue';
import { t, formatNumber } from '@/lib/i18n';

/*
 * Areas with published projects (§8.4).
 *
 * Two rules from §8.4 shape this file.
 *
 * First, `linkable`. The list only becomes links when the public Area profile
 * is switched on; otherwise the same real name and count render as plain text.
 * The un-linked form is not a degraded state — it is the correct one when there
 * is no page behind the name.
 *
 * Second, no ranking. HomeController orders by project count and sends no rank,
 * no score and no "best area" judgement, so this component adds none. The
 * arrival order is the server's; a numbered leaderboard drawn over it would be
 * an editorial claim the platform never made.
 *
 * The count is `.numeral` so its digits are not reordered inside a Sorani line.
 */
defineProps<{
    areas: Array<{ slug: string; name: string; type: string | null; project_count: number }>;
    linkable: boolean;
    /** Locale-prefixed detail paths, resolved by the page. */
    hrefs: Record<string, string>;
}>();
</script>

<template>
    <ul class="mh-lux-panel divide-y divide-line">
        <li v-for="area in areas" :key="area.slug">
            <Link
                v-if="linkable"
                :href="hrefs[area.slug]"
                class="flex items-center gap-3 px-5 py-3.5 transition-colors duration-200 ease-calm
                       hover:bg-surface-sunken focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-accent"
            >
                <AppIcon name="areas" class="h-4 w-4 shrink-0 text-ink-faint" />

                <span class="min-w-0 flex-1">
                    <span class="block truncate text-sm text-ink">{{ area.name }}</span>
                    <span v-if="area.type" class="block truncate text-xs text-ink-faint">
                        {{ t(`geography.area_types.${area.type}`) }}
                    </span>
                </span>

                <span class="flex shrink-0 items-baseline gap-1.5">
                    <span class="numeral text-sm font-medium text-ink">
                        {{ formatNumber(area.project_count) }}
                    </span>
                    <span class="text-xs text-ink-faint">{{ t('home.area_projects') }}</span>
                </span>

                <!-- Points onward, so it mirrors in RTL. -->
                <AppIcon name="chevron" mirror class="h-4 w-4 shrink-0 text-ink-faint" />
            </Link>

            <div v-else class="flex items-center gap-3 px-5 py-3.5">
                <AppIcon name="areas" class="h-4 w-4 shrink-0 text-ink-faint" />

                <span class="min-w-0 flex-1">
                    <span class="block truncate text-sm text-ink">{{ area.name }}</span>
                    <span v-if="area.type" class="block truncate text-xs text-ink-faint">
                        {{ t(`geography.area_types.${area.type}`) }}
                    </span>
                </span>

                <span class="flex shrink-0 items-baseline gap-1.5">
                    <span class="numeral text-sm font-medium text-ink">
                        {{ formatNumber(area.project_count) }}
                    </span>
                    <span class="text-xs text-ink-faint">{{ t('home.area_projects') }}</span>
                </span>
            </div>
        </li>
    </ul>
</template>
