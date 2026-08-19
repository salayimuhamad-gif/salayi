<script setup lang="ts">
import { computed } from 'vue';
import { Head, Link, router } from '@inertiajs/vue3';
import PublicLayout from '@/Layouts/PublicLayout.vue';
import AppEmptyState from '@/Components/ui/AppEmptyState.vue';
import AppAlert from '@/Components/ui/AppAlert.vue';
import AppSelect from '@/Components/ui/AppSelect.vue';
import { t, formatNumber } from '@/lib/i18n';
import { useLocale } from '@/Composables/useLocale';

/*
 * The public place directory (spec 10.3).
 *
 * Category filtering happens server-side, by key rather than id, so the URL a
 * visitor shares reproduces the same list on any environment.
 */
const { localized } = useLocale();

interface PlaceRow {
    slug: string;
    name: string;
    category: string | null;
    area: string | null;
    operational_status: string | null;
}

const props = defineProps<{
    places: { items: PlaceRow[]; total: number; current_page: number; last_page: number };
    categories: Array<{ key: string; name: string }>;
    filters: { category: string; unknown_category: boolean };
    seo: { title: string; canonical: string; alternates: Record<string, string> };
}>();

const categoryOptions = computed(() => [
    { value: '', label: t('geography.public.all_categories') },
    ...props.categories.map((category) => ({ value: category.key, label: category.name })),
]);

function filterByCategory(value: string): void {
    router.get(
        localized('/places'),
        value === '' ? {} : { category: value },
        { preserveState: true, preserveScroll: true, replace: true },
    );
}
</script>

<template>
    <Head>
        <title>{{ seo.title }}</title>
        <link rel="canonical" :href="seo.canonical">
        <link
            v-for="(href, hreflang) in seo.alternates"
            :key="hreflang"
            rel="alternate"
            :hreflang="hreflang"
            :href="href"
        >
    </Head>

    <PublicLayout>
        <h1 class="font-display text-2xl font-bold text-ink">{{ t('geography.public.places_title') }}</h1>
        <p class="mt-2 max-w-2xl text-sm text-ink-muted">{{ t('geography.public.places_intro') }}</p>

        <div class="mt-6 max-w-xs">
            <AppSelect
                :model-value="filters.category"
                :options="categoryOptions"
                :label="t('geography.public.place_category')"
                @update:model-value="filterByCategory"
            />
        </div>

        <!--
            An unknown category is reported, not silently ignored. A visitor
            who mistypes a filter and sees the full list believes they are
            looking at filtered results.
        -->
        <AppAlert
            v-if="filters.unknown_category"
            class="mt-4"
            variant="warning"
            :message="t('geography.public.unknown_category')"
        />

        <AppEmptyState
            v-else-if="places.total === 0"
            class="mt-8"
            :title="t('geography.public.no_places_published')"
            :description="t('geography.public.no_places_published_hint')"
        />

        <template v-else>
            <p class="mt-4 text-sm text-ink-faint">
                <span class="numeral" dir="ltr">{{ formatNumber(places.total) }}</span>
            </p>

            <ul class="mt-4 grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                <li v-for="place in places.items" :key="place.slug">
                    <Link
                        :href="localized(`/places/${place.slug}`)"
                        class="mh-card block p-4 transition-shadow hover:shadow-raised
                               focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-accent"
                    >
                        <span class="font-medium text-ink">{{ place.name }}</span>
                        <span v-if="place.category" class="mt-1 block text-xs text-ink-faint">
                            {{ place.category }}
                        </span>
                        <span v-if="place.area" class="mt-0.5 block text-xs text-ink-muted">
                            {{ t('geography.public.in_area', { area: place.area }) }}
                        </span>
                        <span
                            v-if="place.operational_status && place.operational_status !== 'operating'"
                            class="mt-1 block text-xs text-caution"
                        >{{ t(`geography.operational.${place.operational_status}`) }}</span>
                    </Link>
                </li>
            </ul>
        </template>
    </PublicLayout>
</template>
