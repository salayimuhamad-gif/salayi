<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import PublicLayout from '@/Layouts/PublicLayout.vue';
import AppEmptyState from '@/Components/ui/AppEmptyState.vue';
import AppAlert from '@/Components/ui/AppAlert.vue';
import { t, formatNumber } from '@/lib/i18n';
import { useLocale } from '@/Composables/useLocale';

/*
 * The public area directory (spec 10.2).
 *
 * Grouped by level rather than rendered as an expandable tree. Erbil's
 * hierarchy runs seven levels deep, and somebody looking for Ankawa wants to
 * read a list, not open four districts to reach it.
 */
const { localized } = useLocale();

interface AreaRow {
    slug: string;
    name: string;
    name_is_fallback: boolean;
    project_count: number;
}

interface Group {
    type: string;
    label: string;
    areas: AreaRow[];
}

defineProps<{
    groups: Group[];
    total: number;
    truncated: boolean;
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
        <h1 class="font-display text-2xl font-bold text-ink">{{ t('geography.public.index_title') }}</h1>
        <p class="mt-2 max-w-2xl text-sm text-ink-muted">{{ t('geography.public.index_intro') }}</p>

        <!--
            Empty is the correct state on a fresh installation, and it says so
            rather than rendering a plausible-looking skeleton. §4: no invented
            or demo data.
        -->
        <AppEmptyState
            v-if="total === 0"
            class="mt-8"
            :title="t('geography.public.none')"
            :description="t('geography.public.none_hint')"
        />

        <template v-else>
            <p class="mt-4 text-sm text-ink-faint">
                <span class="numeral" dir="ltr">{{ formatNumber(total) }}</span>
                {{ t('geography.public.area_count', { count: total }) }}
            </p>

            <!--
                A truncated list must say so. Silently capping at 200 and
                letting a visitor conclude the 201st area does not exist is
                the kind of quiet lie the empty states exist to avoid.
            -->
            <AppAlert
                v-if="truncated"
                class="mt-4"
                variant="info"
                :message="t('geography.public.truncated', { count: 200 })"
            />

            <section v-for="group in groups" :key="group.type" class="mt-8">
                <h2 class="mb-3 font-display text-lg font-semibold text-ink">{{ group.label }}</h2>

                <ul class="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                    <li v-for="area in group.areas" :key="area.slug">
                        <Link
                            :href="localized(`/areas/${area.slug}`)"
                            class="mh-card flex items-center justify-between gap-3 p-4 transition-shadow
                                   hover:shadow-raised focus-visible:outline-none focus-visible:ring-2
                                   focus-visible:ring-accent"
                        >
                            <span>
                                <span class="font-medium text-ink">{{ area.name }}</span>
                                <!--
                                    A name shown in a language the visitor did
                                    not ask for is flagged, not passed off as a
                                    translation. Same principle as the admin
                                    data-quality view: the backlog stays
                                    visible.
                                -->
                                <span
                                    v-if="area.name_is_fallback"
                                    class="ms-2 text-xs text-ink-faint"
                                    :title="t('geography.public.name_fallback_notice')"
                                >&#8258;</span>
                            </span>

                            <span class="numeral shrink-0 text-sm text-ink-faint" dir="ltr">
                                {{ formatNumber(area.project_count) }}
                            </span>
                        </Link>
                    </li>
                </ul>
            </section>
        </template>
    </PublicLayout>
</template>
