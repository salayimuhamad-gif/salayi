<script setup lang="ts">
import { computed, ref } from 'vue';
import { Head, Link, router } from '@inertiajs/vue3';
import PublicLayout from '@/Layouts/PublicLayout.vue';
import AppCard from '@/Components/ui/AppCard.vue';
import AppButton from '@/Components/ui/AppButton.vue';
import PropertyForm from '@/Components/Portfolio/PropertyForm.vue';
import AppPagination from '@/Components/ui/AppPagination.vue';
import { t, formatNumber } from '@/lib/i18n';
import { useLocale } from '@/Composables/useLocale';

const { localized } = useLocale();

/*
 * A person's own properties (File one §9), with the Wave 5 dashboard band.
 *
 * Every figure in the band arrives from PortfolioSummaryService, which derives
 * it on each read from the same rows the cards below render — the count, the
 * per-currency totals, the coverage and the latest valued date can therefore
 * never disagree with the list. Two honesty rules the band inherits:
 *
 *   A property whose latest valuation is a refusal — or which has none — is
 *   AWAITING, never zero. "No current valuation is available yet" is the
 *   state; $0 would be a claim.
 *
 *   Currencies never mix. Totals arrive grouped per currency and render as
 *   separate figures; when more than one group exists the band says why there
 *   is no single number instead of inventing an exchange rate.
 *
 * §9.4 requires the midpoint to travel with its range, confidence, match level,
 * period and the excluded-asking-price note — unchanged on every card below.
 */
interface Valuation {
    midpoint: string;
    low: string;
    high: string;
    confidence: string;
    match_level: number;
    match_label: string | null;
    period_from: string | null;
    period_to: string | null;
    methodology: string | null;
    comparison_count: number | null;
    excluded_asking_count: number | null;
    created_at: string | null;
}

interface Property {
    id: number;
    label: string | null;
    property_type: string;
    size_sqm: string | null;
    currency: string;
    consent_valuation: boolean;
    has_location: boolean;
    project_id: number | null;
    area_id: number | null;
    valuation: Valuation | null;
}

interface NamedOption { id: number; name_ckb: string; name_ar: string | null; name_en: string | null }

interface Summary {
    property_count: number;
    valued_count: number;
    awaiting_count: number;
    totals: Array<{ currency: string; total: string; count: number }>;
    multi_currency: boolean;
    latest_valued_at: string | null;
    composition: Array<{ property_type: string; count: number }>;
    state: 'no_assets' | 'no_valuations' | 'ready';
}

interface PaginationLink { url: string | null; label: string; active: boolean }

const props = defineProps<{
    summary: Summary;
    properties: { data: Property[]; links: PaginationLink[] };
    projects: NamedOption[];
    areas: NamedOption[];
}>();

// Adding is a deliberate act, so the form starts folded — except when the
// portfolio is empty, where the form IS the page's next step.
const adding = ref(false);

const confidenceTone = (confidence: string): string => ({
    high: 'text-positive',
    moderate: 'text-ink',
    low: 'text-caution',
    insufficient: 'text-negative',
}[confidence] ?? 'text-ink-muted');

/*
 * The repository's client-side name fallback (the same rule Profile has always
 * used): the page's language first, Sorani as the floor.
 */
function entityName(option: NamedOption | undefined): string | null {
    if (!option) return null;

    const locale = document.documentElement.lang || 'ckb';

    return (locale === 'ar' ? option.name_ar : locale === 'en' ? option.name_en : option.name_ckb)
        ?? option.name_ckb;
}

const projectsById = computed(() => new Map(props.projects.map((p) => [p.id, p])));
const areasById = computed(() => new Map(props.areas.map((a) => [a.id, a])));

function identityOf(property: Property): string | null {
    const parts = [
        entityName(property.project_id === null ? undefined : projectsById.value.get(property.project_id)),
        entityName(property.area_id === null ? undefined : areasById.value.get(property.area_id)),
    ].filter((name): name is string => name !== null);

    return parts.length === 0 ? null : parts.join(' · ');
}

/*
 * Presentation only — the exact decimal lives on the server and in the tests.
 * The same value/format discipline as MarketMetricCard: a parsable figure is
 * formatted, an unparsable one renders untouched rather than becoming NaN.
 */
function figure(value: string): string {
    const parsed = Number(value);

    return Number.isFinite(parsed) ? formatNumber(parsed, Number.isInteger(parsed) ? 0 : 2) : value;
}

function exportAll(): void {
    window.location.href = localized('/account/portfolio/export');
}

