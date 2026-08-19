<script setup lang="ts">
import { computed } from 'vue';
import { Head } from '@inertiajs/vue3';
import PublicLayout from '@/Layouts/PublicLayout.vue';
import AppCard from '@/Components/ui/AppCard.vue';
import AppAlert from '@/Components/ui/AppAlert.vue';
import AppEmptyState from '@/Components/ui/AppEmptyState.vue';
import MarketTrendChart from '@/Components/Public/MarketTrendChart.vue';
import IndexExplanation from '@/Components/IndexExplanation.vue';
import { t, formatNumber } from '@/lib/i18n';

/*
 * The spec 15.3 explanation shape, declared once. Writing it as a loose record
 * would have silently accepted a payload missing half of what 15.3 requires —
 * the compiler is the only thing checking that contract on this side.
 */
interface Explanation {
    period: string;
    effective_date: string | null;
    sample_size: number;
    excluded_outliers: number;
    sources: string[];
    confidence: string;
    revision_status: string;
    revision_number: number;
    methodology_version: string;
    is_limited: boolean;
    warning: string | null;
}

interface Index_ {
    key: string;
    name: string;
    price_type: string;
    family: string;
    requires_qualifier: boolean;
    currency: string;
    basis: string;
    latest: { period: string; value: string; change_percent: string | null; direction: string | null };
    explanation: Explanation;
    series: Array<{ period: string; value: string; is_limited: boolean }>;
}

const props = defineProps<{
    indices: Index_[];
    unavailable: Record<string, boolean>;
    seo: { title: string; canonical: string; alternates: Array<{ hreflang: string; href: string }> };
}>();

const missing = computed(() =>
    Object.entries(props.unavailable).filter(([, v]) => v).map(([k]) => k),
);

// Verified indices lead. An asking-price figure placed first would be read as
// the headline market number regardless of its label.
const ordered = computed(() =>
    [...props.indices].sort((a, b) => {
        const rank = (f: string): number => ({ verified: 0, official: 1, asking: 2 }[f] ?? 3);
        return rank(a.family) - rank(b.family);
    }),
);

</script>

<template>
    <Head>
        <title>{{ seo.title }}</title>
        <link rel="canonical" :href="seo.canonical">
        <link v-for="a in seo.alternates" :key="a.hreflang" rel="alternate" :hreflang="a.hreflang" :href="a.href">
    </Head>

    <PublicLayout>
        <h1 class="mb-2 font-display text-2xl font-bold text-ink">{{ t('market.public.title') }}</h1>
        <p class="mb-8 max-w-2xl text-sm text-ink-muted">{{ t('market.public.intro') }}</p>

        <AppEmptyState
            v-if="indices.length === 0"
            :title="t('market.public.none')"
            :description="t('market.public.none_hint')"
        />

        <div v-else class="grid gap-5 lg:grid-cols-2">
            <AppCard v-for="index in ordered" :key="index.key" :title="index.name">
                <div class="flex items-end justify-between gap-4">
                    <div>
                        <p class="numeral font-display text-3xl font-bold text-ink" dir="ltr">
                            {{ formatNumber(Number(index.latest.value)) }}
                            <span class="text-base font-normal text-ink-muted">{{ index.currency }}</span>
                        </p>
                        <p class="numeral mt-1 text-xs text-ink-faint" dir="ltr">{{ index.latest.period }}</p>
                    </div>

                    <p
                        v-if="index.latest.change_percent"
                        class="numeral text-sm font-medium"
                        :class="index.latest.direction === 'up' ? 'text-positive'
                            : index.latest.direction === 'down' ? 'text-negative' : 'text-ink-muted'"
                        dir="ltr"
                    >
                        {{ index.latest.direction === 'up' ? '+' : '' }}{{ index.latest.change_percent }}%
                    </p>
                </div>

                <!-- A sparkline, not a chart library (still). The upgraded
                     chart draws on once in view and re-draws limited-sample
                     runs as dashed caution overlays at the true coordinates —
                     honesty in the visualization itself. Never mirrored. -->
                <MarketTrendChart
                    v-if="index.series.length > 1"
                    :series="index.series"
                    :height="32"
                    class="mt-4"
                    :aria-label="t('market.public.trend')"
                />

                <div class="mt-4 border-t border-line pt-4">
                    <IndexExplanation
                        :explanation="index.explanation"
                        :requires-qualifier="index.requires_qualifier"
                        :price-type="index.price_type"
                    />
                </div>
            </AppCard>
        </div>

        <AppCard v-if="missing.length > 0" :title="t('projects.not_yet_available')" class="mt-6">
            <ul class="space-y-1.5 text-sm text-ink-muted">
                <li v-for="key in missing" :key="key" class="flex gap-2">
                    <span aria-hidden="true">·</span>
                    <span>{{ t(`market.public.pending.${key}`) }}</span>
                </li>
            </ul>
        </AppCard>

        <AppAlert variant="info" class="mt-6">
            {{ t('market.public.methodology_notice') }}
        </AppAlert>
    </PublicLayout>
</template>
