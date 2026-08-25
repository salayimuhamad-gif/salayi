<script setup lang="ts">
import { ref } from 'vue';
import { Head, Link, router } from '@inertiajs/vue3';
import PublicLayout from '@/Layouts/PublicLayout.vue';
import AppEmptyState from '@/Components/ui/AppEmptyState.vue';
import AppIcon from '@/Components/Icons/AppIcon.vue';
import { t } from '@/lib/i18n';
import { useLocale } from '@/Composables/useLocale';

/*
 * Bare public paths are the *default-locale* (Sorani) URLs under
 * `prefix_except_default`. Emitting them from an Arabic or English page threw
 * the visitor back into Sorani, so each one is wrapped by `localized()`.
 */
const { localized } = useLocale();

interface Row {
    slug: string;
    name: string;
    area: string | null;
    developer: string | null;
    construction_status: string | null;
    is_adverse: boolean;
    completion_percent: number | null;
}

const props = defineProps<{ projects: { data: Row[] }; filters: { q: string } }>();

/*
 * The index consumes the same `q` the homepage hero submits — the server has
 * filtered by it all along (ProjectProfileController), the page just never
 * SHOWED it, so a visitor arriving from the hero search saw a filtered list
 * with no visible query and no way to refine it. Same GET contract, nothing
 * new server-side; an empty query is simply the unfiltered index.
 */
const searchQuery = ref(props.filters.q ?? '');

function submitSearch(): void {
    const q = searchQuery.value.trim();

    router.get(localized('/projects'), q === '' ? {} : { q });
}
</script>

<template>
    <Head :title="t('nav.projects')" />

    <PublicLayout>
        <div class="mb-6 flex flex-wrap items-center justify-between gap-x-6 gap-y-4">
            <h1 class="font-display text-2xl font-bold text-ink">{{ t('nav.projects') }}</h1>

            <form
                role="search"
                class="mh-searchbar flex w-full items-center gap-2.5 !rounded-pill px-4 py-1 sm:w-80"
                @submit.prevent="submitSearch"
            >
                <AppIcon name="search" class="h-4 w-4 shrink-0 text-ink-faint" aria-hidden="true" />
                <label class="sr-only" for="projects-search">{{ t('home.search_label') }}</label>
                <input
                    id="projects-search"
                    v-model="searchQuery"
                    type="search"
                    name="q"
                    class="min-h-11 flex-1 text-sm"
                    :placeholder="t('home.search_placeholder')"
                    autocomplete="off"
                >
            </form>
        </div>

        <AppEmptyState
            v-if="projects.data.length === 0"
            :title="filters.q ? t('projects.search_none') : t('projects.none_published')"
            :description="filters.q ? t('projects.search_none_hint') : t('projects.none_published_hint')"
        />

        <div v-else class="grid gap-5 sm:grid-cols-2 lg:grid-cols-3">
            <Link
                v-for="project in projects.data"
                :key="project.slug"
                :href="localized(`/projects/${project.slug}`)"
                class="mh-card mh-lux-card-interactive block p-5
                       focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-accent"
            >
                <p v-if="project.area" class="mh-label">{{ project.area }}</p>
                <h2 class="mt-1 font-display text-base font-semibold text-ink">{{ project.name }}</h2>
                <p v-if="project.developer" class="mt-1 text-sm text-ink-muted">{{ project.developer }}</p>

                <div class="mt-4 flex flex-wrap items-center gap-2">
                    <span
                        v-if="project.construction_status"
                        class="rounded-full px-2.5 py-0.5 text-xs"
                        :class="project.is_adverse ? 'bg-caution/10 text-caution' : 'bg-surface-sunken text-ink-muted'"
                    >{{ t(`projects.construction_statuses.${project.construction_status}`) }}</span>

                    <span v-if="project.completion_percent !== null" class="numeral text-xs text-ink-faint">
                        {{ project.completion_percent }}%
                    </span>
                </div>
            </Link>
        </div>
    </PublicLayout>
</template>
