<script setup lang="ts">
import { ref } from 'vue';
import { Head, Link, router } from '@inertiajs/vue3';
import AppCard from '@/Components/ui/AppCard.vue';
import AppButton from '@/Components/ui/AppButton.vue';
import PropertyForm from '@/Components/Portfolio/PropertyForm.vue';
import AppPagination from '@/Components/ui/AppPagination.vue';
import { t, formatNumber } from '@/lib/i18n';
import { useLocale } from '@/Composables/useLocale';

const { localized } = useLocale();

/*
 * A person's own properties (File one §9).
 *
 * §9.4 requires the midpoint to travel with its range, confidence, match level,
 * period and the excluded-asking-price note. The midpoint is the number people
 * quote and the one they should trust least, so it is never shown alone here —
 * an owner who reads "worth $180,000" and acts on it deserves to have seen
 * "$165,000–$195,000, low confidence, 4 comparisons" at the same moment.
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
    valuation: Valuation | null;
}

interface NamedOption { id: number; name_ckb: string; name_ar: string | null; name_en: string | null }

/*
 * H9: the index paginates (24 per page, fixed server-side). The paginator
 * arrives as { data, links, ... } — the cards read `data`, the links feed
 * AppPagination.
 */
interface PaginationLink { url: string | null; label: string; active: boolean }

defineProps<{
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

function exportAll(): void {
    window.location.href = localized('/account/portfolio/export');
}

function remove(id: number, label: string | null): void {
    if (!window.confirm(`${t('portfolio.delete_confirm')} — ${label ?? ''}`)) return;

    router.delete(localized(`/account/portfolio/${id}`), { preserveScroll: true });
}
</script>

<template>
    <Head :title="t('portfolio.title')" />

    <main class="mx-auto max-w-3xl px-4 py-8">
        <header class="mb-6 flex flex-wrap items-center justify-between gap-3">
            <h1 class="font-display text-2xl font-bold text-ink">{{ t('portfolio.title') }}</h1>

            <div class="flex gap-2">
                <AppButton v-if="properties.data.length > 0" variant="ghost" @click="exportAll">
                    {{ t('portfolio.export') }}
                </AppButton>
                <AppButton data-testid="add-property" :aria-expanded="adding" @click="adding = !adding">
                    {{ adding ? t('app.actions.cancel') : t('portfolio.form.add') }}
                </AppButton>
            </div>
        </header>

        <!-- The add form (spec §11): folded until asked for, except on an
             empty portfolio where it IS the next step. -->
        <AppCard v-if="adding || properties.data.length === 0" class="mb-6">
            <PropertyForm :projects="projects" :areas="areas" @saved="adding = false" />
        </AppCard>

        <AppCard v-for="property in properties.data" :key="property.id" class="mb-4">
            <div class="flex flex-wrap items-baseline justify-between gap-3">
                <Link
                    :href="localized(`/account/portfolio/${property.id}`)"
                    class="font-display text-lg font-semibold text-ink underline-offset-2 hover:underline"
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
                <AppButton variant="ghost" size="sm" @click="remove(property.id, property.label)">
                    {{ t('app.actions.delete') }}
                </AppButton>
            </div>
        </AppCard>

        <AppPagination :links="properties.links" spa />
    </main>
</template>
