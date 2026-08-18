<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import PublicLayout from '@/Layouts/PublicLayout.vue';
import AppEmptyState from '@/Components/ui/AppEmptyState.vue';
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

defineProps<{ projects: { data: Row[] }; filters: { q: string } }>();
</script>

<template>
    <Head :title="t('nav.projects')" />

    <PublicLayout>
        <h1 class="mb-6 font-display text-2xl font-bold text-ink">{{ t('nav.projects') }}</h1>

        <AppEmptyState
            v-if="projects.data.length === 0"
            :title="t('projects.none_published')"
            :description="t('projects.none_published_hint')"
        />

        <div v-else class="grid gap-5 sm:grid-cols-2 lg:grid-cols-3">
            <Link
                v-for="project in projects.data"
                :key="project.slug"
                :href="localized(`/projects/${project.slug}`)"
                class="mh-card block p-5 transition-shadow hover:shadow-raised
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
