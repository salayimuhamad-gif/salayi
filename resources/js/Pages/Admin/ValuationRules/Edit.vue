<script setup lang="ts">
import { computed, ref } from 'vue';
import { Head, Link, router, useForm, usePage } from '@inertiajs/vue3';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import AppCard from '@/Components/ui/AppCard.vue';
import AppButton from '@/Components/ui/AppButton.vue';
import AppInput from '@/Components/ui/AppInput.vue';
import AppSelect from '@/Components/ui/AppSelect.vue';
import AppAlert from '@/Components/ui/AppAlert.vue';
import { t } from '@/lib/i18n';

/*
 * The rule-set builder (Wave 6).
 *
 * Drafts are the only editable state — everything structural (scope,
 * questions, options) refuses on a published version at the server, and
 * this page mirrors that by not offering the controls. Percentages appear
 * HERE and only here: this is the authoring surface behind the configure
 * permission; the owner form never sees a number.
 */
interface NamedProject { id: number; name_ckb: string; name_ar: string | null; name_en: string | null }

interface OptionRow {
    id: number;
    key: string;
    label_ckb: string;
    label_ar: string;
    label_en: string;
    adjustment_percent: string;
    sort_order: number;
    is_active: boolean;
}

interface QuestionRow {
    id: number;
    key: string;
    question_type: string;
    label_ckb: string;
    label_ar: string;
    label_en: string;
    help_ckb: string | null;
    help_ar: string | null;
    help_en: string | null;
    sort_order: number;
    is_active: boolean;
    options: OptionRow[];
}

const props = defineProps<{
    set: {
        id: number;
        name: string;
        project_id: number | null;
        project: NamedProject | null;
        property_types: string[] | null;
        version: number;
        status: string;
        published_at: string | null;
        retired_at: string | null;
        questions: QuestionRow[];
    };
    projects: NamedProject[];
    property_types: string[];
    max_percent: string;
    warning_threshold: string;
    can: { manage: boolean };
}>();

const page = usePage();

const isDraft = computed(() => props.set.status === 'draft');
const editable = computed(() => isDraft.value && props.can.manage);
const base = `/admin/portfolio/valuation-rules/${props.set.id}`;

const locale = document.documentElement.lang || 'ckb';

function projectName(project: NamedProject | null): string {
    if (!project) return t('portfolio.valuation_rules.scope_all_projects');

    return (locale === 'ar' ? project.name_ar : locale === 'en' ? project.name_en : project.name_ckb)
        ?? project.name_ckb;
}

// The page's locale rule, applied to a trilingual rule row (labels are
// required in all three languages, so no fallback is needed).
function localizedLabel(row: { label_ckb: string; label_ar: string; label_en: string }): string {
    return locale === 'ar' ? row.label_ar : locale === 'en' ? row.label_en : row.label_ckb;
}

/* ------------------------------- scope ------------------------------- */

const scopeForm = useForm({
    name: props.set.name,
    project_id: props.set.project_id,
    property_types: props.set.property_types ?? [],
});

function toggleScopeType(type: string): void {
    const index = scopeForm.property_types.indexOf(type);

    if (index === -1) scopeForm.property_types.push(type);
    else scopeForm.property_types.splice(index, 1);
}

function saveScope(): void {
    scopeForm.transform((data) => ({
        ...data,
        property_types: data.property_types.length === 0 ? null : data.property_types,
    })).put(base, { preserveScroll: true });
}

/* ----------------------------- lifecycle ----------------------------- */

function publish(): void {
    if (!window.confirm(t('portfolio.valuation_rules.publish_confirm'))) return;

    router.post(`${base}/publish`, {}, { preserveScroll: true });
}

function retire(): void {
    if (!window.confirm(t('portfolio.valuation_rules.retire_confirm'))) return;

    router.post(`${base}/retire`, {}, { preserveScroll: true });
}

function duplicate(): void {
    router.post(`${base}/duplicate`, {}, { preserveScroll: true });
}

function destroySet(): void {
    if (!window.confirm(t('portfolio.valuation_rules.delete_confirm'))) return;

    router.delete(base, { preserveScroll: true });
}

/* ----------------------------- questions ----------------------------- */

const questionForm = useForm({
    id: null as number | null,
    key: '',
    label_ckb: '',
    label_ar: '',
    label_en: '',
    help_ckb: '',
    help_ar: '',
    help_en: '',
    sort_order: 0,
    is_active: true,
});

