<script setup lang="ts">
import { computed } from 'vue';
import { useForm } from '@inertiajs/vue3';
import AppAlert from '@/Components/ui/AppAlert.vue';
import AppButton from '@/Components/ui/AppButton.vue';
import AppInput from '@/Components/ui/AppInput.vue';
import { t } from '@/lib/i18n';
import { useLocale } from '@/Composables/useLocale';

const { localized } = useLocale();

/*
 * One form for adding and editing a portfolio property (spec §11) — the same
 * fields, the same validation surface, POSTing or PUTting depending on
 * whether an id was handed in.
 *
 * The consent line does double duty: it is the valuation opt-in the
 * controller requires, and its wording is the honest framing — an estimate
 * from published market evidence, not an appraisal.
 */
interface NamedOption { id: number; name_ckb: string; name_ar: string | null; name_en: string | null }

/*
 * Wave 6: the applicable valuation questions, resolved SERVER-side for this
 * property's scope. Options deliberately carry no percentage — the browser
 * offers choices and submits ids; every number stays on the server. The
 * whole prop is null while the feature flag is off, so the form cannot even
 * hint at the surface.
 */
interface RuleOption { id: number; key: string; label_ckb: string; label_ar: string; label_en: string }
interface RuleQuestion {
    id: number;
    key: string;
    question_type: string;
    label_ckb: string;
    label_ar: string;
    label_en: string;
    help_ckb: string | null;
    help_ar: string | null;
    help_en: string | null;
    options: RuleOption[];
}

const props = defineProps<{
    projects: NamedOption[];
    areas: NamedOption[];
    valuationRules?: {
        questions: RuleQuestion[];
        answers: Record<number, number>;
        stale: number;
    } | null;
    property?: {
        id: number;
        label: string | null;
        property_type: string;
        project_id: number | null;
        area_id: number | null;
        unit_type: string | null;
        size_sqm: string | null;
        rooms: number | null;
        floor: number | null;
        purchase_price: string | null;
        purchase_date: string | null;
        ownership_percent: string | null;
        occupancy_status: string | null;
        currency: string;
        consent_valuation: boolean;
        has_location: boolean;
        location_precision: string;
    } | null;
}>();

const emit = defineEmits<{ saved: [] }>();

/*
 * H9: the vocabularies mirror PortfolioProperty's constants — the server is
 * the authority; these lists exist so the form can only OFFER what the
 * server will accept. A legacy free-text unit type (rows saved before the
 * allowlist) preselects as "not specified" rather than as an option the
 * server would refuse on resubmit.
 */
const propertyTypes = ['apartment', 'villa', 'house', 'land', 'commercial'];
const unitTypes = [
    'studio', 'one_bedroom', 'two_bedroom', 'three_bedroom',
    'four_plus_bedroom', 'duplex', 'penthouse', 'shop', 'office', 'other',
];
const currencies = ['USD', 'IQD'];
const occupancyOptions = ['owner_occupied', 'rented', 'vacant'];

/*
 * Rehydration without defaults: every applicable question starts at the
 * owner's PERSISTED valid answer, or at "not answered". A stale stored
 * answer never preselects anything — the notice below explains why.
 */
const initialAnswers: Record<number, number | null> = {};

for (const question of props.valuationRules?.questions ?? []) {
    initialAnswers[question.id] = props.valuationRules?.answers?.[question.id] ?? null;
}

const form = useForm({
    label: props.property?.label ?? '',
    property_type: props.property?.property_type ?? 'apartment',
    project_id: props.property?.project_id ?? null,
    area_id: props.property?.area_id ?? null,
    unit_type: unitTypes.includes(props.property?.unit_type ?? '') ? props.property!.unit_type : null,
    size_sqm: props.property?.size_sqm ?? '',
    rooms: props.property?.rooms ?? null,
    floor: props.property?.floor ?? null,
    purchase_price: props.property?.purchase_price ?? '',
    purchase_date: props.property?.purchase_date ?? '',
    ownership_percent: props.property?.ownership_percent ?? '',
    occupancy_status: props.property?.occupancy_status ?? null,
    currency: props.property?.currency ?? 'USD',
    consent_valuation: props.property?.consent_valuation ?? false,
    /*
     * §9.3: stored coordinates never come back from the server, so these
     * start blank even when a pin is saved — blank means "keep what is
     * saved", and every change (new pair, new precision, removal) is an
     * explicit act.
     */
    latitude: '',
    longitude: '',
    location_precision:
        props.property?.has_location && ['exact', 'approximate'].includes(props.property.location_precision)
            ? props.property.location_precision
            : 'exact',
    remove_location: false,
    notes: '',
    answers: initialAnswers,
});

