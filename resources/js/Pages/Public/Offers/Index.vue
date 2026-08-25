<script setup lang="ts">
import { ref, watch } from 'vue';
import { Head, router } from '@inertiajs/vue3';
import PublicLayout from '@/Layouts/PublicLayout.vue';
import AppEmptyState from '@/Components/ui/AppEmptyState.vue';
import OfferCard from '@/Components/OfferCard.vue';
import { t } from '@/lib/i18n';
import { useLocale } from '@/Composables/useLocale';

/*
 * Bare public paths are the *default-locale* (Sorani) URLs under
 * `prefix_except_default`. Emitting them from an Arabic or English page threw
 * the visitor back into Sorani, so each one is wrapped by `localized()`.
 */
const { localized } = useLocale();

interface Offer {
    public_id: string; title: string; offer_type: string; property_type: string;
    price: string | null; currency: string; size_sqm: string | null; rooms: number | null;
    area: string | null; project: string | null; company: string | null;
    company_verified: boolean; location_precision: string; published_at: string | null;
    rank: number; disclosure_label?: string | null;
}

const props = defineProps<{
    organic: Offer[];
    sponsored: Offer[];
    counts: { organic: number; sponsored: number };
    filters: { q: string; type: string | null; budget: string | null };
    seo: { title: string; canonical: string; alternates: Array<{ hreflang: string; href: string }> };
}>();

const filters = ref({ ...props.filters, type: props.filters.type ?? '', budget: props.filters.budget ?? '' });
let timer: ReturnType<typeof setTimeout> | undefined;

watch(filters, () => {
    clearTimeout(timer);
    timer = setTimeout(() => {
        router.get(localized('/offers'), filters.value, { preserveState: true, replace: true });
    }, 300);
}, { deep: true });
</script>

<template>
    <Head>
        <title>{{ seo.title }}</title>
        <link rel="canonical" :href="seo.canonical">
        <link v-for="a in seo.alternates" :key="a.hreflang" rel="alternate" :hreflang="a.hreflang" :href="a.href">
    </Head>

    <PublicLayout>
        <h1 class="mb-6 font-display text-2xl font-bold text-ink">{{ t('marketplace.public.title') }}</h1>

        <div class="mb-8 flex flex-wrap gap-3">
            <input
                v-model="filters.q"
                type="search"
                :placeholder="t('marketplace.public.search')"
                class="mh-field-glass min-h-11 min-w-0 flex-1 rounded-card px-3 py-2 text-sm"
            >
            <input
                v-model="filters.budget"
                type="text"
                dir="ltr"
                :placeholder="t('marketplace.public.budget')"
                class="mh-field-glass min-h-11 w-40 rounded-card px-3 py-2 text-sm"
            >
        </div>

        <!--
          SPONSORED SECTION.

          A separate <section> with its own heading and its own visual
          treatment, above the organic results and never among them. The two
          collections arrive from the server as distinct arrays — the template
          could not interleave them without deliberately merging two props,
          which is the point of the ranker returning them apart.
        -->
        <section v-if="sponsored.length > 0" class="mb-10" :aria-label="t('marketplace.sponsorship.sponsored_section')">
            <div class="mb-3 flex items-baseline justify-between gap-3">
                <h2 class="font-display text-sm font-semibold uppercase tracking-wide text-caution">
                    {{ t('marketplace.sponsorship.sponsored_section') }}
                </h2>
                <p class="text-xs text-ink-faint">{{ t('marketplace.sponsorship.disclosure_notice') }}</p>
            </div>

            <div class="grid gap-4 rounded-panel border border-caution/30 bg-caution/[0.07] p-4 sm:grid-cols-2 lg:grid-cols-3">
                <OfferCard
                    v-for="offer in sponsored"
                    :key="offer.public_id"
                    :offer="offer"
                    :disclosure-label="offer.disclosure_label ?? t('marketplace.sponsorship.sponsored')"
                />
            </div>
        </section>

        <!-- ORGANIC SECTION. Ranked on merit alone. -->
        <section :aria-label="t('marketplace.sponsorship.organic_results')">
            <div class="mb-3 flex items-baseline justify-between gap-3">
                <h2 class="font-display text-sm font-semibold uppercase tracking-wide text-ink-muted">
                    {{ t('marketplace.sponsorship.organic_results') }}
                </h2>
                <p class="numeral text-xs text-ink-faint">{{ counts.organic }}</p>
            </div>

            <AppEmptyState
                v-if="organic.length === 0"
                :title="t('marketplace.public.none')"
                :description="t('marketplace.public.none_hint')"
            />

            <div v-else class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                <OfferCard v-for="offer in organic" :key="offer.public_id" :offer="offer" />
            </div>

            <p class="mt-6 text-xs text-ink-faint">
                {{ t('marketplace.sponsorship.not_ranked_by_payment') }}
            </p>
        </section>
    </PublicLayout>
</template>
