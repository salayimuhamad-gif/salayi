<script setup lang="ts">
import AppAlert from '@/Components/ui/AppAlert.vue';
import { t, formatNumber } from '@/lib/i18n';

interface Point {
    period: string;
    value: string;
    sample_size: number | null;
    source: string | null;
    confidence: string;
}

interface Series {
    price_type: string;
    family: string;
    requires_qualifier: boolean;
    currency: string;
    points: Point[];
    change_percent: string | null;
    sources: string[];
}

defineProps<{
    prices: {
        series: Series[];
        has_verified: boolean;
        has_asking_only: boolean;
        period_from: string | null;
        period_to: string | null;
    };
}>();

/*
 * Each series is drawn as its own line with its own scale label, never merged.
 * A single trend combining asking prices and verified transactions would be the
 * most misleading thing this page could render — the two diverge most in a
 * falling market, which is precisely when a buyer depends on the difference.
 */
function path(points: Point[]): string {
    if (points.length < 2) return '';
    const values = points.map((p) => Number(p.value));
    const min = Math.min(...values);
    const max = Math.max(...values);
    const span = max - min || 1;
    return values
        .map((v, i) => `${(i / (values.length - 1)) * 100},${36 - ((v - min) / span) * 30}`)
        .join(' ');
}

const stroke = (family: string): string => ({
    verified: 'text-positive',
    official: 'text-brand',
    asking: 'text-caution',
}[family] ?? 'text-ink-muted');

const latest = (s: Series): Point => s.points[s.points.length - 1];
</script>

<template>
    <div class="space-y-5">
        <!-- The disclosure that matters most: if everything on this page is
             what sellers were asking, say so before the reader reads it as a
             record of what the market did. -->
        <AppAlert v-if="prices.has_asking_only" variant="warning">
            {{ t('market.history.asking_only_notice') }}
        </AppAlert>

        <div v-for="series in prices.series" :key="series.price_type" class="rounded-card border border-line p-4">
            <div class="flex flex-wrap items-baseline justify-between gap-3">
                <div>
                    <p class="text-sm font-medium text-ink">
                        {{ t(`market.price_types.${series.price_type}`) }}
                    </p>
                    <p v-if="series.requires_qualifier" class="mt-0.5 text-xs text-caution">
                        {{ t(`market.public.qualifier.${series.price_type}`) }}
                    </p>
                </div>

                <div class="text-end">
                    <p class="numeral font-display text-lg font-semibold text-ink" dir="ltr">
                        {{ formatNumber(Number(latest(series).value)) }}
                        <span class="text-xs font-normal text-ink-muted">{{ series.currency }}</span>
                    </p>
                    <p
                        v-if="series.change_percent"
                        class="numeral text-xs"
                        :class="Number(series.change_percent) >= 0 ? 'text-positive' : 'text-negative'"
                        dir="ltr"
                    >
                        {{ Number(series.change_percent) >= 0 ? '+' : '' }}{{ series.change_percent }}%
                        <span class="text-ink-faint">{{ t('market.history.over_period') }}</span>
                    </p>
                </div>
            </div>

            <svg
                v-if="series.points.length > 1"
                class="mt-3 h-10 w-full"
                viewBox="0 0 100 36"
                preserveAspectRatio="none"
                role="img"
                :aria-label="t(`market.price_types.${series.price_type}`)"
            >
                <polyline
                    :points="path(series.points)"
                    fill="none"
                    stroke="currentColor"
                    stroke-width="1.5"
                    :class="stroke(series.family)"
                    vector-effect="non-scaling-stroke"
                />
            </svg>

            <div class="mt-3 flex flex-wrap items-center gap-x-4 gap-y-1 text-xs text-ink-faint">
                <span class="numeral" dir="ltr">
                    {{ series.points[0].period }} – {{ latest(series).period }}
                </span>
                <span v-if="latest(series).sample_size" class="numeral">
                    n={{ latest(series).sample_size }}
                </span>
                <!-- Every published figure names its source, here as elsewhere. -->
                <span v-if="series.sources.length > 0">
                    {{ t('app.meta.source') }}: {{ series.sources.join(', ') }}
                </span>
            </div>
        </div>
    </div>
</template>
