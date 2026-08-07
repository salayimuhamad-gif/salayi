<script setup lang="ts">
import { ref } from 'vue';
import { Head, router } from '@inertiajs/vue3';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import AppButton from '@/Components/ui/AppButton.vue';
import AppSelect from '@/Components/ui/AppSelect.vue';
import AppInput from '@/Components/ui/AppInput.vue';
import AppEmptyState from '@/Components/ui/AppEmptyState.vue';
import { t } from '@/lib/i18n';

/*
 * Review queue for company↔developer links (spec 11.2).
 *
 * Without this the domain replaced one deadlock with another: links could be
 * created as pending and nothing could clear them.
 */
interface Link {
    id: number;
    company: string | null;
    developer: string | null;
    status: string | null;
    starts_on: string | null;
    ends_on: string | null;
    notes: string | null;
    created_at: string | null;
}

const props = defineProps<{
    links: { items: Link[]; total: number; current_page: number; last_page: number };
    filters: { status: string };
    statuses: string[];
    companies: Array<{ id: number; name: string }>;
    developers: Array<{ id: number; name: string }>;
}>();

/*
 * Create form. Without it the only way to grant a link was to know the store
 * URL, which is not a workflow.
 */
const draft = ref({ company_id: '', developer_id: '', starts_on: '', ends_on: '', notes: '' });
const creating = ref(false);
const createError = ref<string | null>(null);

function createLink(): void {
    if (draft.value.company_id === '' || draft.value.developer_id === '') {
        return;
    }

    creating.value = true;
    createError.value = null;

    router.post(`/admin/companies/${draft.value.company_id}/developers`, {
        developer_id: Number(draft.value.developer_id),
        starts_on: draft.value.starts_on || null,
        ends_on: draft.value.ends_on || null,
        notes: draft.value.notes || null,
    }, {
        preserveScroll: true,
        // Duplicate and conflict messages land here rather than as a 500.
        onError: (errors) => { createError.value = Object.values(errors)[0] ?? null; },
        onFinish: () => { creating.value = false; },
    });
}

function goToPage(page: number): void {
    router.get('/admin/company-developers', { status: props.filters.status, page }, {
        preserveState: true,
        replace: true,
    });
}

const reason = ref<Record<number, string>>({});
const busy = ref<number | null>(null);

function filterBy(status: string): void {
    router.get('/admin/company-developers', { status }, { preserveState: true, replace: true });
}

function act(id: number, action: 'approve' | 'reject' | 'revoke'): void {
    // Refusal and withdrawal need a reason: a company told only "no" cannot
    // correct anything.
    if (action !== 'approve' && !(reason.value[id] ?? '').trim()) {
        return;
    }

    busy.value = id;

    router.post(
        `/admin/company-developers/${id}/${action}`,
        action === 'approve' ? {} : { notes: reason.value[id] },
        { preserveScroll: true, onFinish: () => { busy.value = null; } },
    );
}
</script>

