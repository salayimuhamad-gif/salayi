<script setup lang="ts">
import { ref, watch } from 'vue';
import { Head, Link, router } from '@inertiajs/vue3';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import AppButton from '@/Components/ui/AppButton.vue';
import AppEmptyState from '@/Components/ui/AppEmptyState.vue';
import { t } from '@/lib/i18n';

interface Row {
    id: number;
    name: string;
    legal_name: string;
    verification_status: string;
    status: string;
    branches: number;
    associations: number;
    has_licence: boolean;
    licence_expired: boolean;
    missing_translations: string[];
}

const props = defineProps<{
    companies: { data: Row[] };
    filters: { q: string; verification: string };
    can: { create: boolean; verify: boolean };
}>();

const filters = ref({ ...props.filters });
let timer: ReturnType<typeof setTimeout> | undefined;

watch(filters, () => {
    clearTimeout(timer);
    timer = setTimeout(() => {
        router.get('/admin/companies', filters.value, { preserveState: true, replace: true });
    }, 300);
}, { deep: true });

const tone = (status: string): string => ({
    verified: 'bg-positive/10 text-positive',
    pending: 'bg-caution/10 text-caution',
    rejected: 'bg-negative/10 text-negative',
    suspended: 'bg-negative/10 text-negative',
}[status] ?? 'bg-surface-sunken text-ink-muted');
</script>

<template>
    <Head :title="t('nav.companies')" />

    <AdminLayout>
        <template #title>{{ t('nav.companies') }}</template>

        <div class="mb-6 flex flex-wrap items-end gap-3">
            <div class="min-w-0 flex-1">
                <label for="q" class="mh-label mb-1 block">{{ t('app.actions.search') }}</label>
                <input
                    id="q" v-model="filters.q" type="search"
                    class="w-full rounded-card border border-line bg-surface-raised px-3 py-2 text-ink
                           focus:border-brand focus:outline-none focus:ring-2 focus:ring-accent"
                >
            </div>

            <div>
                <label for="v" class="mh-label mb-1 block">{{ t('companies.verification.title') }}</label>
                <select
                    id="v" v-model="filters.verification"
                    class="rounded-card border border-line bg-surface-raised px-3 py-2 text-ink
                           focus:border-brand focus:outline-none focus:ring-2 focus:ring-accent"
                >
                    <option value="">{{ t('app.actions.filter') }}</option>
                    <option value="pending">{{ t('companies.verification.pending') }}</option>
                    <option value="verified">{{ t('companies.verification.verified') }}</option>
                    <option value="rejected">{{ t('companies.verification.rejected') }}</option>
                    <option value="suspended">{{ t('companies.verification.suspended') }}</option>
                </select>
            </div>

            <Link v-if="can.create" href="/admin/companies/create">
                <AppButton>{{ t('companies.create') }}</AppButton>
            </Link>
        </div>

        <AppEmptyState
            v-if="companies.data.length === 0"
            :title="t('companies.empty')"
            :description="t('companies.empty_hint')"
        >
            <template v-if="can.create" #action>
                <Link href="/admin/companies/create">
                    <AppButton>{{ t('companies.create') }}</AppButton>
                </Link>
            </template>
        </AppEmptyState>

        <div v-else class="mh-card overflow-hidden">
            <ul class="divide-y divide-line">
                <li v-for="company in companies.data" :key="company.id">
                    <Link
                        :href="`/admin/companies/${company.id}/edit`"
                        class="flex items-center gap-4 px-5 py-4 transition-colors hover:bg-surface-sunken
                                 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-accent"
                    >
                        <div class="min-w-0 flex-1">
                            <p class="truncate font-medium text-ink">{{ company.name }}</p>
                            <p class="numeral mt-0.5 truncate text-xs text-ink-muted">
                                {{ company.legal_name }}
                                · {{ company.associations }} {{ t('companies.associations') }}
                            </p>
                        </div>

                        <div class="flex shrink-0 items-center gap-2">
                            <!-- An expired licence is not evidence, so it is
                                 surfaced next to the verification badge rather
                                 than buried in the record. -->
                            <span
                                v-if="company.licence_expired"
                                class="rounded-full bg-negative/10 px-2 py-0.5 text-xs text-negative"
                            >
                                {{ t('companies.licence_expired') }}
                            </span>

                            <span
                                v-if="company.missing_translations.length > 0"
                                class="rounded-full bg-caution/10 px-2 py-0.5 text-xs text-caution"
                            >
                                {{ company.missing_translations.join(' / ') }}
                            </span>

                            <span :class="['rounded-full px-2.5 py-0.5 text-xs', tone(company.verification_status)]">
                                {{ t(`companies.verification.${company.verification_status}`) }}
                            </span>
                        </div>
                    </Link>
                </li>
            </ul>
        </div>
    </AdminLayout>
</template>
