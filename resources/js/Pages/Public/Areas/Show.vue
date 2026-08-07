<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import PublicLayout from '@/Layouts/PublicLayout.vue';
import AppEmptyState from '@/Components/ui/AppEmptyState.vue';
import { t, formatNumber } from '@/lib/i18n';
import { useLocale } from '@/Composables/useLocale';

/*
 * A single public area profile (spec 10.2, 12.2).
 *
 * The direct project count and the subtree count are shown as two separate,
 * separately labelled figures. Folding them into one produces a district
 * headline of "48" above a list of six, which no visitor can reconcile and
 * which quietly destroys trust in every other number on the page.
 */
const { localized } = useLocale();

interface Crumb {
    slug: string;
    name: string;
}

interface Child {
    slug: string;
    name: string;
    type_label: string;
    project_count: number;
}

interface ProjectRow {
    slug: string;
    name: string;
    status: string | null;
}

interface PlaceRow {
    name: string;
    category: string | null;
    operational_status: string | null;
}

defineProps<{
    area: {
        slug: string;
        name: string;
        name_is_fallback: boolean;
        description: string | null;
        type: string;
        type_label: string;
        lifestyle_classification: string | null;
        coordinates: { latitude: number; longitude: number } | null;
        has_boundary: boolean;
    };
    breadcrumb: Crumb[];
    children: Child[];
    projects: { items: ProjectRow[]; total: number; current_page: number; last_page: number };
    subtree_project_count: number;
    places: PlaceRow[];
    seo: { title: string; canonical: string; alternates: Record<string, string> };
}>();
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
        <!-- Ancestors are all published: the controller 404s the page rather
             than rendering a chain that leaks an unpublished parent's name. -->
        <nav v-if="breadcrumb.length > 0" class="mb-3 flex flex-wrap items-center gap-1.5 text-sm text-ink-muted">
            <Link
                :href="localized('/areas')"
                class="hover:text-brand focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-accent"
            >
                {{ t('geography.public.index_title') }}
            </Link>

            <template v-for="crumb in breadcrumb" :key="crumb.slug">
                <span class="text-ink-faint" aria-hidden="true">/</span>
                <Link
                    :href="localized(`/areas/${crumb.slug}`)"
                    class="hover:text-brand focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-accent"
                >
                    {{ crumb.name }}
                </Link>
            </template>
        </nav>

        <header>
            <p class="mh-label">{{ area.type_label }}</p>
            <h1 class="mt-1 font-display text-2xl font-bold text-ink">{{ area.name }}</h1>

            <p v-if="area.name_is_fallback" class="mt-1 text-xs text-ink-faint">
                {{ t('geography.public.name_fallback_notice') }}
            </p>

            <p v-if="area.lifestyle_classification" class="mt-2 text-sm text-ink-muted">
                {{ t('geography.public.lifestyle') }}: {{ area.lifestyle_classification }}
            </p>

            <p class="mt-4 max-w-2xl text-sm leading-relaxed text-ink-muted">
                {{ area.description ?? t('geography.public.no_description') }}
            </p>

            <p v-if="!area.coordinates" class="mt-2 text-xs text-ink-faint">
                {{ t('geography.public.no_coordinates') }}
            </p>
        </header>

        <!-- ------------------------------------------------------ projects -->
        <section class="mt-10">
            <h2 class="mb-3 font-display text-lg font-semibold text-ink">{{ t('geography.public.projects') }}</h2>

            <AppEmptyState
                v-if="projects.total === 0"
                :title="t('geography.public.no_projects')"
                :description="t('geography.public.no_projects_hint')"
            />

            <template v-else>
                <ul class="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                    <li v-for="project in projects.items" :key="project.slug">
                        <Link
                            :href="localized(`/projects/${project.slug}`)"
                            class="mh-card block p-4 transition-shadow hover:shadow-raised
                                   focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-accent"
                        >
                            <span class="font-medium text-ink">{{ project.name }}</span>
                            <span v-if="project.status" class="mt-1 block text-xs text-ink-faint">
                                {{ t(`projects.construction_statuses.${project.status}`) }}
                            </span>
                        </Link>
                    </li>
                </ul>

                <!-- Two figures, two labels. See the script comment. -->
                <p
                    v-if="subtree_project_count > projects.total"
                    class="mt-4 text-sm text-ink-faint"
                >
                    {{ t('geography.public.subtree_count', { count: subtree_project_count }) }}
                </p>
            </template>
        </section>

        <!-- ----------------------------------------------------- sub-areas -->
        <section class="mt-10">
            <h2 class="mb-3 font-display text-lg font-semibold text-ink">{{ t('geography.public.children') }}</h2>

            <AppEmptyState v-if="children.length === 0" :title="t('geography.public.no_children')" />

            <ul v-else class="flex flex-wrap gap-2">
                <li v-for="child in children" :key="child.slug">
                    <Link
                        :href="localized(`/areas/${child.slug}`)"
                        class="inline-flex items-center gap-2 rounded-card border border-line px-3 py-1.5 text-sm
                               text-ink-muted transition-colors hover:bg-surface-sunken hover:text-ink
                               focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-accent"
                    >
                        {{ child.name }}
                        <span class="numeral text-xs text-ink-faint" dir="ltr">
                            {{ formatNumber(child.project_count) }}
                        </span>
                    </Link>
                </li>
            </ul>
        </section>

        <!-- -------------------------------------------------------- places -->
        <section class="mt-10">
            <h2 class="mb-3 font-display text-lg font-semibold text-ink">{{ t('geography.public.places') }}</h2>

            <AppEmptyState v-if="places.length === 0" :title="t('geography.public.no_places')" />

            <ul v-else class="grid gap-2 sm:grid-cols-2 lg:grid-cols-3">
                <li
                    v-for="place in places"
                    :key="place.name"
                    class="rounded-card border border-line px-3 py-2 text-sm"
                >
                    <span class="text-ink">{{ place.name }}</span>
                    <span v-if="place.category" class="ms-2 text-xs text-ink-faint">{{ place.category }}</span>
                    <span
                        v-if="place.operational_status && place.operational_status !== 'operating'"
                        class="mt-1 block text-xs text-caution"
                    >{{ t(`geography.operational.${place.operational_status}`) }}</span>
                </li>
            </ul>
        </section>
    </PublicLayout>
</template>
