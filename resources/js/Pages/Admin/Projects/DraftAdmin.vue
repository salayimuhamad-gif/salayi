<script setup lang="ts">
import { ref } from 'vue';
import { Head, router } from '@inertiajs/vue3';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import AppButton from '@/Components/ui/AppButton.vue';
import AppSelect from '@/Components/ui/AppSelect.vue';
import AppAlert from '@/Components/ui/AppAlert.vue';
import AppEmptyState from '@/Components/ui/AppEmptyState.vue';
import { t } from '@/lib/i18n';

/*
 * Administrator draft listing and recovery (spec 12.1, 26.1).
 *
 * Drafts were invisible to everyone but their author, so a departed colleague's
 * half-entered project was unreachable and the prune command could delete it
 * with nobody having looked.
 */
interface Draft {
    id: number;
    owner: string | null;
    company: string | null;
    current_step: string;
    completed: number;
    media_count: number;
    project_id: number | null;
    submitted_at: string | null;
    last_touched_at: string | null;
    is_stale: boolean;
}

defineProps<{
    drafts: { items: Draft[]; total: number; current_page: number; last_page: number };
    filters: { state: string };
    retention_days: number;
    can_recover: boolean;
}>();

const busy = ref<number | null>(null);
const failure = ref<string | null>(null);

function filterBy(state: string): void {
    router.get('/admin/project-drafts', { state }, { preserveState: true, replace: true });
}

function recover(id: number): void {
    busy.value = id;
    failure.value = null;

    router.post(`/admin/project-drafts/${id}/recover`, {}, {
        onError: (errors) => { failure.value = Object.values(errors)[0] ?? null; },
        onFinish: () => { busy.value = null; },
    });
}

function purge(id: number): void {
    busy.value = id;
    failure.value = null;

    router.delete(`/admin/project-drafts/${id}`, {
        preserveScroll: true,
        // A failed purge means files survived; the message says so rather than
        // appearing to have worked.
        onError: (errors) => { failure.value = Object.values(errors)[0] ?? null; },
        onFinish: () => { busy.value = null; },
    });
}
</script>

<template>
    <Head :title="t('projects.wizard.creation.drafts_admin')" />

    <AdminLayout>
        <template #title>{{ t('projects.wizard.creation.drafts_admin') }}</template>

        <p class="mb-4 text-sm text-ink-muted">
            {{ t('projects.wizard.creation.retention_hint', { days: retention_days }) }}
        </p>

        <AppAlert v-if="failure" variant="danger" :message="failure" />

        <div class="mb-4 max-w-xs">
            <AppSelect
                :model-value="filters.state"
                :label="t('projects.wizard.creation.drafts_title')"
                :options="[
                    { value: 'open', label: t('projects.wizard.creation.drafts_open') },
                    { value: 'stale', label: t('projects.wizard.creation.drafts_stale') },
                    { value: 'submitted', label: t('projects.wizard.creation.drafts_submitted') },
                ]"
                @update:model-value="filterBy"
            />
        </div>

        <AppEmptyState
            v-if="drafts.total === 0"
            :title="t('projects.wizard.creation.drafts_none')"
            :description="t('projects.wizard.creation.drafts_none_hint')"
        />

        <ul v-else class="space-y-2">
            <li
                v-for="draft in drafts.items"
                :key="draft.id"
                class="mh-card p-4"
                :class="{ 'border-caution': draft.is_stale }"
            >
                <div class="flex flex-wrap items-baseline justify-between gap-2">
                    <span class="font-medium text-ink">
                        {{ draft.owner ?? '—' }}
                        <span v-if="draft.company" class="text-ink-muted">· {{ draft.company }}</span>
                    </span>
                    <span class="text-xs text-ink-faint">
                        {{ t('projects.wizard.creation.drafts_updated') }}: {{ draft.last_touched_at ?? '—' }}
                    </span>
                </div>

                <p class="mt-1 text-xs text-ink-muted">
                    {{ draft.current_step }} · {{ draft.completed }}/3 · {{ draft.media_count }}
                    <span v-if="draft.is_stale" class="text-caution">
                        · {{ t('projects.wizard.creation.drafts_stale') }}
                    </span>
                </p>

                <!-- A submitted draft is an audit record: neither recoverable
                     nor purgeable, and its project is linked instead. -->
                <div v-if="draft.submitted_at" class="mt-2 text-xs">
                    <a
                        v-if="draft.project_id"
                        class="text-accent underline"
                        :href="`/admin/projects/${draft.project_id}/edit`"
                    >{{ t('projects.wizard.creation.drafts_open_project') }}</a>
                </div>

                <div v-else-if="can_recover" class="mt-3 flex gap-2">
                    <AppButton size="sm" :disabled="busy === draft.id" @click="recover(draft.id)">
                        {{ t('projects.wizard.creation.recover') }}
                    </AppButton>
                    <AppButton
                        size="sm"
                        variant="danger"
                        :disabled="busy === draft.id"
                        @click="purge(draft.id)"
                    >
                        {{ t('app.actions.delete') }}
                    </AppButton>
                </div>
            </li>
        </ul>
    </AdminLayout>
</template>