<template>
    <Head :title="t('companies.developer_links.title')" />

    <AdminLayout>
        <template #title>{{ t('companies.developer_links.title') }}</template>

        <p class="mb-4 max-w-2xl text-sm text-ink-muted">
            {{ t('companies.developer_links.intro') }}
        </p>

        <div class="mb-4 max-w-xs">
            <AppSelect
                :model-value="filters.status"
                :label="t('companies.developer_links.status')"
                :options="statuses.map((status) => ({
                    value: status,
                    label: t(`companies.developer_links.statuses.${status}`),
                }))"
                @update:model-value="filterBy"
            />
        </div>

        <!-- Create / request -->
        <div class="mh-card mb-6 space-y-3 p-4">
            <p class="font-medium text-ink">{{ t('companies.developer_links.create') }}</p>

            <AppAlert v-if="createError" variant="danger" :message="createError" />

            <div class="grid gap-3 sm:grid-cols-2">
                <AppSelect
                    v-model="draft.company_id"
                    :label="t('companies.developer_links.company')"
                    :options="[
                        { value: '', label: t('companies.developer_links.company') },
                        ...companies.map((c) => ({ value: String(c.id), label: c.name })),
                    ]"
                />
                <AppSelect
                    v-model="draft.developer_id"
                    :label="t('companies.developer_links.developer')"
                    :options="[
                        { value: '', label: t('companies.developer_links.developer') },
                        ...developers.map((d) => ({ value: String(d.id), label: d.name })),
                    ]"
                />
                <AppInput
                    v-model="draft.starts_on"
                    type="date"
                    :label="t('companies.developer_links.starts_on')"
                />
                <AppInput
                    v-model="draft.ends_on"
                    type="date"
                    :label="t('companies.developer_links.ends_on')"
                />
            </div>

            <AppInput v-model="draft.notes" :label="t('companies.developer_links.notes')" />

            <AppButton
                :disabled="creating || draft.company_id === '' || draft.developer_id === ''"
                @click="createLink"
            >
                {{ t('companies.developer_links.create') }}
            </AppButton>
        </div>

        <AppEmptyState
            v-if="links.total === 0"
            :title="t('companies.developer_links.none')"
            :description="t('companies.developer_links.none_hint')"
        />

        <ul v-else class="space-y-3">
            <li v-for="link in links.items" :key="link.id" class="mh-card p-4">
                <div class="flex flex-wrap items-baseline justify-between gap-2">
                    <span class="font-medium text-ink">{{ link.company }}</span>
                    <span class="text-sm text-ink-muted">{{ link.developer }}</span>
                    <span class="text-xs text-ink-faint">
                        {{ t(`companies.developer_links.statuses.${link.status}`) }}
                    </span>
                </div>

                <p v-if="link.notes" class="mt-1 text-xs text-ink-muted">{{ link.notes }}</p>

                <div v-if="link.status === 'pending' || link.status === 'approved'" class="mt-3 space-y-2">
                    <input
                        v-if="link.status !== 'pending' || true"
                        v-model="reason[link.id]"
                        type="text"
                        class="w-full rounded-card border border-line px-3 py-1.5 text-sm"
                        :placeholder="t('companies.developer_links.reason')"
                    >

                    <div class="flex gap-2">
                        <AppButton
                            v-if="link.status === 'pending'"
                            size="sm"
                            :disabled="busy === link.id"
                            @click="act(link.id, 'approve')"
                        >
                            {{ t('companies.developer_links.approve') }}
                        </AppButton>

                        <AppButton
                            v-if="link.status === 'pending'"
                            size="sm"
                            variant="secondary"
                            :disabled="busy === link.id || !(reason[link.id] ?? '').trim()"
                            @click="act(link.id, 'reject')"
                        >
                            {{ t('companies.developer_links.reject') }}
                        </AppButton>

                        <AppButton
                            v-if="link.status === 'approved'"
                            size="sm"
                            variant="danger"
                            :disabled="busy === link.id || !(reason[link.id] ?? '').trim()"
                            @click="act(link.id, 'revoke')"
                        >
                            {{ t('companies.developer_links.revoke') }}
                        </AppButton>
                    </div>
                </div>
            </li>
        </ul>

        <!-- Pagination. A queue capped at 25 with no way forward hides work. -->
        <div v-if="links.last_page > 1" class="mt-4 flex items-center gap-2">
            <AppButton
                size="sm"
                variant="secondary"
                :disabled="links.current_page <= 1"
                @click="goToPage(links.current_page - 1)"
            >
                ←
            </AppButton>
            <span class="text-xs text-ink-muted">
                {{ t('companies.developer_links.page') }} {{ links.current_page }} / {{ links.last_page }}
            </span>
            <AppButton
                size="sm"
                variant="secondary"
                :disabled="links.current_page >= links.last_page"
                @click="goToPage(links.current_page + 1)"
            >
                →
            </AppButton>
        </div>
    </AdminLayout>
</template>