const questionEditorOpen = ref(false);

function openQuestionEditor(question: QuestionRow | null): void {
    questionForm.clearErrors();
    questionForm.id = question?.id ?? null;
    questionForm.key = question?.key ?? '';
    questionForm.label_ckb = question?.label_ckb ?? '';
    questionForm.label_ar = question?.label_ar ?? '';
    questionForm.label_en = question?.label_en ?? '';
    questionForm.help_ckb = question?.help_ckb ?? '';
    questionForm.help_ar = question?.help_ar ?? '';
    questionForm.help_en = question?.help_en ?? '';
    questionForm.sort_order = question?.sort_order ?? props.set.questions.length;
    questionForm.is_active = question?.is_active ?? true;
    questionEditorOpen.value = true;
}

function saveQuestion(): void {
    const options = {
        preserveScroll: true,
        onSuccess: () => { questionEditorOpen.value = false; },
    };

    questionForm.transform((data) => ({
        key: data.key,
        label_ckb: data.label_ckb,
        label_ar: data.label_ar,
        label_en: data.label_en,
        help_ckb: data.help_ckb === '' ? null : data.help_ckb,
        help_ar: data.help_ar === '' ? null : data.help_ar,
        help_en: data.help_en === '' ? null : data.help_en,
        sort_order: data.sort_order,
        is_active: data.is_active,
    }));

    if (questionForm.id === null) {
        questionForm.post(`${base}/questions`, options);
    } else {
        questionForm.put(`${base}/questions/${questionForm.id}`, options);
    }
}

function destroyQuestion(question: QuestionRow): void {
    if (!window.confirm(t('portfolio.valuation_rules.delete_confirm'))) return;

    router.delete(`${base}/questions/${question.id}`, { preserveScroll: true });
}

/* ------------------------------ options ------------------------------ */

const optionForm = useForm({
    id: null as number | null,
    question_id: null as number | null,
    key: '',
    label_ckb: '',
    label_ar: '',
    label_en: '',
    adjustment_percent: '',
    sort_order: 0,
    is_active: true,
});

function openOptionEditor(question: QuestionRow, option: OptionRow | null): void {
    optionForm.clearErrors();
    optionForm.id = option?.id ?? null;
    optionForm.question_id = question.id;
    optionForm.key = option?.key ?? '';
    optionForm.label_ckb = option?.label_ckb ?? '';
    optionForm.label_ar = option?.label_ar ?? '';
    optionForm.label_en = option?.label_en ?? '';
    optionForm.adjustment_percent = option?.adjustment_percent ?? '';
    optionForm.sort_order = option?.sort_order ?? question.options.length;
    optionForm.is_active = option?.is_active ?? true;
}

function saveOption(): void {
    const questionId = optionForm.question_id;
    const options = {
        preserveScroll: true,
        onSuccess: () => { optionForm.question_id = null; },
    };

    optionForm.transform((data) => ({
        key: data.key,
        label_ckb: data.label_ckb,
        label_ar: data.label_ar,
        label_en: data.label_en,
        adjustment_percent: data.adjustment_percent,
        sort_order: data.sort_order,
        is_active: data.is_active,
    }));

    if (optionForm.id === null) {
        optionForm.post(`${base}/questions/${questionId}/options`, options);
    } else {
        optionForm.put(`${base}/questions/${questionId}/options/${optionForm.id}`, options);
    }
}

function destroyOption(question: QuestionRow, option: OptionRow): void {
    if (!window.confirm(t('portfolio.valuation_rules.delete_confirm'))) return;

    router.delete(`${base}/questions/${question.id}/options/${option.id}`, { preserveScroll: true });
}

/* ------------------------------ preview ------------------------------ */

const previewBase = ref('100000');
const previewAnswers = ref<Record<number, string>>({});
const previewBusy = ref(false);
const previewError = ref<string | null>(null);
const previewResult = ref<{
    base: string;
    total_percent: string;
    factor: string;
    refused: boolean;
    final: string | null;
    warned: boolean;
} | null>(null);

const activeQuestions = computed(() =>
    props.set.questions
        .filter((question) => question.is_active)
        .map((question) => ({
            ...question,
            options: question.options.filter((option) => option.is_active),
        })),
);