const locale = document.documentElement.lang || 'ckb';

function optionName(option: NamedOption): string {
    return (locale === 'ar' ? option.name_ar : locale === 'en' ? option.name_en : option.name_ckb)
        ?? option.name_ckb;
}

/** The stored trilingual label in the page's language, Sorani as the floor. */
function ruleLabel(row: { label_ckb: string; label_ar: string; label_en: string }): string {
    return locale === 'ar' ? row.label_ar : locale === 'en' ? row.label_en : row.label_ckb;
}

function ruleHelp(question: RuleQuestion): string | null {
    return (locale === 'ar' ? question.help_ar : locale === 'en' ? question.help_en : question.help_ckb)
        ?? question.help_ckb;
}

const editing = computed(() => props.property != null);

/** A pair is being typed — precision becomes relevant the moment either half exists. */
const coordinateEntered = computed(() => form.latitude !== '' || form.longitude !== '');

/** The saved pin is being kept as-is (edit mode, pin exists, no removal requested). */
const keepingSavedPin = computed(
    () => editing.value && props.property!.has_location && !form.remove_location,
);

function submit(): void {
    form.transform((data) => {
        /*
         * Empty string is what an untouched number input holds; the server's
         * `numeric` rule would refuse it, and the honest value is null —
         * "no coordinate given". `remove_location` only exists as a concept
         * on edit, so create strips it.
         */
        const payload: Record<string, unknown> = {
            ...data,
            latitude: data.latitude === '' ? null : data.latitude,
            longitude: data.longitude === '' ? null : data.longitude,
        };

        if (!editing.value) {
            delete payload.remove_location;
        }

        /*
         * Answers travel only when the server offered questions: a form
         * that never showed the section must not submit the key at all —
         * that absence is what keeps flag-off requests byte-identical.
         */
        if (!props.valuationRules || props.valuationRules.questions.length === 0) {
            delete payload.answers;
        }

        return payload;
    });

    const options = {
        preserveScroll: true,
        onSuccess: () => {
            if (!editing.value) form.reset();
            emit('saved');
        },
    };

    if (editing.value) {
        form.put(localized(`/account/portfolio/${props.property!.id}`), options);
    } else {
        form.post(localized('/account/portfolio'), options);
    }
}
</script>

