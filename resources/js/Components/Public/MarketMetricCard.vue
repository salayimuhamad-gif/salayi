<script setup lang="ts">
import { computed } from 'vue';
import AppIcon from '@/Components/Icons/AppIcon.vue';
import { t, formatNumber } from '@/lib/i18n';

/*
 * One published market index (§8.2).
 *
 * The whole point of this card is that the figure never travels alone. §8.2
 * lists what has to survive the redesign — period, sample size, confidence, the
 * limited-data warning, currency, price family and basis — and the reason is in
 * HomeController's own comment: an index built on eleven observations and one
 * built on eleven hundred read identically as a number, and only one of them
 * should move a purchase decision. A premium card that promoted the value to
 * 48px and dropped the evidence would be a downgrade wearing better type.
 *
 * So the value gets the typographic weight AND the evidence row stays, in full,
 * below it.
 *
 * NOT here, per §8.2: no sparkline, no historical curve, no decorative bars. A
 * single period arrives from the server and a line needs at least two, so any
 * chart drawn from this prop would be drawing a shape nobody measured.
 *
 * Direction: the trend arrow means up or down, never forward, so it is NOT
 * mirrored in RTL. The value and the percentage are `.numeral`, which isolates
 * them from bidi reordering — without it "‎-3.4" renders as "3.4-" in Sorani.
 */
interface Index {
    key: string;
    name: string | null;
    price_type: string | null;
    basis: string | null;
    value: string;
    change_percent: string | null;
    period: string;
    currency: string;
    sample_size: number | null;
    confidence: string;
    is_limited: boolean;
}

const props = defineProps<{ index: Index }>();

const change = computed<number | null>(() => {
    if (props.index.change_percent === null) return null;

    const numeric = Number(props.index.change_percent);

    return Number.isFinite(numeric) ? numeric : null;
});

/*
 * §13 and §8.2 both forbid meaning carried by colour alone: a red figure and a
 * green figure are the same figure to a red-green colour-blind reader, and the
 * arrow is what actually says which way the market moved.
 */
const trendIcon = computed(() => {
    if (change.value === null || change.value === 0) return 'trend-flat' as const;

    return change.value > 0 ? ('trend-up' as const) : ('trend-down' as const);
});

const trendTone = computed(() => {
    if (change.value === null || change.value === 0) return 'text-ink-muted';

    return change.value > 0 ? 'text-positive' : 'text-negative';
});

const confidenceTone = computed(
    () =>
        ({
            high: 'text-positive',
            moderate: 'text-ink-muted',
            low: 'text-caution',
            insufficient: 'text-negative',
        })[props.index.confidence] ?? 'text-ink-muted',
);
</script>

<template>
    <article class="mh-lux-card mh-lux-gilded p-5">
        <header class="flex items-start justify-between gap-3">
            <h3 class="min-w-0 text-sm font-medium text-ink">{{ index.name }}</h3>
            <span class="mh-lux-eyebrow shrink-0">{{ index.currency }}</span>
        </header>

        <div class="mt-3 flex items-end gap-3">
            <span class="numeral font-display text-3xl font-semibold leading-none text-ink">
                {{ index.value }}
            </span>

            <span v-if="change !== null" class="flex items-center gap-1" :class="trendTone">
                <AppIcon :name="trendIcon" class="h-4 w-4 shrink-0" />
                <span class="numeral text-sm font-medium">{{ index.change_percent }}%</span>
            </span>
        </div>

        <!-- The price family and basis travel with the figure: §14.1 keeps
             asking, official and verified prices strictly apart, and an
             unlabelled index is exactly how an asking price gets read as a
             transaction price. -->
        <div v-if="index.price_type || index.basis" class="mt-3 flex flex-wrap gap-1.5">
            <span v-if="index.price_type" class="mh-lux-chip">
                {{ t(`market.price_types.${index.price_type}`) }}
            </span>
            <span v-if="index.basis" class="mh-lux-chip">
                {{ t(`market.basis.${index.basis}`) }}
            </span>
        </div>

        <!-- The evidence that makes the figure readable. -->
        <dl class="mt-4 flex flex-wrap gap-x-4 gap-y-1.5 border-t border-line pt-3 text-xs">
            <div class="flex items-center gap-1.5">
                <dt class="text-ink-faint">{{ t('market.period') }}</dt>
                <dd class="numeral text-ink-muted">{{ index.period }}</dd>
            </div>

            <div v-if="index.sample_size" class="flex items-center gap-1.5">
                <dt class="text-ink-faint">{{ t('home.sample') }}</dt>
                <dd class="numeral text-ink-muted">{{ formatNumber(index.sample_size) }}</dd>
            </div>

            <div class="flex items-center gap-1.5">
                <dt class="text-ink-faint">{{ t('market.explanation.confidence') }}</dt>
                <dd :class="confidenceTone">{{ t(`market.confidence.${index.confidence}`) }}</dd>
            </div>
        </dl>

        <p v-if="index.is_limited" class="mt-3 flex items-start gap-2 text-xs text-caution">
            <span aria-hidden="true">!</span>
            <span>{{ t('market.warnings.sample_below_minimum') }}</span>
        </p>
    </article>
</template>