async function runPreview(): Promise<void> {
    previewBusy.value = true;
    previewError.value = null;
    previewResult.value = null;

    const params = new URLSearchParams({ base: previewBase.value });

    for (const [questionId, optionId] of Object.entries(previewAnswers.value)) {
        if (optionId !== '') params.append(`answers[${questionId}]`, optionId);
    }

    try {
        const response = await fetch(`${base}/preview?${params.toString()}`, {
            headers: { Accept: 'application/json' },
        });
        const body = await response.json();

        if (!response.ok) {
            previewError.value = typeof body?.error === 'string' ? body.error : t('portfolio.valuation_questions.invalid');

            return;
        }

        previewResult.value = body;
    } catch {
        previewError.value = t('portfolio.valuation_questions.invalid');
    } finally {
        previewBusy.value = false;
    }
}

const lifecycleError = computed(() => (page.props.errors as Record<string, string>)?.lifecycle ?? null);
</script>

<template>
    <Head :title="`${t('portfolio.valuation_rules.title')} — ${set.name}`" />

    <AdminLayout>
        <template #title>{{ set.name }}</template>

        <Link
            href="/admin/portfolio/valuation-rules"
            class="mb-5 inline-flex min-h-11 items-center text-sm text-ink-muted transition-colors hover:text-ink
                   focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-accent"
        >
            {{ t('app.actions.back') }}
        </Link>

        <!-- ========================= lifecycle ========================== -->
        <AppCard class="mb-5">
            <div class="flex flex-wrap items-center justify-between gap-3">
                <div class="space-y-1">
                    <p class="numeral text-sm text-ink-muted" dir="ltr">
                        v{{ set.version }}
                        <template v-if="set.published_at"> · {{ set.published_at }}</template>
                        <template v-if="set.retired_at"> · {{ set.retired_at }}</template>
                    </p>
                    <span
                        class="inline-block rounded-full px-2.5 py-0.5 text-xs"
                        :class="{
                            'bg-positive/10 text-positive': set.status === 'active',
                            'bg-caution/10 text-caution': set.status === 'draft',
                            'bg-surface-sunken text-ink-muted': set.status === 'retired',
                        }"
                        data-testid="set-status"
                    >
                        {{ t(`portfolio.valuation_rules.statuses.${set.status}`) }}
                    </span>
                </div>

                <div v-if="can.manage" class="flex flex-wrap gap-2">
                    <AppButton v-if="isDraft" data-testid="publish-set" size="md" @click="publish">
                        {{ t('portfolio.valuation_rules.publish') }}
                    </AppButton>
                    <AppButton v-if="set.status === 'active'" variant="secondary" size="md" @click="retire">
                        {{ t('portfolio.valuation_rules.retire') }}
                    </AppButton>
                    <AppButton variant="secondary" size="md" @click="duplicate">
                        {{ t('portfolio.valuation_rules.duplicate') }}
                    </AppButton>
                    <AppButton v-if="!isDraft || set.questions.length === 0" variant="ghost" size="md" :disabled="set.status === 'active'" @click="destroySet">
                        {{ t('portfolio.valuation_rules.delete') }}
                    </AppButton>
                </div>
            </div>

            <AppAlert v-if="lifecycleError" variant="danger" class="mt-3" data-testid="lifecycle-error">
                {{ lifecycleError }}
            </AppAlert>

            <AppAlert v-if="!isDraft" variant="info" class="mt-3">
                {{ t('portfolio.valuation_rules.frozen_notice') }}
            </AppAlert>
        </AppCard>

        <!-- =========================== scope ============================ -->
        <AppCard :title="t('portfolio.valuation_rules.scope')" class="mb-5">
            <form v-if="editable" class="space-y-4" @submit.prevent="saveScope">
                <AppInput
                    v-model="scopeForm.name"
                    :label="t('portfolio.valuation_rules.name')"
                    :error="scopeForm.errors.name"
                    required
                />
                <AppSelect
                    v-model="scopeForm.project_id"
                    :label="t('portfolio.valuation_rules.scope_project')"
                    :options="projects.map((p) => ({ value: p.id, label: projectName(p) }))"
                    :placeholder="t('portfolio.valuation_rules.scope_all_projects')"
                    :error="scopeForm.errors.project_id"
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
                            :class="scopeForm.property_types.includes(type)
                                ? 'border-brand bg-brand text-white'
                                : 'border-line bg-surface text-ink-muted hover:bg-surface-sunken'"
                            :aria-pressed="scopeForm.property_types.includes(type)"
                            @click="toggleScopeType(type)"
                        >
                            {{ t(`portfolio.types.${type}`) }}
                        </button>
                    </div>
                    <p class="mt-1 text-xs text-ink-faint">{{ t('portfolio.valuation_rules.scope_all_types') }}</p>
                </fieldset>
                <div class="flex justify-end">
                    <AppButton type="submit" size="sm" :loading="scopeForm.processing">
                        {{ t('app.actions.save') }}
                    </AppButton>
                </div>
            </form>

            <div v-else class="space-y-1 text-sm text-ink-muted">
                <p>{{ projectName(set.project) }}</p>
                <p>
                    {{ set.property_types && set.property_types.length > 0
                        ? set.property_types.map((type) => t(`portfolio.types.${type}`)).join(' · ')
                        : t('portfolio.valuation_rules.scope_all_types') }}
                </p>
            </div>
        </AppCard>

        <!-- ========================= questions ========================== -->
        <AppCard :title="t('portfolio.valuation_rules.questions')" class="mb-5">
            <div v-if="editable" class="mb-4 flex justify-end">
                <AppButton v-if="!questionEditorOpen" data-testid="add-question" size="sm" @click="openQuestionEditor(null)">
                    {{ t('portfolio.valuation_rules.add_question') }}
                </AppButton>
            </div>

            <form
                v-if="questionEditorOpen"
                class="mb-5 space-y-4 rounded-card border border-line bg-surface-sunken p-4"
                data-testid="question-editor"
                @submit.prevent="saveQuestion"
            >
                <div class="grid gap-4 sm:grid-cols-2">
                    <AppInput
                        v-model="questionForm.key"
                        :label="t('portfolio.valuation_rules.question_key')"
                        :hint="t('portfolio.valuation_rules.key_hint')"
                        :error="questionForm.errors.key"
                        dir="ltr"
                        required
                    />
                    <AppInput
                        v-model="questionForm.sort_order"
                        type="number"
                        dir="ltr"
                        :label="t('portfolio.valuation_rules.sort_order')"
                        :error="questionForm.errors.sort_order"
                    />
                </div>
                <div class="grid gap-4 sm:grid-cols-3">
                    <AppInput v-model="questionForm.label_ckb" :label="t('portfolio.valuation_rules.label_ckb')" :error="questionForm.errors.label_ckb" required />
                    <AppInput v-model="questionForm.label_ar" :label="t('portfolio.valuation_rules.label_ar')" :error="questionForm.errors.label_ar" required />
                    <AppInput v-model="questionForm.label_en" :label="t('portfolio.valuation_rules.label_en')" :error="questionForm.errors.label_en" dir="ltr" required />
                </div>
                <div class="grid gap-4 sm:grid-cols-3">
                    <AppInput v-model="questionForm.help_ckb" :label="t('portfolio.valuation_rules.help_ckb')" :error="questionForm.errors.help_ckb" />
                    <AppInput v-model="questionForm.help_ar" :label="t('portfolio.valuation_rules.help_ar')" :error="questionForm.errors.help_ar" />
                    <AppInput v-model="questionForm.help_en" :label="t('portfolio.valuation_rules.help_en')" :error="questionForm.errors.help_en" dir="ltr" />
                </div>
                <label class="flex min-h-11 cursor-pointer items-center gap-2 text-sm text-ink-muted">
                    <input v-model="questionForm.is_active" type="checkbox" class="h-4 w-4 rounded border-line text-brand focus:ring-accent">
                    <span>{{ t('portfolio.valuation_rules.active') }}</span>
                </label>
                <div class="flex justify-end gap-2">
                    <AppButton variant="ghost" size="sm" @click="questionEditorOpen = false">
                        {{ t('app.actions.cancel') }}
                    </AppButton>
                    <AppButton type="submit" size="sm" :loading="questionForm.processing">
                        {{ t('app.actions.save') }}
                    </AppButton>
                </div>
            </form>

            <p v-if="set.questions.length === 0" class="text-sm text-ink-faint">
                {{ t('portfolio.valuation_rules.none_hint') }}
            </p>

            <div v-else class="space-y-4">
                <div
                    v-for="question in set.questions"
                    :key="question.id"
                    class="rounded-card border border-line p-4"
                    data-testid="question-card"
                >
                    <div class="flex flex-wrap items-start justify-between gap-3">
                        <div class="min-w-0">
                            <p class="text-sm font-medium text-ink">
                                {{ question.label_ckb }}
                                <span v-if="!question.is_active" class="ms-2 rounded-full bg-surface-sunken px-2 py-0.5 text-xs text-ink-muted">
                                    {{ t('portfolio.valuation_rules.inactive') }}
                                </span>
                            </p>
                            <p class="mt-0.5 font-mono text-xs text-ink-faint" dir="ltr">{{ question.key }} · {{ question.sort_order }}</p>
                            <p class="mt-0.5 text-xs text-ink-muted">{{ question.label_ar }} · {{ question.label_en }}</p>
                        </div>
                        <div v-if="editable" class="flex gap-2">
                            <AppButton variant="secondary" size="sm" @click="openQuestionEditor(question)">
                                {{ t('app.actions.edit') }}
                            </AppButton>
                            <AppButton variant="ghost" size="sm" @click="destroyQuestion(question)">
                                {{ t('app.actions.delete') }}
                            </AppButton>
                        </div>
                    </div>

                    <!-- options -->
                    <div class="mt-3 border-t border-line pt-3">
                        <div class="mb-2 flex items-center justify-between gap-2">
                            <p class="mh-microlabel">{{ t('portfolio.valuation_rules.options') }}</p>
                            <AppButton
                                v-if="editable && optionForm.question_id !== question.id"
                                variant="secondary"
                                size="sm"
                                :data-testid="`add-option-${question.key}`"
                                @click="openOptionEditor(question, null)"
                            >
                                {{ t('portfolio.valuation_rules.add_option') }}
                            </AppButton>
                        </div>

                        <p v-if="question.options.length === 0" class="text-xs text-caution">
                            {{ t('portfolio.valuation_rules.no_options') }}
                        </p>

                        <ul v-else class="divide-y divide-line">
                            <li
                                v-for="option in question.options"
                                :key="option.id"
                                class="flex flex-wrap items-center justify-between gap-2 py-2"
                            >
                                <span class="min-w-0 text-sm text-ink">
                                    {{ option.label_ckb }}
                                    <span class="text-xs text-ink-faint">· {{ option.label_ar }} · {{ option.label_en }}</span>
                                    <span v-if="!option.is_active" class="ms-1 rounded-full bg-surface-sunken px-2 py-0.5 text-xs text-ink-muted">
                                        {{ t('portfolio.valuation_rules.inactive') }}
                                    </span>
                                </span>
                                <span class="flex items-center gap-2">
                                    <span class="numeral text-sm font-medium text-ink" dir="ltr">
                                        {{ option.adjustment_percent.startsWith('-') ? option.adjustment_percent : `+${option.adjustment_percent}` }}%
                                    </span>
                                    <template v-if="editable">
                                        <AppButton variant="ghost" size="sm" @click="openOptionEditor(question, option)">
                                            {{ t('app.actions.edit') }}
                                        </AppButton>
                                        <AppButton variant="ghost" size="sm" @click="destroyOption(question, option)">
                                            {{ t('app.actions.delete') }}
                                        </AppButton>
                                    </template>
                                </span>
                            </li>
                        </ul>

                        <form
                            v-if="optionForm.question_id === question.id"
                            class="mt-3 space-y-4 rounded-card border border-line bg-surface-sunken p-4"
                            data-testid="option-editor"
                            @submit.prevent="saveOption"
                        >
                            <div class="grid gap-4 sm:grid-cols-3">
                                <AppInput
                                    v-model="optionForm.key"
                                    :label="t('portfolio.valuation_rules.question_key')"
                                    :error="optionForm.errors.key"
                                    dir="ltr"
                                    required
                                />
                                <!-- Plain text on purpose: AppInput does not
                                     forward `step`, and a number input with
                                     the default step of 1 silently refuses
                                     to submit "2.5". The server validates. -->
                                <AppInput
                                    v-model="optionForm.adjustment_percent"
                                    dir="ltr"
                                    :label="t('portfolio.valuation_rules.percent')"
                                    :hint="t('portfolio.valuation_rules.percent_hint')"
                                    :error="optionForm.errors.adjustment_percent"
                                    required
                                />
                                <AppInput
                                    v-model="optionForm.sort_order"
                                    type="number"
                                    dir="ltr"
                                    :label="t('portfolio.valuation_rules.sort_order')"
                                    :error="optionForm.errors.sort_order"
                                />
                            </div>
                            <div class="grid gap-4 sm:grid-cols-3">
                                <AppInput v-model="optionForm.label_ckb" :label="t('portfolio.valuation_rules.label_ckb')" :error="optionForm.errors.label_ckb" required />
                                <AppInput v-model="optionForm.label_ar" :label="t('portfolio.valuation_rules.label_ar')" :error="optionForm.errors.label_ar" required />
                                <AppInput v-model="optionForm.label_en" :label="t('portfolio.valuation_rules.label_en')" :error="optionForm.errors.label_en" dir="ltr" required />
                            </div>
                            <label class="flex min-h-11 cursor-pointer items-center gap-2 text-sm text-ink-muted">
                                <input v-model="optionForm.is_active" type="checkbox" class="h-4 w-4 rounded border-line text-brand focus:ring-accent">
                                <span>{{ t('portfolio.valuation_rules.active') }}</span>
                            </label>
                            <div class="flex justify-end gap-2">
                                <AppButton variant="ghost" size="sm" @click="optionForm.question_id = null">
                                    {{ t('app.actions.cancel') }}
                                </AppButton>
                                <AppButton type="submit" size="sm" :loading="optionForm.processing">
                                    {{ t('app.actions.save') }}
                                </AppButton>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        </AppCard>

        <!-- ========================== preview =========================== -->
        <AppCard v-if="can.manage" :title="t('portfolio.valuation_rules.preview')">
            <p class="mb-3 text-xs text-ink-faint">{{ t('portfolio.valuation_rules.preview_hint') }}</p>

            <div class="grid gap-4 sm:grid-cols-2">
                <AppInput
                    v-model="previewBase"
                    dir="ltr"
                    :label="t('portfolio.valuation_rules.preview_base')"
                />
                <AppSelect
                    v-for="question in activeQuestions"
                    :key="question.id"
                    v-model="previewAnswers[question.id]"
                    :label="localizedLabel(question)"
                    :options="question.options.map((option) => ({
                        value: option.id,
                        label: `${localizedLabel(option)} (${option.adjustment_percent.startsWith('-') ? option.adjustment_percent : `+${option.adjustment_percent}`}%)`,
                    }))"
                    :placeholder="t('portfolio.valuation_questions.not_answered')"
                />
            </div>

            <div class="mt-4">
                <AppButton data-testid="run-preview" size="sm" :disabled="previewBusy" @click="runPreview">
                    {{ t('portfolio.valuation_rules.preview_run') }}
                </AppButton>
            </div>

            <AppAlert v-if="previewError" variant="danger" class="mt-3">{{ previewError }}</AppAlert>

            <div v-if="previewResult" class="mt-4 space-y-1.5 rounded-card border border-line bg-surface-sunken px-4 py-3 text-sm" data-testid="preview-result">
                <p class="flex justify-between gap-3">
                    <span class="text-ink-muted">{{ t('portfolio.valuation_breakdown.baseline') }}</span>
                    <span class="numeral text-ink" dir="ltr">{{ previewResult.base }}</span>
                </p>
                <p class="flex justify-between gap-3">
                    <span class="text-ink-muted">{{ t('portfolio.valuation_breakdown.total') }}</span>
                    <span class="numeral text-ink" dir="ltr">
                        {{ previewResult.total_percent.startsWith('-') ? previewResult.total_percent : `+${previewResult.total_percent}` }}%
                    </span>
                </p>
                <AppAlert v-if="previewResult.refused" variant="danger" class="mt-2">
                    {{ t('portfolio.valuation_rules.preview_refused') }}
                </AppAlert>
                <p v-else class="flex justify-between gap-3 border-t border-line pt-1.5">
                    <span class="font-medium text-ink">{{ t('portfolio.valuation_rules.preview_final') }}</span>
                    <span class="numeral font-medium text-ink" dir="ltr">{{ previewResult.final }}</span>
                </p>
                <AppAlert v-if="previewResult.warned" variant="warning" class="mt-2">
                    {{ t('portfolio.valuation_rules.preview_warning') }}
                </AppAlert>
            </div>
        </AppCard>
    </AdminLayout>
</template>