<template>
    <form class="space-y-4" @submit.prevent="submit">
        <AppInput
            v-model="form.label"
            :label="t('portfolio.form.label')"
            :hint="t('portfolio.form.label_hint')"
            required
            :error="form.errors.label"
        />

        <div class="grid gap-4 sm:grid-cols-2">
            <div>
                <label :for="`pf-type-${property?.id ?? 'new'}`" class="mb-1.5 block text-sm font-medium text-ink">
                    {{ t('portfolio.form.property_type') }}
                </label>
                <select
                    :id="`pf-type-${property?.id ?? 'new'}`"
                    v-model="form.property_type"
                    class="block min-h-11 w-full rounded-card border border-line bg-surface px-3 text-sm text-ink
                           focus:border-accent focus:outline-none focus:ring-2 focus:ring-accent/40"
                >
                    <option v-for="type in propertyTypes" :key="type" :value="type">
                        {{ t(`portfolio.types.${type}`) }}
                    </option>
                </select>
            </div>

            <div>
                <label :for="`pf-unit-${property?.id ?? 'new'}`" class="mb-1.5 block text-sm font-medium text-ink">
                    {{ t('portfolio.form.unit_type') }}
                </label>
                <select
                    :id="`pf-unit-${property?.id ?? 'new'}`"
                    v-model="form.unit_type"
                    class="block min-h-11 w-full rounded-card border border-line bg-surface px-3 text-sm text-ink
                           focus:border-accent focus:outline-none focus:ring-2 focus:ring-accent/40"
                >
                    <option :value="null">{{ t('portfolio.form.unit_none') }}</option>
                    <option v-for="unit in unitTypes" :key="unit" :value="unit">
                        {{ t(`portfolio.unit_types.${unit}`) }}
                    </option>
                </select>
                <p v-if="form.errors.unit_type" class="mt-1 text-sm text-negative">
                    {{ form.errors.unit_type }}
                </p>
            </div>

            <div>
                <label :for="`pf-project-${property?.id ?? 'new'}`" class="mb-1.5 block text-sm font-medium text-ink">
                    {{ t('portfolio.form.project') }}
                </label>
                <select
                    :id="`pf-project-${property?.id ?? 'new'}`"
                    v-model="form.project_id"
                    class="block min-h-11 w-full rounded-card border border-line bg-surface px-3 text-sm text-ink
                           focus:border-accent focus:outline-none focus:ring-2 focus:ring-accent/40"
                >
                    <option :value="null">{{ t('portfolio.form.external') }}</option>
                    <option v-for="project in projects" :key="project.id" :value="project.id">
                        {{ optionName(project) }}
                    </option>
                </select>
            </div>

            <div>
                <label :for="`pf-area-${property?.id ?? 'new'}`" class="mb-1.5 block text-sm font-medium text-ink">
                    {{ t('portfolio.form.area') }}
                </label>
                <select
                    :id="`pf-area-${property?.id ?? 'new'}`"
                    v-model="form.area_id"
                    class="block min-h-11 w-full rounded-card border border-line bg-surface px-3 text-sm text-ink
                           focus:border-accent focus:outline-none focus:ring-2 focus:ring-accent/40"
                >
                    <option :value="null">{{ t('portfolio.form.area_none') }}</option>
                    <option v-for="area in areas" :key="area.id" :value="area.id">
                        {{ optionName(area) }}
                    </option>
                </select>
            </div>

            <AppInput
                v-model="form.size_sqm"
                type="number"
                dir="ltr"
                :label="t('portfolio.form.size_sqm')"
                :error="form.errors.size_sqm"
            />
            <AppInput
                v-model="form.rooms"
                type="number"
                dir="ltr"
                :label="t('portfolio.form.rooms')"
                :error="form.errors.rooms"
            />
            <AppInput
                v-model="form.floor"
                type="number"
                dir="ltr"
                :label="t('portfolio.form.floor')"
                :error="form.errors.floor"
            />
            <AppInput
                v-model="form.purchase_price"
                type="number"
                dir="ltr"
                :label="t('portfolio.form.purchase_price')"
                :error="form.errors.purchase_price"
            />
            <div>
                <label :for="`pf-currency-${property?.id ?? 'new'}`" class="mb-1.5 block text-sm font-medium text-ink">
                    {{ t('portfolio.form.currency') }}
                </label>
                <select
                    :id="`pf-currency-${property?.id ?? 'new'}`"
                    v-model="form.currency"
                    class="block min-h-11 w-full rounded-card border border-line bg-surface px-3 text-sm text-ink
                           focus:border-accent focus:outline-none focus:ring-2 focus:ring-accent/40"
                >
                    <option v-for="code in currencies" :key="code" :value="code">{{ code }}</option>
                </select>
                <p v-if="form.errors.currency" class="mt-1 text-sm text-negative">
                    {{ form.errors.currency }}
                </p>
            </div>
            <AppInput
                v-model="form.purchase_date"
                type="date"
                dir="ltr"
                :label="t('portfolio.form.purchase_date')"
                :error="form.errors.purchase_date"
            />
            <AppInput
                v-model="form.ownership_percent"
                type="number"
                dir="ltr"
                :label="t('portfolio.form.ownership_percent')"
                :hint="t('portfolio.form.ownership_hint')"
                :error="form.errors.ownership_percent"
            />
        </div>

        <fieldset>
            <legend class="mb-2 block text-sm font-medium text-ink">
                {{ t('portfolio.form.occupancy') }}
            </legend>
            <div class="flex flex-wrap gap-2">
                <button
                    v-for="option in occupancyOptions"
                    :key="option"
                    type="button"
                    class="min-h-11 rounded-card border px-4 text-sm transition-colors
                           focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-accent"
                    :class="form.occupancy_status === option
                        ? 'border-brand bg-brand text-white'
                        : 'border-line bg-surface text-ink-muted hover:bg-surface-sunken'"
                    :aria-pressed="form.occupancy_status === option"
                    @click="form.occupancy_status = form.occupancy_status === option ? null : option"
                >
                    {{ t(`portfolio.occupancy.${option}`) }}
                </button>
            </div>
            <p v-if="form.errors.occupancy_status" class="mt-1 text-sm text-negative">
                {{ form.errors.occupancy_status }}
            </p>
        </fieldset>

        <!--
            Wave 6: the valuation questions the ACTIVE rule set asks about
            this property. All optional, all single-select, none defaulted —
            "not answered" is a complete answer. The section exists only when
            the server resolved an applicable set; the flag off means the
            prop is null and nothing here renders.
        -->
        <fieldset
            v-if="valuationRules && valuationRules.questions.length > 0"
            data-testid="valuation-questions"
        >
            <legend class="mb-1.5 block text-sm font-medium text-ink">
                {{ t('portfolio.valuation_questions.title') }}
            </legend>
            <p class="mb-2 text-xs text-ink-faint">{{ t('portfolio.valuation_questions.hint') }}</p>

            <!-- A stored answer whose question or option was superseded is
                 EXCLUDED, and the owner is told — never silently applied. -->
            <AppAlert
                v-if="valuationRules.stale > 0"
                data-testid="stale-answers"
                variant="warning"
                class="mb-3"
            >
                {{ t('portfolio.valuation_questions.stale_notice') }}
            </AppAlert>

            <div class="grid gap-4 sm:grid-cols-2">
                <div v-for="question in valuationRules.questions" :key="question.id">
                    <label
                        :for="`pf-vq-${question.id}`"
                        class="mb-1.5 block text-sm font-medium text-ink"
                    >
                        {{ ruleLabel(question) }}
                    </label>
                    <select
                        :id="`pf-vq-${question.id}`"
                        v-model="form.answers[question.id]"
                        :data-testid="`valuation-question-${question.key}`"
                        class="block min-h-11 w-full rounded-card border border-line bg-surface px-3 text-sm text-ink
                               focus:border-accent focus:outline-none focus:ring-2 focus:ring-accent/40"
                    >
                        <option :value="null">{{ t('portfolio.valuation_questions.not_answered') }}</option>
                        <option v-for="option in question.options" :key="option.id" :value="option.id">
                            {{ ruleLabel(option) }}
                        </option>
                    </select>
                    <p v-if="ruleHelp(question)" class="mt-1 text-xs text-ink-faint">
                        {{ ruleHelp(question) }}
                    </p>
                </div>
            </div>

            <p v-if="form.errors.answers" class="mt-1 text-sm text-negative" role="alert">
                {{ form.errors.answers }}
            </p>
        </fieldset>

        <fieldset>
            <legend class="mb-1.5 block text-sm font-medium text-ink">
                {{ t('portfolio.form.location') }}
            </legend>
            <p class="mb-2 text-xs text-ink-faint">{{ t('portfolio.form.location_hint') }}</p>

            <!--
                §9.3 makes the saved pin write-only, so edit mode narrates
                state instead of echoing numbers: a pin exists (clearable),
                or its removal is queued (undoable). Blank inputs mean
                "keep what is saved".
            -->
            <div
                v-if="keepingSavedPin"
                data-testid="location-saved"
                class="mb-3 flex flex-wrap items-center justify-between gap-2 rounded-card border border-line
                       bg-surface-sunken px-3 py-2 text-sm text-ink-muted"
            >
                <span>{{ t('portfolio.form.location_saved') }}</span>
                <button
                    type="button"
                    class="min-h-9 rounded-card border border-line px-3 text-sm text-negative
                           hover:bg-surface focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-accent"
                    @click="form.remove_location = true"
                >
                    {{ t('portfolio.form.location_clear') }}
                </button>
            </div>
            <div
                v-else-if="form.remove_location"
                class="mb-3 flex flex-wrap items-center justify-between gap-2 rounded-card border border-caution/50
                       bg-surface-sunken px-3 py-2 text-sm text-ink-muted"
            >
                <span>{{ t('portfolio.form.location_will_remove') }}</span>
                <button
                    type="button"
                    class="min-h-9 rounded-card border border-line px-3 text-sm text-ink
                           hover:bg-surface focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-accent"
                    @click="form.remove_location = false"
                >
                    {{ t('portfolio.form.location_keep') }}
                </button>
            </div>

            <template v-if="!form.remove_location">
                <p v-if="keepingSavedPin" class="mb-2 text-xs text-ink-faint">
                    {{ t('portfolio.form.location_replace_hint') }}
                </p>

                <div class="grid gap-4 sm:grid-cols-2">
                    <div>
                        <label :for="`pf-lat-${property?.id ?? 'new'}`" class="mb-1.5 block text-sm font-medium text-ink">
                            {{ t('portfolio.form.latitude') }}
                        </label>
                        <input
                            :id="`pf-lat-${property?.id ?? 'new'}`"
                            v-model="form.latitude"
                            type="number"
                            step="any"
                            inputmode="decimal"
                            dir="ltr"
                            class="block min-h-11 w-full rounded-card border border-line bg-surface px-3 text-sm text-ink
                                   focus:border-accent focus:outline-none focus:ring-2 focus:ring-accent/40"
                        >
                        <p v-if="form.errors.latitude" class="mt-1 text-sm text-negative">
                            {{ form.errors.latitude }}
                        </p>
                    </div>
                    <div>
                        <label :for="`pf-lng-${property?.id ?? 'new'}`" class="mb-1.5 block text-sm font-medium text-ink">
                            {{ t('portfolio.form.longitude') }}
                        </label>
                        <input
                            :id="`pf-lng-${property?.id ?? 'new'}`"
                            v-model="form.longitude"
                            type="number"
                            step="any"
                            inputmode="decimal"
                            dir="ltr"
                            class="block min-h-11 w-full rounded-card border border-line bg-surface px-3 text-sm text-ink
                                   focus:border-accent focus:outline-none focus:ring-2 focus:ring-accent/40"
                        >
                        <p v-if="form.errors.longitude" class="mt-1 text-sm text-negative">
                            {{ form.errors.longitude }}
                        </p>
                    </div>
                </div>

                <div v-if="coordinateEntered || keepingSavedPin" class="mt-3">
                    <span class="mb-2 block text-sm font-medium text-ink">
                        {{ t('portfolio.form.precision') }}
                    </span>
                    <div class="flex flex-wrap gap-2">
                        <button
                            v-for="option in ['exact', 'approximate']"
                            :key="option"
                            :data-testid="`precision-${option}`"
                            type="button"
                            class="min-h-11 rounded-card border px-4 text-sm transition-colors
                                   focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-accent"
                            :class="form.location_precision === option
                                ? 'border-brand bg-brand text-white'
                                : 'border-line bg-surface text-ink-muted hover:bg-surface-sunken'"
                            :aria-pressed="form.location_precision === option"
                            @click="form.location_precision = option"
                        >
                            {{ t(`portfolio.form.precision_${option}`) }}
                        </button>
                    </div>
                    <p v-if="form.errors.location_precision" class="mt-1 text-sm text-negative">
                        {{ form.errors.location_precision }}
                    </p>
                </div>
            </template>
        </fieldset>

        <div>
            <label :for="`pf-notes-${property?.id ?? 'new'}`" class="mb-1.5 block text-sm font-medium text-ink">
                {{ t('portfolio.form.notes') }}
            </label>
            <textarea
                :id="`pf-notes-${property?.id ?? 'new'}`"
                v-model="form.notes"
                rows="2"
                maxlength="2000"
                class="block w-full rounded-card border border-line bg-surface px-3 py-2.5 text-sm text-ink
                       focus:border-accent focus:outline-none focus:ring-2 focus:ring-accent/40"
            />
            <p class="mt-1 text-xs text-ink-faint">{{ t('portfolio.form.notes_hint') }}</p>
        </div>

        <label class="flex min-h-11 cursor-pointer items-start gap-3 text-sm text-ink-muted">
            <input
                v-model="form.consent_valuation"
                type="checkbox"
                class="mt-1 h-4 w-4 rounded border-line text-brand focus:ring-accent"
            >
            <span>{{ t('portfolio.form.consent_valuation') }}</span>
        </label>
        <p v-if="form.errors.consent_valuation" class="text-sm text-negative">
            {{ form.errors.consent_valuation }}
        </p>

        <AppButton type="submit" block class="min-h-11" :loading="form.processing">
            {{ editing ? t('portfolio.form.save_changes') : t('portfolio.form.add') }}
        </AppButton>
    </form>
</template>
