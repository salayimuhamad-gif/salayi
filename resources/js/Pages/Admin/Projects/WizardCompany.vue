<script setup lang="ts">
import { ref } from 'vue';
import { Head, router } from '@inertiajs/vue3';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import AppButton from '@/Components/ui/AppButton.vue';
import AppSelect from '@/Components/ui/AppSelect.vue';
import { t } from '@/lib/i18n';

/*
 * Acting-company selection (spec 11.2).
 *
 * Shown before a draft exists. A user in several companies has no correct
 * default — choosing for them scopes the draft to the wrong company, and the
 * mistake only surfaces much later when the project is attributed wrongly.
 */
const props = defineProps<{
    companies: Array<{ id: number; name: string }>;
    may_use_platform_mode: boolean;
}>();

const selected = ref<string>(props.companies[0] ? String(props.companies[0].id) : '');

function choose(): void {
    router.post('/admin/projects/wizard/company', { acting_company_id: Number(selected.value) });
}
</script>

<template>
    <Head :title="t('projects.wizard.creation.choose_company_title')" />

    <AdminLayout>
        <h1 class="font-display text-2xl font-bold text-ink">
            {{ t('projects.wizard.creation.choose_company_title') }}
        </h1>
        <p class="mt-2 max-w-xl text-sm text-ink-muted">
            {{ t('projects.wizard.creation.choose_company_hint') }}
        </p>

        <div class="mt-6 max-w-sm space-y-4">
            <AppSelect
                v-model="selected"
                :label="t('projects.wizard.creation.choose_company_title')"
                :options="companies.map((company) => ({ value: String(company.id), label: company.name }))"
            />

            <AppButton :disabled="selected === ''" @click="choose">
                {{ t('projects.wizard.creation.continue') }}
            </AppButton>

            <!-- Offered only to an explicitly authorised platform operator.
                 Absence of a company membership is not authorisation. -->
            <p v-if="may_use_platform_mode" class="text-xs text-ink-faint">
                {{ t('projects.wizard.creation.platform_mode') }}
            </p>
        </div>
    </AdminLayout>
</template>
