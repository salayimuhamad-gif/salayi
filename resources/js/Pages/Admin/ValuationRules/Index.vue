<script setup lang="ts">
import { ref } from 'vue';
import { Head, Link, useForm } from '@inertiajs/vue3';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import AppCard from '@/Components/ui/AppCard.vue';
import AppButton from '@/Components/ui/AppButton.vue';
import AppInput from '@/Components/ui/AppInput.vue';
import AppSelect from '@/Components/ui/AppSelect.vue';
import AppEmptyState from '@/Components/ui/AppEmptyState.vue';
import { t } from '@/lib/i18n';

/*
 * The rule-set list (Wave 6): every version of every scope family with its
 * lifecycle state, and a create form for a fresh draft. All content work
 * happens on the Edit page — this list only says what exists and where it
 * stands.
 */
interface NamedProject { id: number; name_ckb: string; name_ar: string | null; name_en: string | null }

interface RuleSet {
    id: number;
    name: string;
    project: NamedProject | null;
    property_types: string[] | null;
    version: number;
    status: string;
    published_at: string | null;
    retired_at: string | null;
    question_count: number;
    created_at: string | null;
}

const props = defineProps<{
    sets: RuleSet[];
    projects: NamedProject[];
    property_types: string[];
    can: { manage: boolean };
}>();

const creating = ref(false);

const form = useForm({
    name: '',
    project_id: null as number | null,
    property_types: [] as string[],
});

const locale = document.documentElement.lang || 'ckb';

function projectName(project: NamedProject | null): string {
    if (!project) return t('portfolio.valuation_rules.scope_all_projects');

    return (locale === 'ar' ? project.name_ar : locale === 'en' ? project.name_en : project.name_ckb)
        ?? project.name_ckb;
}

function typesLabel(types: string[] | null): string {
    if (!types || types.length === 0) return t('portfolio.valuation_rules.scope_all_types');

    return types.map((type) => t(`portfolio.types.${type}`)).join(' · ');
}

function toggleType(type: string): void {
    const index = form.property_types.indexOf(type);

    if (index === -1) form.property_types.push(type);
    else form.property_types.splice(index, 1);
}

function create(): void {
    form.transform((data) => ({
        ...data,
        // An empty selection means "every type" — sent as null, the same
        // value the server stores for it.
        property_types: data.property_types.length === 0 ? null : data.property_types,
    })).post('/admin/portfolio/valuation-rules', {
        preserveScroll: true,
        onSuccess: () => { form.reset(); creating.value = false; },
    });
}

const statusTone = (status: string): string => ({
    active: 'bg-positive/10 text-positive',
    draft: 'bg-caution/10 text-caution',
    retired: 'bg-surface-sunken text-ink-muted',
}[status] ?? 'bg-surface-sunken text-ink-muted');
</script>

<template>
    <Head :title="t('portfolio.valuation_rules.title')" />

    <AdminLayout>
        <template #title>{{ t('portfolio.valuation_rules.title') }}</template>

        <div v-if="can.manage" class="mb-6 flex justify-end">
            <AppButton v-if="!creating" data-testid="create-rule-set" @click="creating = true">
                {{ t('portfolio.valuation_rules.create') }}
            </AppButton>
        </div>

        <AppCard v-if="creating" :title="t('portfolio.valuation_rules.create')" class="mb-6">
            <form class="space-y-5" @submit.prevent="create">
                <AppInput
                    v-model="form.name"
                    :label="t('portfolio.valuation_rules.name')"
                    :error="form.errors.name"
                    required
                />

                <AppSelect
                    v-model="form.project_id"
                    :label="t('portfolio.valuation_rules.scope_project')"
                    :options="projects.map((p) => ({ value: p.id, label: projectName(p) }))"
                    :placeholder="t('portfolio.valuation_rules.scope_all_projects')"
                    :error="form.errors.project_id"
                />

                <fieldset>
                    <legend class="mb-2 block text-sm font-medium text-ink">
                        {{ t('portfolio.valuation_rules.scope_types') }}
                    </legend>
                    <div class="flex flex-wrap gap-2">
                        <button
                            v-for="type in property_types"
                            :key="type"
                            type="button"
                            class="min-h-11 rounded-card border px-4 text-sm transition-colors
                                   focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-accent"
                            :class="form.property_types.includes(type)
                                ? 'border-brand bg-brand text-white'
                                : 'border-line bg-surface text-ink-muted hover:bg-surface-sunken'"
                            :aria-pressed="form.property_types.includes(type)"
                            @click="toggleType(type)"
                        >
                            {{ t(`portfolio.types.${type}`) }}
                        </button>
                    </div>
                    <p class="mt-1 text-xs text-ink-faint">{{ t('portfolio.valuation_rules.scope_all_types') }}</p>
                    <p v-if="form.errors.property_types" class="mt-1 text-sm text-negative">
                        {{ form.errors.property_types }}
                    </p>
                </fieldset>

                <div class="flex justify-end gap-2">
                    <AppButton variant="ghost" size="sm" @click="creating = false">
                        {{ t('app.actions.cancel') }}
                    </AppButton>
                    <AppButton type="submit" size="sm" :loading="form.processing">
                        {{ t('app.actions.save') }}
                    </AppButton>
                </div>
            </form>
        </AppCard>

        <AppEmptyState
            v-if="sets.length === 0"
            :title="t('portfolio.valuation_rules.none')"
            :description="t('portfolio.valuation_rules.none_hint')"
        />

        <div v-else class="space-y-4">
            <AppCard v-for="set in sets" :key="set.id" data-testid="rule-set-card">
                <div class="flex flex-wrap items-start justify-between gap-4">
                    <div class="min-w-0 flex-1 space-y-1.5">
                        <Link
                            :href="`/admin/portfolio/valuation-rules/${set.id}`"
                            class="inline-flex min-h-11 items-center font-display text-base font-semibold text-ink
                                   underline-offset-2 hover:underline focus-visible:outline-none
                                   focus-visible:ring-2 focus-visible:ring-accent"
                        >
                            {{ set.name }}
                        </Link>

                        <p class="text-sm text-ink-muted">
                            {{ projectName(set.project) }} · {{ typesLabel(set.property_types) }}
                        </p>

                        <p class="numeral text-xs text-ink-faint" dir="ltr">
                            v{{ set.version }}
                            · {{ set.question_count }} {{ t('portfolio.valuation_rules.questions') }}
                            <template v-if="set.published_at"> · {{ set.published_at }}</template>
                        </p>
                    </div>

                    <span :class="['rounded-full px-2.5 py-0.5 text-xs', statusTone(set.status)]">
                        {{ t(`portfolio.valuation_rules.statuses.${set.status}`) }}
                    </span>
                </div>
            </AppCard>
        </div>
    </AdminLayout>
</template>
