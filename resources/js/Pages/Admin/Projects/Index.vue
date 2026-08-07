<script setup lang="ts">
import { ref, watch } from 'vue';
import { Head, Link, router } from '@inertiajs/vue3';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import AppButton from '@/Components/ui/AppButton.vue';
import AppEmptyState from '@/Components/ui/AppEmptyState.vue';
import { t } from '@/lib/i18n';
import AppPagination from '@/Components/ui/AppPagination.vue';

interface Row {
    id: number;
    name: string;
    slug: string;
    status: string;
    type: string | null;
    area: string | null;
    has_geometry: boolean;
    missing_translations: string[];
    updated_at: string | null;
}

const props = defineProps<{
    projects: { data: Row[]; links: Array<{ url: string | null; label: string; active: boolean }> };
    filters: { q: string; status: string };
    statuses: string[];
    can: { create_scoped: boolean; create_unscoped: boolean; use_wizard: boolean };
}>();

const search = ref(props.filters.q);
const status = ref(props.filters.status);

let timer: ReturnType<typeof setTimeout> | undefined;

watch([search, status], () => {
    clearTimeout(timer);
    timer = setTimeout(() => {
        router.get('/admin/projects', { q: search.value, status: status.value }, {
            preserveState: true, replace: true,
        });
    }, 300);
});

const tone = (s: string): string => ({
    published: 'bg-positive/10 text-positive',
    draft: 'bg-surface-sunken text-ink-muted',
    in_review: 'bg-caution/10 text-caution',
    unpublished: 'bg-negative/10 text-negative',
    archived: 'bg-surface-sunken text-ink-faint',
}[s] ?? 'bg-surface-sunken text-ink-muted');
</script>

<template>
    <Head :title="t('nav.projects')" />

    <AdminLayout>
        <template #title>{{ t('nav.projects') }}</template>

        <div class="mb-6 flex flex-wrap items-end gap-3">
            <div class="min-w-0 flex-1">
                <label for="q" class="mh-label mb-1 block">{{ t('app.actions.search') }}</label>
                <input
                    id="q"
                    v-model="search"
                    type="search"
                    class="w-full rounded-card border border-line bg-surface-raised px-3 py-2 text-ink
                           focus:border-brand focus:outline-none focus:ring-2 focus:ring-accent"
                    :placeholder="t('projects.search_placeholder')"
                >
            </div>

            <div>
                <label for="status" class="mh-label mb-1 block">{{ t('projects.publication.status') }}</label>
                <select
                    id="status"
                    v-model="status"
                    class="rounded-card border border-line bg-surface-raised px-3 py-2 text-ink
                           focus:border-brand focus:outline-none focus:ring-2 focus:ring-accent"
                >
                    <option value="">{{ t('app.actions.filter') }}</option>
                    <option v-for="s in statuses" :key="s" :value="s">
                        {{ t(`projects.publication_statuses.${s}`) }}
                    </option>
                </select>
            </div>

            <!-- The wizard is the guided path; the single form stays as the
                 advanced alternative for somebody entering their fiftieth
                 project who does not want six screens. Both are permission-
                 and flag-aware: the button is hidden when either says no, and
                 the route enforces both regardless. -->
            <!-- Wizard for anyone who may create within a company. -->
            <Link v-if="can.use_wizard" href="/admin/projects/wizard">
                <AppButton>{{ t('projects.wizard.creation.wizard_button') }}</AppButton>
            </Link>

            <!-- The legacy single form writes no company association, so it
                 is platform-only. Showing it to a company user would offer a
                 button the server always refuses. -->
            <Link v-if="can.create_unscoped" href="/admin/projects/create">
                <AppButton variant="secondary">{{ t('projects.create') }}</AppButton>
            </Link>
        </div>

        <AppEmptyState
            v-if="projects.data.length === 0"
            :title="t('projects.empty')"
            :description="t('projects.empty_hint')"
        >
            <template v-if="can.create_unscoped || can.use_wizard" #action>
                <Link v-if="can.use_wizard" href="/admin/projects/wizard">
                    <AppButton>{{ t('projects.wizard.creation.wizard_button') }}</AppButton>
                </Link>
                <Link v-else-if="can.create_unscoped" href="/admin/projects/create">
                    <AppButton>{{ t('projects.create') }}</AppButton>
                </Link>
            </template>
        </AppEmptyState>

        <div v-else class="mh-card overflow-hidden">
            <ul class="divide-y divide-line">
                <li v-for="project in projects.data" :key="project.id">
                    <Link
                        :href="`/admin/projects/${project.id}/edit`"
                        class="flex items-center gap-4 px-5 py-4 transition-colors hover:bg-surface-sunken
                               focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-accent"
                    >
                        <div class="min-w-0 flex-1">
                            <p class="truncate font-medium text-ink">{{ project.name }}</p>
                            <p class="mt-0.5 truncate text-xs text-ink-muted">
                                {{ project.area ?? t('projects.blockers.area_missing') }}
                            </p>
                        </div>

                        <!-- Data-quality signals in the list, so a gap is
                             visible without opening every record. -->
                        <div class="flex shrink-0 items-center gap-2">
                            <span
                                v-if="!project.has_geometry"
                                class="rounded-full bg-caution/10 px-2 py-0.5 text-xs text-caution"
                                :title="t('projects.blockers.geometry_missing')"
                            >{{ t('projects.badge.no_location') }}</span>

                            <span
                                v-if="project.missing_translations.length > 0"
                                class="rounded-full bg-caution/10 px-2 py-0.5 text-xs text-caution"
                            >{{ project.missing_translations.join(' / ') }}</span>

                            <span :class="['rounded-full px-2.5 py-0.5 text-xs', tone(project.status)]">
                                {{ t(`projects.publication_statuses.${project.status}`) }}
                            </span>
                        </div>
                    </Link>
                </li>
            </ul>
        </div>

        <AppPagination :links="projects.links" spa />
    </AdminLayout>
</template>
