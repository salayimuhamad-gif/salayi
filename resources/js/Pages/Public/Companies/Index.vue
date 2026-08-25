<script setup lang="ts">
import { ref } from 'vue';
import { Head, Link, router } from '@inertiajs/vue3';
import PublicLayout from '@/Layouts/PublicLayout.vue';
import AppCard from '@/Components/ui/AppCard.vue';
import AppEmptyState from '@/Components/ui/AppEmptyState.vue';
import AppPagination from '@/Components/ui/AppPagination.vue';
import { t, formatNumber } from '@/lib/i18n';
import { useLocale } from '@/Composables/useLocale';

/*
 * Bare public paths are the *default-locale* (Sorani) URLs under
 * `prefix_except_default`. Emitting them from an Arabic or English page threw
 * the visitor back into Sorani, so each one is wrapped by `localized()`.
 */
const { localized } = useLocale();

/*
 * Public company directory (File two §11).
 *
 * Only verified companies reach this page — the controller's scope guarantees
 * it. The verified badge is still rendered from the payload rather than assumed,
 * because a badge that is always true by construction is one nobody notices
 * when the construction changes.
 */
interface CompanyRow {
    slug: string;
    name: string;
    brand_name: string | null;
    logo_path: string | null;
    specialties: string[];
    languages: string[];
    branches_count: number;
    projects_count: number;
    is_verified: boolean;
}

const props = defineProps<{
    companies: {
        data: CompanyRow[];
        links: Array<{ url: string | null; label: string; active: boolean }>;
        last_page: number;
    };
    filters: { q?: string; specialty?: string; area_id?: number };
}>();

const query = ref(props.filters.q ?? '');

function search(): void {
    router.get(localized('/companies'), { q: query.value }, { preserveState: true, replace: true });
}
</script>

<template>
    <Head :title="t('companies.public.directory')" />

    <PublicLayout>
        <article class="mx-auto max-w-5xl">
            <h1 class="font-display text-2xl font-bold text-ink">
                {{ t('companies.public.directory') }}
            </h1>

            <div class="mt-5 flex gap-2">
                <input
                    v-model="query"
                    type="search"
                    :placeholder="t('companies.public.search')"
                    :aria-label="t('companies.public.search')"
                    class="mh-field-glass min-h-11 min-w-0 flex-1 rounded-card px-3 py-2 text-sm"
                    @keyup.enter="search"
                >
                <button
                    type="button"
                    class="mh-lux-btn mh-lux-btn-primary shrink-0"
                    @click="search"
                >
                    {{ t('app.actions.search') }}
                </button>
            </div>

            <AppEmptyState
                v-if="companies.data.length === 0"
                class="mt-8"
                :title="t('companies.public.none')"
                :description="t('companies.public.search')"
            />

            <ul v-else class="mt-6 grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                <li v-for="company in companies.data" :key="company.slug">
                    <AppCard class="h-full">
                        <Link
                            :href="localized(`/companies/${company.slug}`)"
                            class="font-display text-base font-semibold text-ink underline-offset-2 hover:underline"
                        >
                            {{ company.name }}
                        </Link>

                        <p v-if="company.is_verified" class="mt-1 text-xs text-positive">
                            {{ t('companies.public.verified') }}
                        </p>

                        <p class="numeral mt-2 text-xs text-ink-muted">
                            {{ t('companies.public.branches') }}: {{ formatNumber(company.branches_count) }}
                            ·
                            {{ t('companies.public.projects') }}: {{ formatNumber(company.projects_count) }}
                        </p>

                        <ul v-if="company.specialties.length" class="mt-3 flex flex-wrap gap-1.5">
                            <li
                                v-for="specialty in company.specialties.slice(0, 3)"
                                :key="specialty"
                                class="rounded-card bg-surface-sunken px-2 py-0.5 text-xs text-ink-muted"
                            >
                                {{ specialty }}
                            </li>
                        </ul>
                    </AppCard>
                </li>
            </ul>

            <AppPagination v-if="companies.last_page > 1" :links="companies.links" spa />
        </article>
    </PublicLayout>
</template>
