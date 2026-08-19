<script setup lang="ts">
import { computed, ref } from 'vue';
import { router, usePage } from '@inertiajs/vue3';
import AppSelect from '@/Components/ui/AppSelect.vue';
import AppAlert from '@/Components/ui/AppAlert.vue';
import { t } from '@/lib/i18n';
import type { SharedPageProps } from '@/Types/inertia';

/*
 * The acting-company switcher, rendered in the admin chrome.
 *
 * Every project surface is scoped by this choice, so it has to be visible and
 * changeable from all of them. It previously existed only inside the Wizard,
 * which meant a multi-company user landing on the project index saw an empty
 * list with nothing explaining why.
 *
 * The control is hidden entirely for single-company and platform users: a
 * select with one option is a question with one answer.
 */
const page = usePage<SharedPageProps>();

const acting = computed(() => page.props.acting_company);

/*
 * Switching is a server round trip, so the control reports its own state.
 * Without this a slow connection looks like a select that ignored the click.
 */
const switching = ref(false);
const failed = ref(false);
/*
 * Shown whenever there is a genuine choice: several companies, or one company
 * plus platform mode. A select with one option is a question with one answer.
 */
const visible = computed(() => {
    const options = acting.value?.options.length ?? 0;

    return options > 1 || (options >= 1 && (acting.value?.may_use_platform ?? false));
});

function switchTo(value: string): void {
    if (value === '' || switching.value) {
        return;
    }

    switching.value = true;
    failed.value = false;

    router.post('/admin/acting-company', {
        acting_company_id: value === 'platform' ? 'platform' : Number(value),
    }, {
        preserveScroll: true,
        // A refused switch must be visible. Silently reverting leaves somebody
        // believing they are acting as a company they are not.
        onError: () => { failed.value = true; },
        onFinish: () => { switching.value = false; },
    });
}
</script>

<template>
    <div v-if="visible" class="space-y-2">
        <!-- Stated, not implied. A blank project list with no explanation is
             indistinguishable from a broken one. -->
        <AppAlert
            v-if="acting?.must_choose"
            variant="info"
            :message="t('projects.wizard.creation.choose_company_hint')"
        />

        <AppAlert
            v-if="failed"
            variant="danger"
            :message="t('projects.wizard.creation.error_company_scope')"
        />

        <p v-if="switching" class="text-xs text-ink-faint">
            {{ t('projects.wizard.creation.saving') }}
        </p>

        <AppSelect
            :model-value="acting?.mode === 'platform'
                ? 'platform'
                : (acting?.current_id ? String(acting.current_id) : '')"
            :label="t('projects.wizard.creation.choose_company_title')"
            :options="[
                { value: '', label: t('projects.wizard.creation.choose_company_title') },
                ...(acting?.may_use_platform
                    ? [{ value: 'platform', label: t('projects.wizard.creation.platform_mode') }]
                    : []),
                ...(acting?.options ?? []).map((company) => ({
                    value: String(company.id),
                    label: company.name,
                })),
            ]"
            @update:model-value="switchTo"
        />
    </div>
</template>