function remove(id: number, label: string | null): void {
    if (!window.confirm(`${t('portfolio.delete_confirm')} — ${label ?? ''}`)) return;

    router.delete(localized(`/account/portfolio/${id}`), { preserveScroll: true });
}

const statCard = 'rounded-xl border border-line bg-surface p-4';
</script>

<template>
    <Head :title="t('portfolio.title')" />

    <PublicLayout>
        <main class="mx-auto w-full max-w-4xl px-4 py-8 sm:py-10">
            <header class="mb-6 flex flex-wrap items-center justify-between gap-3">
                <h1 class="font-display text-2xl font-bold text-ink">{{ t('portfolio.title') }}</h1>

                <div class="flex gap-2">
                    <AppButton v-if="properties.data.length > 0" variant="ghost" class="min-h-11" @click="exportAll">
                        {{ t('portfolio.export') }}
                    </AppButton>
                    <AppButton data-testid="add-property" class="min-h-11" :aria-expanded="adding" @click="adding = !adding">
                        {{ adding ? t('app.actions.cancel') : t('portfolio.form.add') }}
                    </AppButton>
                </div>
            </header>

            <!-- ====================== the dashboard band ====================== -->
            <section
                v-if="summary.state !== 'no_assets'"
                class="mb-6"
                :aria-label="t('portfolio.summary.title')"
                data-testid="portfolio-summary"
            >
                <h2 class="mh-microlabel mb-3">{{ t('portfolio.summary.title') }}</h2>

                <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                    <div :class="statCard" data-testid="summary-count">
                        <p class="mh-microlabel">{{ t('portfolio.summary.properties') }}</p>
                        <p class="numeral mt-1.5 font-display text-2xl font-semibold text-ink" dir="ltr">
                            {{ formatNumber(summary.property_count) }}
                        </p>
                        <p class="mt-1 text-xs text-ink-muted" data-testid="summary-coverage">
                            {{ t('portfolio.summary.coverage', {
                                valued: formatNumber(summary.valued_count),
                                total: formatNumber(summary.property_count),
                            }) }}
                        </p>
                    </div>

                    <!-- One card per currency, never one card for all of them. -->
                    <div
                        v-for="group in summary.totals"
                        :key="group.currency"
                        :class="statCard"
                        :data-testid="`summary-total-${group.currency}`"
                    >
                        <p class="mh-microlabel">{{ t('portfolio.summary.total_label') }}</p>
                        <p class="numeral mt-1.5 font-display text-2xl font-semibold text-ink" dir="ltr">
                            {{ figure(group.total) }} {{ group.currency }}
                        </p>
                        <p class="mt-1 text-xs text-ink-muted">
                            {{ t('portfolio.summary.per_currency_count', {
                                count: formatNumber(group.count),
                                currency: group.currency,
                            }) }}
                        </p>
                    </div>

                    <!-- No valuations at all: the honest sentence, never $0. -->
                    <div
                        v-if="summary.state === 'no_valuations'"
                        :class="statCard"
                        data-testid="summary-no-valuations"
                    >
                        <p class="mh-microlabel">{{ t('portfolio.summary.total_label') }}</p>
                        <p class="mt-1.5 text-sm text-ink-muted">{{ t('portfolio.summary.no_valuations') }}</p>
                    </div>

                    <div :class="statCard">
                        <p class="mh-microlabel">{{ t('portfolio.summary.latest_valued') }}</p>
                        <p
                            v-if="summary.latest_valued_at"
                            class="numeral mt-1.5 font-display text-lg font-semibold text-ink"
                            dir="ltr"
                            data-testid="summary-latest"
                        >{{ summary.latest_valued_at }}</p>
                        <p v-else class="mt-1.5 text-sm text-ink-muted">{{ t('portfolio.summary.no_valuations') }}</p>

                        <p v-if="summary.awaiting_count > 0" class="mt-1 text-xs text-ink-muted" data-testid="summary-awaiting">
                            {{ t('portfolio.summary.awaiting') }}:
                            <span class="numeral" dir="ltr">{{ formatNumber(summary.awaiting_count) }}</span>
                        </p>
                    </div>
                </div>

                <p
                    v-if="summary.multi_currency"
                    class="mt-2.5 text-xs text-ink-muted"
                    data-testid="summary-multi-currency"
                >
                    {{ t('portfolio.summary.multi_currency_note') }}
                </p>

                <div
                    v-if="summary.composition.length > 0"
                    class="mt-3 flex flex-wrap items-center gap-1.5"
                    data-testid="summary-composition"
                >
                    <span class="mh-microlabel">{{ t('portfolio.summary.composition') }}</span>
                    <span
                        v-for="slice in summary.composition"
                        :key="slice.property_type"
                        class="inline-flex min-h-6 items-center rounded-full border border-line px-2.5 py-0.5 text-xs text-ink-muted"
                    >
                        {{ t(`portfolio.types.${slice.property_type}`) }}
                        <span class="numeral ms-1" dir="ltr">{{ formatNumber(slice.count) }}</span>
                    </span>
                </div>
            </section>

            <!-- The add form (spec §11): folded until asked for, except on an
                 empty portfolio where it IS the next step. -->
            <AppCard v-if="adding || properties.data.length === 0" class="mb-6">
                <p v-if="properties.data.length === 0" class="mb-4 text-sm text-ink-muted" data-testid="portfolio-empty">
                    {{ t('portfolio.none') }}
                </p>
                <PropertyForm :projects="projects" :areas="areas" @saved="adding = false" />
            </AppCard>

            <AppCard v-for="property in properties.data" :key="property.id" class="mb-4" data-testid="property-card">
                <div class="flex flex-wrap items-baseline justify-between gap-3">
                    <!-- A flex child is blockified, so this link IS a measured
                         touch target: it must carry the 44px floor itself. -->
                    <Link
                        :href="localized(`/account/portfolio/${property.id}`)"
                        class="inline-flex min-h-11 items-center font-display text-lg font-semibold text-ink underline-offset-2 hover:underline"
                    >
                        {{ property.label ?? '—' }}
                    </Link>

                    <span class="text-xs text-ink-muted">
                        {{ t(`portfolio.types.${property.property_type}`) }}
                        <template v-if="property.size_sqm">
                            · <span class="numeral" dir="ltr">{{ property.size_sqm }}</span> m²
                        </template>
                    </span>
                </div>

                <!-- Real stored identity only: the published project/area the
                     owner attached, in the page's language — or nothing. -->
                <p v-if="identityOf(property)" class="mt-1 text-xs text-ink-muted" data-testid="property-entity">
                    {{ identityOf(property) }}
                </p>

                <!-- Valuation is opt-in; a property without consent shows no
                     estimate rather than a blank one. -->
                <p v-if="!property.consent_valuation" class="mt-3 text-sm text-ink-faint">
                    {{ t('portfolio.no_valuation') }}
                </p>

                <div v-else-if="property.valuation" class="mt-3">
                    <p class="numeral font-display text-2xl font-semibold text-ink" dir="ltr">
                        {{ property.valuation.midpoint }} {{ property.currency }}
                    </p>

                    <!-- §9.4: the range and confidence sit with the midpoint, not
                         behind a disclosure link. -->
                    <p class="numeral mt-1 text-sm text-ink-muted" dir="ltr">
                        {{ property.valuation.low }} – {{ property.valuation.high }}
                    </p>

                    <div class="mt-2 flex flex-wrap gap-x-4 gap-y-1 text-xs">
                        <span :class="confidenceTone(property.valuation.confidence)">
                            {{ t('portfolio.confidence') }}:
                            {{ t(`market.confidence.${property.valuation.confidence}`) }}
                        </span>

                        <span v-if="property.valuation.match_label" class="text-ink-muted">
                            {{ property.valuation.match_label }}
                        </span>

                        <span v-if="property.valuation.comparison_count" class="numeral text-ink-muted">
                            {{ t('portfolio.comparisons') }}:
                            {{ formatNumber(property.valuation.comparison_count) }}
                        </span>

                        <span
                            v-if="property.valuation.period_from"
                            class="numeral text-ink-faint"
                            dir="ltr"
                        >{{ property.valuation.period_from }} – {{ property.valuation.period_to }}</span>
                    </div>

                    <!-- The excluded-asking-price note, as a count. "Twelve asking
                         prices were excluded" says listing prices exist and were
                         deliberately not counted; a yes/no reads as boilerplate. -->
                    <p
                        v-if="property.valuation.excluded_asking_count"
                        class="numeral mt-2 text-xs text-caution"
                    >
                        {{ formatNumber(property.valuation.excluded_asking_count) }}
                        {{ t('portfolio.excluded_asking') }}
                    </p>
                </div>

                <p v-else class="mt-3 text-sm text-ink-faint">{{ t('portfolio.no_valuation') }}</p>

                <div class="mt-4">
                    <!-- md is the component's own 44px tier; a min-h-11 class
                         appended to sm loses to the size's min-h-9 in the
                         compiled stylesheet and measured 38px on mobile. -->
                    <AppButton variant="ghost" size="md" @click="remove(property.id, property.label)">
                        {{ t('app.actions.delete') }}
                    </AppButton>
                </div>
            </AppCard>

            <AppPagination :links="properties.links" spa />
        </main>
    </PublicLayout>
</template>
