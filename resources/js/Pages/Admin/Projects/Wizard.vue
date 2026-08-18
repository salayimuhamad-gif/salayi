<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import { Head, router, useForm, usePage } from '@inertiajs/vue3';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import AppAlert from '@/Components/ui/AppAlert.vue';
import AppButton from '@/Components/ui/AppButton.vue';
import MapPicker from '@/Components/map/MapPicker.vue';
import { createRequestGate, createThrottle } from '@/lib/wizard/geometry';
import AppInput from '@/Components/ui/AppInput.vue';
import AppSelect from '@/Components/ui/AppSelect.vue';
import { t, formatNumber } from '@/lib/i18n';
import type { SharedPageProps } from '@/Types/inertia';

/*
 * The Project Creation Wizard (spec 12.1).
 *
 * Every step posts to the server, which stores the values whether or not they
 * validate, and marks the step complete only when they do. Nothing is held in
 * browser state between steps: on Erbil mobile data a client-side wizard loses
 * everything when the tab is reclaimed, and the draft exists precisely so that
 * cannot happen.
 *
 * Distances in the nearby preview are straight-line and labelled as such
 * (§10.5). No travel time is shown or estimated — there is no routing
 * provider, and a duration derived from a straight line reads as a
 * measurement while being a guess.
 */
interface Option { id: number; name: string; depth?: number }

interface NearbyPlace {
    name: string;
    category: string | null;
    distance_km: number;
    distance_method: string;
    travel_time_minutes: number | null;
}

const props = defineProps<{
    media?: Array<{
        id: number;
        path: string;
        alt: string | null;
        alt_ckb: string | null;
        alt_ar: string | null;
        alt_en: string | null;
        preview_url: string;
        is_cover: boolean;
    }>;
    draft: {
        id: number;
        current_step: string;
        completed_steps: string[];
        values: Record<string, unknown>;
        all_values: Record<string, unknown>;
        missing_steps: string[];
        is_submittable: boolean;
        project_id: number | null;
        updated_at: string | null;
        version: number;
        submitted_at: string | null;
    };
    retention_days: number;
    acting: { company_id: number | null; is_platform: boolean; must_choose: boolean; available: number[] };
    steps: string[];
    required_steps: string[];
    options: {
        developers?: Option[];
        companies?: Option[];
        areas?: Option[];
        project_types?: string[];
        construction_statuses?: string[];
        delivery_statuses?: string[];
        price_types?: string[];
        association_roles?: string[];
    };
    can: { publish: boolean };
}>();

/*
 * Step values are heterogeneous by design — each step owns different fields —
 * so the form is typed to the primitives Inertia can actually serialise
 * rather than to `unknown`, which its FormDataType constraint rejects.
 */
type StepValue = string | number | boolean | null | undefined;

const form = useForm<Record<string, StepValue>>({
    ...(props.draft.values as Record<string, StepValue>),
});

const nearby = ref<NearbyPlace[]>([]);
const suggestedArea = ref<{ id: number; name: string } | null>(null);
const nearbyLoading = ref(false);
const nearbyGate = createRequestGate();
const nearbyThrottle = createThrottle(400);

/*
 * The exact point a suggestion belongs to. Apply is disabled unless this
 * still matches the form — a suggestion is only ever valid for the
 * coordinates that produced it.
 */
const suggestionPoint = ref<{ lat: number; lng: number } | null>(null);

const suggestionIsCurrent = computed(() => {
    const point = suggestionPoint.value;

    return point !== null
        && Number(form.latitude) === point.lat
        && Number(form.longitude) === point.lng;
});

/*
 * An asking price shown without a qualifier is indistinguishable from a
 * transaction, and the gap between the two is the thing this product measures.
 */
/* ------------------------------------------------- fatal + retention */

const fatal = ref(false);
const retentionTouched = ref(false);

function reload(): void {
    router.reload({ onError: () => { fatal.value = true; } });
}

/** Push the retention clock out without changing any content. */
function touchRetention(): void {
    router.post(`/admin/projects/wizard/${props.draft.id}/touch`, {}, {
        preserveScroll: true,
        onSuccess: () => { retentionTouched.value = true; },
        onError: () => { fatal.value = true; },
    });
}

function discardDraft(): void {
    router.delete(`/admin/projects/wizard/${props.draft.id}`, {
        onError: () => { fatal.value = true; },
    });
}

const isAskingPrice = computed(() => {
    const type = String(form.price_type ?? '');

    return type === 'sale_asking' || type === 'rent_asking';
});
const nearbyError = ref(false);

/*
 * Step labels reuse the section names already defined under projects.wizard —
 * identity, developer, location, media — and only the three the existing group
 * lacks are new. Duplicating labels that already exist is how two names for
 * one concept drift apart in translation.
 */
const extraStepLabels: Record<string, string> = {
    details: 'projects.wizard.creation.step_details',
    pricing: 'projects.wizard.creation.step_pricing',
    review: 'projects.wizard.creation.step_review',
};

/** A translated label for a review row, falling back to the raw key. */
function reviewLabel(key: string): string {
    const candidates = [
        `projects.wizard.creation.field_${key}`,
        `projects.fields.${key}`,
    ];

    for (const candidate of candidates) {
        const label = t(candidate);

        if (label !== candidate) {
            return label;
        }
    }

    return key;
}

/** Enum values are shown translated; everything else as entered. */
function reviewValue(key: string, value: unknown): string {
    if (value === null || value === undefined || value === '') {
        return '—';
    }

    const groups: Record<string, string> = {
        project_type: 'projects.types',
        construction_status: 'projects.construction_statuses',
        delivery_status: 'projects.delivery_statuses',
        price_type: 'market.price_types',
    };

    if (groups[key]) {
        const label = t(`${groups[key]}.${String(value)}`);

        return label.startsWith(groups[key]) ? String(value) : label;
    }

    return String(value);
}

function stepLabel(step: string): string {
    return extraStepLabels[step] ? t(extraStepLabels[step]) : t(`projects.wizard.${step}`);
}

/** Translated select options for an enum-backed field. */
function enumOptions(values: string[] | undefined, group: string) {
    return [
        { value: '', label: t('projects.wizard.creation.empty_options') },
        ...(values ?? []).map((value) => ({ value, label: t(`${group}.${value}`) })),
    ];
}

const stepIndex = computed(() => props.steps.indexOf(props.draft.current_step));
const previousStep = computed(() => (stepIndex.value > 0 ? props.steps[stepIndex.value - 1] : null));


const developerOptions = computed(() => [
    { value: '', label: t('projects.wizard.creation.empty_options') },
    ...(props.options.developers ?? []).map((dev) => ({ value: dev.id, label: dev.name })),
]);

function save(advance: boolean): void {
    form
        .transform((data) => ({ ...data, advance, version: props.draft.version }))
        .post(`/admin/projects/wizard/${props.draft.id}/${props.draft.current_step}`, {
            preserveScroll: true,
        });
}

function goBack(): void {
    if (previousStep.value) {
        router.get(`/admin/projects/wizard/${props.draft.id}/${previousStep.value}`);
    }
}

const areaUnresolved = ref(false);

/*
 * Map configuration comes from shared props, so the wizard and the Explorer
 * agree about which provider is in use.
 */
const page = usePage<SharedPageProps>();
const mapProvider = computed(() => page.props.map?.provider ?? 'maplibre');
const mapStyleUrl = computed(() => page.props.map?.style_url ?? null);
// Never shared to the client; MapLibre renders without one.
const mapGoogleKey = null;

function applySuggestedArea(): void {
    // Refuses a suggestion that belongs to a point the person has left.
    if (suggestedArea.value && suggestionIsCurrent.value) {
        form.area_id = suggestedArea.value.id;
        // Recorded as SUGGESTED, not manual: provenance distinguishes a
        // choice from an acceptance.
        form.area_was_suggested = true;
    }
}

/* ------------------------------------------------------------- media */

const mediaBusy = ref(false);
const mediaError = ref<string | null>(null);
const dragFrom = ref<number | null>(null);

/** Local alt-text buffer, so typing does not post on every keystroke. */
const altDraft = ref<Record<number, { ckb: string; ar: string; en: string }>>({});

watch(
    () => props.media,
    (items) => {
        for (const item of items ?? []) {
            altDraft.value[item.id] ??= {
                ckb: item.alt_ckb ?? '',
                ar: item.alt_ar ?? '',
                en: item.alt_en ?? '',
            };
        }
    },
    { immediate: true, deep: true },
);

const media = computed(() => props.media ?? []);

function mediaRequest(payload: Record<string, unknown>, method: 'post' | 'patch' = 'patch'): void {
    mediaBusy.value = true;
    mediaError.value = null;

    router[method](`/admin/projects/wizard/${props.draft.id}/media`, payload as never, {
        preserveScroll: true,
        forceFormData: method === 'post',
        onError: (errors) => {
            // Recoverable: the draft is intact, only this operation failed.
            mediaError.value = Object.values(errors)[0] ?? t('projects.wizard.creation.state_error');
        },
        onFinish: () => {
            mediaBusy.value = false;
        },
    });
}

function setCover(id: number): void {
    mediaRequest({ cover_id: id });
}

function deleteMedia(id: number): void {
    mediaRequest({ delete_id: id });
}

function saveAlt(id: number): void {
    mediaRequest({ alt: { [id]: altDraft.value[id] } });
}

/** Explicit reorder, used by both the arrows and the drop handler. */
function applyOrder(ids: number[]): void {
    mediaRequest({ order: ids });
}

function move(index: number, delta: number): void {
    const ids = media.value.map((item) => item.id);
    const target = index + delta;

    if (target < 0 || target >= ids.length) {
        return;
    }

    [ids[index], ids[target]] = [ids[target], ids[index]];
    applyOrder(ids);
}

function dropOn(index: number): void {
    if (dragFrom.value === null || dragFrom.value === index) {
        return;
    }

    const ids = media.value.map((item) => item.id);
    const [moved] = ids.splice(dragFrom.value, 1);
    ids.splice(index, 0, moved);
    dragFrom.value = null;
    applyOrder(ids);
}

function uploadMedia(event: Event): void {
    const input = event.target as HTMLInputElement;
    const file = input.files?.[0];

    if (!file) {
        return;
    }

    mediaRequest({ file }, 'post');

    // Clear the input so the same file can be retried after an error.
    input.value = '';
}

function submit(): void {
    router.post(`/admin/projects/wizard/${props.draft.id}/submit`);
}

function discard(): void {
    if (window.confirm(t('projects.wizard.creation.discard_confirm'))) {
        router.delete(`/admin/projects/wizard/${props.draft.id}`);
    }
}

/** Nearby preview. Recomputed only on demand — not on every keystroke. */
/*
 * ONE point update, then ONE request.
 *
 * Firing after each coordinate separately issued two requests with MIXED
 * values — old latitude, new longitude — and whichever returned last won,
 * suggesting an area for a location nobody chose.
 */
function updatePoint(point: { lat: number; lng: number } | null): void {
    form.latitude = point?.lat ?? null;
    form.longitude = point?.lng ?? null;

    /*
     * EVERYTHING derived from the old point is cleared immediately.
     *
     * Leaving the previous suggestion on screen while a new request is in
     * flight let somebody press Apply and attach an area belonging to a
     * location they had already moved away from. Clearing first means the
     * worst case is an empty panel, not a wrong assignment.
     */
    nearby.value = [];
    suggestedArea.value = null;
    areaUnresolved.value = false;
    nearbyError.value = false;
    suggestionPoint.value = null;

    // Applying was tied to the OLD point, so that flag is no longer true.
    if (form.area_was_suggested) {
        form.area_was_suggested = false;
        form.area_id = null;
    }

    // Throttled: dragging a marker emits a stream of positions, and each one
    // would otherwise be a request nobody reads the answer to.
    nearbyThrottle.cancel();

    if (point !== null) {
        nearbyThrottle.schedule(() => void loadNearby());
    }
}

async function loadNearby(): Promise<void> {
    const latitude = form.latitude;
    const longitude = form.longitude;

    if (latitude === undefined || longitude === undefined || latitude === '' || longitude === '') {
        return;
    }

    nearbyLoading.value = true;
    nearbyError.value = false;

    // Monotonic token: an older response cannot overwrite a newer one.
    const token = nearbyGate.begin();

    try {
        const params = new URLSearchParams({ latitude: String(latitude), longitude: String(longitude) });
        const response = await fetch(`/admin/projects/wizard/nearby?${params.toString()}`, {
            headers: { Accept: 'application/json' },
        });

        if (!response.ok) {
            throw new Error(String(response.status));
        }

        const data = await response.json();

        if (!nearbyGate.isCurrent(token)) {
            return;   // superseded while in flight
        }

        nearby.value = data.places ?? [];
        suggestedArea.value = data.suggested_area ?? null;
        areaUnresolved.value = Boolean(data.area_unresolved);
        // Bound to the coordinates this response was asked for.
        suggestionPoint.value = { lat: Number(latitude), lng: Number(longitude) };
    } catch {
        if (nearbyGate.isCurrent(token)) {
            // Recoverable: the point is still valid, only the preview failed.
            nearbyError.value = true;
        }
    } finally {
        if (nearbyGate.isCurrent(token)) {
            nearbyLoading.value = false;
        }
    }
}

// Reset the form when the server sends a different step.
watch(
    () => props.draft.current_step,
    () => form.defaults({ ...(props.draft.values as Record<string, StepValue>) }).reset(),
);
</script>

<template>
    <Head :title="t('projects.wizard.creation.title')" />

    <AdminLayout>
        <h1 class="font-display text-2xl font-bold text-ink">{{ t('projects.wizard.creation.title') }}</h1>
        <p class="mt-2 max-w-2xl text-sm text-ink-muted">{{ t('projects.wizard.creation.intro') }}</p>

        <p v-if="draft.updated_at" class="mt-1 text-xs text-ink-faint">
            {{ t('projects.wizard.creation.saved_at', { time: draft.updated_at }) }}
        </p>

        <!-- Step rail. A completed step is reachable; a later one is not,
             because skipping ahead produces a draft that looks further along
             than it is. -->
        <ol class="mt-6 flex flex-wrap gap-2">
            <li v-for="(step, index) in steps" :key="step">
                <span
                    class="inline-flex items-center gap-2 rounded-full border px-3 py-1 text-xs"
                    :class="step === draft.current_step
                        ? 'border-brand bg-brand text-white'
                        : draft.completed_steps.includes(step)
                            ? 'border-brand/40 text-brand'
                            : 'border-line text-ink-faint'"
                >
                    <span class="numeral" dir="ltr">{{ index + 1 }}</span>
                    {{ stepLabel(step) }}
                </span>
            </li>
        </ol>

        <!-- FATAL: the page cannot continue. Distinct from a recoverable
             error because the action is different, and the first thing the
             person needs to know is that their work survived. -->
        <div v-if="fatal" class="mh-card mt-4 border-danger p-4">
            <p class="font-medium text-danger">{{ t('projects.wizard.creation.fatal_title') }}</p>
            <p class="mt-1 text-sm text-ink-muted">{{ t('projects.wizard.creation.fatal_body') }}</p>
            <AppButton class="mt-3" variant="secondary" @click="reload">
                {{ t('projects.wizard.creation.state_retry') }}
            </AppButton>
        </div>

        <!-- STALE VERSION: another tab or device saved in between. -->
        <AppAlert
            v-else-if="form.errors.version"
            class="mt-4"
            variant="warning"
            :message="t('projects.wizard.creation.error_stale')"
        />

        <!-- SUBMITTED: an audit record now, not a working document. -->
        <AppAlert
            v-else-if="draft.submitted_at"
            class="mt-4"
            variant="info"
            :message="t('projects.wizard.creation.already_submitted')"
        />

        <!-- VALIDATION: stored, not marked complete. -->
        <AppAlert
            v-else-if="Object.keys(form.errors).length > 0"
            class="mt-4"
            variant="danger"
            :message="t('projects.wizard.creation.error')"
        />

        <div class="mt-6 grid gap-6 lg:grid-cols-[2fr_1fr]">
            <div class="space-y-4">
                <!-- ---------------------------------------------- identity -->
                <template v-if="draft.current_step === 'identity'">
                    <AppInput
                        v-model="form.name_ckb as string"
                        :label="t('projects.fields.name_ckb')"
                        :error="form.errors.name_ckb"
                        required
                    />
                    <AppInput v-model="form.name_ar as string" :label="t('projects.fields.name_ar')" :error="form.errors.name_ar" />
                    <AppInput v-model="form.name_en as string" :label="t('projects.fields.name_en')" :error="form.errors.name_en" />
                    <!-- Enum-backed: a free-text value passes validation and
                         then throws during Eloquent casting, producing a 500 at
                         the end of a long form. -->
                    <AppSelect
                        :model-value="(form.project_type as string) ?? ''"
                        :options="enumOptions(options.project_types, 'projects.types')"
                        :label="t('projects.wizard.creation.field_project_type')"
                        :error="form.errors.project_type"
                        required
                        @update:model-value="(value) => (form.project_type = value)"
                    />

                    <AppInput
                        v-model="form.slug as string"
                        :label="t('projects.wizard.creation.field_slug')"
                        :error="form.errors.slug"
                    />
                </template>

                <!-- --------------------------------------------- developer -->
                <template v-else-if="draft.current_step === 'developer'">
                    <AppSelect
                        :model-value="(form.developer_id as string) ?? ''"
                        :options="developerOptions"
                        :label="t('projects.fields.developer')"
                        :error="form.errors.developer_id"
                        @update:model-value="(value) => (form.developer_id = value)"
                    />

                    <AppSelect
                        :model-value="(form.association_role as string) ?? ''"
                        :options="enumOptions(options.association_roles, 'companies.association_roles')"
                        :label="t('projects.wizard.creation.field_association_role')"
                        :error="form.errors.association_role"
                        @update:model-value="(value) => (form.association_role = value)"
                    />
                </template>

                <!-- ---------------------------------------------- location -->
                <template v-else-if="draft.current_step === 'location'">
                    <MapPicker
                        :latitude="form.latitude === '' || form.latitude === undefined
                            ? null : Number(form.latitude)"
                        :longitude="form.longitude === '' || form.longitude === undefined
                            ? null : Number(form.longitude)"
                        :boundary="(form.boundary_wkt as string) ?? null"
                        :provider="mapProvider"
                        :style-url="mapStyleUrl"
                        :google-key="mapGoogleKey"
                        :disabled="form.processing"
                        @update:point="updatePoint"
                        @update:boundary="(value) => (form.boundary_wkt = value)"
                    />

                    <p v-if="form.errors.boundary_wkt" class="text-sm text-danger">
                        {{ form.errors.boundary_wkt }}
                    </p>
                    <p v-if="form.errors.latitude" class="text-sm text-danger">{{ form.errors.latitude }}</p>

                    <!-- Suggested area, applied EXPLICITLY. Adopting it
                         silently would record a machine guess as a human
                         decision, and the two carry different trust. -->
                    <div v-if="suggestedArea && suggestionIsCurrent" class="rounded-card border border-line p-3">
                        <p class="text-sm text-ink">
                            {{ t('projects.wizard.creation.suggested_area') }}:
                            <strong>{{ suggestedArea.name }}</strong>
                        </p>
                        <AppButton
                            type="button"
                            size="sm"
                            variant="secondary"
                            class="mt-2"
                            @click="applySuggestedArea"
                        >
                            {{ t('projects.wizard.creation.apply_suggested') }}
                        </AppButton>
                        <p v-if="form.area_was_suggested" class="mt-1 text-xs text-ink-faint">
                            {{ t('projects.wizard.creation.area_applied') }}
                        </p>
                    </div>

                    <AppAlert
                        v-if="areaUnresolved && suggestionIsCurrent"
                        variant="warning"
                        :message="t('projects.wizard.creation.area_unresolved')"
                    />

                    <!-- Nearby preview, straight-line kilometres, recalculated
                         whenever the point moves. No travel time: there is no
                         routing provider and a duration from a straight line
                         reads as a measurement while being a guess. -->
                    <div v-if="nearby.length > 0" class="space-y-1">
                        <div class="flex items-center justify-between">
                            <p class="text-xs font-medium text-ink-muted">
                                {{ t('projects.wizard.creation.nearby_title') }}
                            </p>
                            <AppButton type="button" size="sm" variant="secondary" @click="loadNearby">
                                {{ t('projects.wizard.creation.nearby_recalculate') }}
                            </AppButton>
                        </div>
                        <ul class="max-h-48 space-y-1 overflow-y-auto text-xs">
                            <li
                                v-for="(place, index) in nearby"
                                :key="index"
                                class="flex justify-between rounded-card border border-line px-2 py-1"
                            >
                                <span>{{ place.name }}</span>
                                <span class="text-ink-faint">
                                    {{ place.distance_km }} {{ t('projects.wizard.creation.km') }}
                                </span>
                            </li>
                        </ul>
                    </div>

                    <p v-else-if="nearbyLoading" class="text-xs text-ink-faint">
                        {{ t('app.states.loading') }}
                    </p>
                </template>

                <!-- ----------------------------------------------- details -->
                <template v-else-if="draft.current_step === 'details'">
                    <AppSelect
                        :model-value="(form.construction_status as string) ?? ''"
                        :options="enumOptions(options.construction_statuses, 'projects.construction_statuses')"
                        :label="t('projects.fields.construction_status')"
                        :error="form.errors.construction_status"
                        required
                        @update:model-value="(value) => (form.construction_status = value)"
                    />
                    <AppSelect
                        :model-value="(form.delivery_status as string) ?? ''"
                        :options="enumOptions(options.delivery_statuses, 'projects.delivery_statuses')"
                        :label="t('projects.fields.delivery_status')"
                        :error="form.errors.delivery_status"
                        required
                        @update:model-value="(value) => (form.delivery_status = value)"
                    />
                    <AppInput
                        v-model="form.completion_percent as string"
                        :label="t('projects.wizard.creation.field_completion_percent')"
                        :error="form.errors.completion_percent"
                    />
                    <AppInput
                        v-model="form.expected_delivery as string"
                        type="date"
                        :label="t('projects.wizard.creation.field_expected_delivery')"
                        :error="form.errors.expected_delivery"
                    />
                    <AppInput v-model="form.unit_count as string" :label="t('projects.wizard.creation.field_unit_count')" :error="form.errors.unit_count" />
                </template>

                <!-- ----------------------------------------------- pricing -->
                <template v-else-if="draft.current_step === 'pricing'">
                    <!-- price_from anchors the record: a payload carrying only
                         price_to or only provenance would be stored and then
                         dropped, losing data somebody typed and confirmed. -->
                    <div class="grid gap-3 sm:grid-cols-2">
                        <AppInput
                            v-model="form.price_from as string"
                            type="number"
                            :label="t('projects.wizard.creation.field_price_from')"
                            :error="form.errors.price_from"
                        />
                        <AppInput
                            v-model="form.price_to as string"
                            type="number"
                            :label="t('projects.wizard.creation.field_price_to')"
                            :error="form.errors.price_to"
                        />
                        <AppInput
                            v-model="form.currency as string"
                            :label="t('projects.wizard.creation.field_currency')"
                            :error="form.errors.currency"
                        />

                        <!-- §15.1. An asking price recorded as a transaction is
                             the most damaging data error this product can make,
                             so the distinction is a required field. -->
                        <AppSelect
                            :model-value="(form.price_type as string) ?? ''"
                            :options="enumOptions(options.price_types, 'market.price_types')"
                            :label="t('projects.wizard.creation.field_price_type')"
                            :error="form.errors.price_type"
                            @update:model-value="(value) => (form.price_type = value)"
                        />

                        <AppSelect
                            :model-value="(form.price_period as string) ?? ''"
                            :options="[
                                { value: '', label: t('projects.wizard.creation.empty_options') },
                                { value: 'total', label: t('projects.wizard.creation.period_total') },
                                { value: 'monthly', label: t('projects.wizard.creation.period_monthly') },
                                { value: 'yearly', label: t('projects.wizard.creation.period_yearly') },
                                { value: 'per_sqm', label: t('projects.wizard.creation.period_per_sqm') },
                            ]"
                            :label="t('projects.wizard.creation.field_price_period')"
                            :error="form.errors.price_period"
                            @update:model-value="(value) => (form.price_period = value)"
                        />

                        <AppInput
                            v-model="form.price_effective_date as string"
                            type="date"
                            :label="t('projects.wizard.creation.field_price_effective_date')"
                            :error="form.errors.price_effective_date"
                        />

                        <!-- Provenance: a figure nobody can check is a rumour
                             with a decimal point. -->
                        <AppInput
                            v-model="form.price_source as string"
                            :label="t('projects.wizard.creation.field_price_source')"
                            :error="form.errors.price_source"
                        />

                        <AppSelect
                            :model-value="(form.price_confidence as string) ?? 'medium'"
                            :options="[
                                { value: 'low', label: t('projects.wizard.creation.confidence_low') },
                                { value: 'medium', label: t('projects.wizard.creation.confidence_medium') },
                                { value: 'high', label: t('projects.wizard.creation.confidence_high') },
                            ]"
                            :label="t('projects.wizard.creation.field_price_confidence')"
                            :error="form.errors.price_confidence"
                            @update:model-value="(value) => (form.price_confidence = value)"
                        />
                    </div>

                    <AppAlert
                        v-if="isAskingPrice"
                        variant="info"
                        :message="t('projects.wizard.creation.asking_qualifier')"
                    />
                </template>

                <!-- ------------------------------------------------- media -->
                <template v-else-if="draft.current_step === 'media'">
                    <!-- Uploads are DRAFT-OWNED: the server writes a row bound
                         to this draft, this uploader and this company, and no
                         media id is ever accepted from the client. -->
                    <label class="block text-sm">
                        <span class="text-ink-muted">{{ t('projects.wizard.creation.media_upload') }}</span>
                        <input
                            type="file"
                            accept="image/jpeg,image/png,image/webp"
                            class="mt-1 block w-full text-sm"
                            :disabled="mediaBusy"
                            @change="uploadMedia"
                        >
                    </label>

                    <p v-if="mediaBusy" class="text-xs text-ink-faint">{{ t('app.states.loading') }}</p>
                    <AppAlert v-if="mediaError" variant="danger" :message="mediaError" />

                    <p v-if="media.length === 0 && !mediaBusy" class="text-sm text-ink-faint">
                        {{ t('projects.wizard.creation.media_none') }}
                    </p>

                    <!-- Drag ordering, with explicit up/down as well: dragging
                         alone is unusable on a phone and inaccessible without
                         a pointer. -->
                    <ul v-else class="space-y-2">
                        <li
                            v-for="(item, index) in media"
                            :key="item.id"
                            class="space-y-2 rounded-card border border-line p-3"
                            :class="{ 'border-accent': item.is_cover }"
                            draggable="true"
                            @dragstart="dragFrom = index"
                            @dragover.prevent
                            @drop="dropOn(index)"
                        >
                            <div class="flex flex-wrap items-center justify-between gap-2">
                                <span class="flex items-center gap-2">
                                    <!-- Ordering and cover choice are visual
                                         decisions; a filename is not enough to
                                         make them. -->
                                    <img
                                        :src="item.preview_url"
                                        :alt="item.alt ?? ''"
                                        class="h-12 w-12 rounded object-cover"
                                        loading="lazy"
                                    >
                                    <span class="text-sm text-ink">{{ item.alt ?? item.path }}</span>
                                </span>
                                <span class="flex flex-wrap gap-2">
                                    <AppButton
                                        type="button"
                                        size="sm"
                                        variant="secondary"
                                        :disabled="index === 0 || mediaBusy"
                                        @click="move(index, -1)"
                                    >↑</AppButton>
                                    <AppButton
                                        type="button"
                                        size="sm"
                                        variant="secondary"
                                        :disabled="index === media.length - 1 || mediaBusy"
                                        @click="move(index, 1)"
                                    >↓</AppButton>
                                    <AppButton
                                        type="button"
                                        size="sm"
                                        :variant="item.is_cover ? 'primary' : 'secondary'"
                                        :disabled="mediaBusy"
                                        @click="setCover(item.id)"
                                    >{{ t('projects.wizard.creation.media_cover') }}</AppButton>
                                    <AppButton
                                        type="button"
                                        size="sm"
                                        variant="danger"
                                        :disabled="mediaBusy"
                                        @click="deleteMedia(item.id)"
                                    >{{ t('projects.wizard.creation.media_delete') }}</AppButton>
                                </span>
                            </div>

                            <!-- Trilingual alt text. A screen reader in Sorani
                                 must not fall back to English. -->
                            <div class="grid gap-2 sm:grid-cols-3">
                                <AppInput
                                    v-model="altDraft[item.id].ckb"
                                    :label="`${t('projects.wizard.creation.media_alt')} (ckb)`"
                                />
                                <AppInput
                                    v-model="altDraft[item.id].ar"
                                    :label="`${t('projects.wizard.creation.media_alt')} (ar)`"
                                />
                                <AppInput
                                    v-model="altDraft[item.id].en"
                                    :label="`${t('projects.wizard.creation.media_alt')} (en)`"
                                />
                            </div>

                            <p class="text-xs text-ink-faint">
                                {{ t('projects.wizard.creation.media_alt_hint') }}
                            </p>

                            <AppButton
                                type="button"
                                size="sm"
                                variant="secondary"
                                :disabled="mediaBusy"
                                @click="saveAlt(item.id)"
                            >
                                {{ t('app.actions.save') }}
                            </AppButton>
                        </li>
                    </ul>
                </template>

                <!-- ------------------------------------------------ review -->
                <template v-else-if="draft.current_step === 'review'">
                    <AppAlert variant="info" :message="t('projects.wizard.creation.not_published_notice')" />

                    <AppAlert
                        v-if="!draft.is_submittable"
                        variant="warning"
                        :message="t('projects.wizard.creation.incomplete', {
                            steps: draft.missing_steps.map((s) => stepLabel(s)).join(', '),
                        })"
                    />

                    <dl class="grid gap-2 text-sm">
                        <div v-for="(value, key) in draft.all_values" :key="key" class="flex gap-3">
                            <!-- Translated labels and readable values. Raw
                                 column names on a confirmation screen ask
                                 somebody to approve a record they cannot
                                 read. -->
                            <dt class="w-48 shrink-0 text-ink-faint">{{ reviewLabel(String(key)) }}</dt>
                            <dd class="text-ink">{{ reviewValue(String(key), value) }}</dd>
                        </div>
                    </dl>


                    <!-- RETENTION. Stated where the work is, not buried in a
                         settings page: a draft silently deleted after a month
                         is indistinguishable from one that was lost. -->
                    <div class="mt-2 space-y-2 rounded-card border border-line p-3">
                        <p class="text-sm font-medium text-ink">
                            {{ t('projects.wizard.creation.retention_title') }}
                        </p>
                        <p class="text-xs text-ink-muted">
                            {{ t('projects.wizard.creation.retention_body', { days: retention_days }) }}
                        </p>
                        <p v-if="retentionTouched" class="text-xs text-ok">
                            {{ t('projects.wizard.creation.retention_touched') }}
                        </p>
                        <span class="flex gap-2">
                            <AppButton type="button" size="sm" variant="secondary" @click="touchRetention">
                                {{ t('projects.wizard.creation.retention_touch') }}
                            </AppButton>
                            <AppButton
                                v-if="!draft.submitted_at"
                                type="button"
                                size="sm"
                                variant="danger"
                                @click="discardDraft"
                            >{{ t('projects.wizard.creation.retention_discard') }}</AppButton>
                        </span>
                    </div>
                </template>

                <div class="flex flex-wrap gap-2 pt-2">
                    <AppButton v-if="previousStep" type="button" variant="secondary" @click="goBack">
                        {{ t('projects.wizard.creation.back') }}
                    </AppButton>

                    <template v-if="draft.current_step !== 'review'">
                        <AppButton type="button" :disabled="form.processing" @click="save(false)">
                            {{ form.processing ? t('projects.wizard.creation.saving') : t('projects.wizard.creation.save') }}
                        </AppButton>
                        <AppButton type="button" variant="primary" :disabled="form.processing" @click="save(true)">
                            {{ t('projects.wizard.creation.save_and_next') }}
                        </AppButton>
                    </template>

                    <AppButton
                        v-else
                        type="button"
                        variant="primary"
                        :disabled="!draft.is_submittable"
                        @click="submit"
                    >
                        {{ t('projects.wizard.creation.submit') }}
                    </AppButton>

                    <AppButton type="button" variant="danger" @click="discard">
                        {{ t('projects.wizard.creation.discard') }}
                    </AppButton>
                </div>
            </div>

            <!-- ------------------------------------------- nearby preview -->
            <aside v-if="draft.current_step === 'location'" class="space-y-2">
                <h2 class="font-display text-base font-semibold text-ink">
                    {{ t('projects.wizard.creation.nearby_title') }}
                </h2>

                <p class="text-xs text-ink-faint">{{ t('projects.wizard.creation.nearby_straight_line') }}</p>

                <p v-if="suggestedArea" class="text-sm text-brand">
                    {{ t('projects.wizard.creation.suggested_area', { area: suggestedArea.name }) }}
                </p>

                <p v-if="nearbyLoading" class="text-sm text-ink-muted">{{ t('projects.wizard.creation.loading') }}</p>

                <AppAlert v-else-if="nearbyError" variant="danger" :message="t('projects.wizard.creation.error')" />

                <p v-else-if="nearby.length === 0" class="text-sm text-ink-faint">
                    {{ t('projects.wizard.creation.nearby_hint') }}
                </p>

                <ul v-else class="space-y-1">
                    <li
                        v-for="place in nearby"
                        :key="place.name"
                        class="flex items-center justify-between gap-3 rounded-card border border-line px-3 py-2 text-sm"
                    >
                        <span class="text-ink">{{ place.name }}</span>
                        <span class="numeral text-xs text-ink-faint" dir="ltr">
                            {{ formatNumber(place.distance_km, 2) }} km
                        </span>
                    </li>
                </ul>
            </aside>
        </div>
    </AdminLayout>
</template>
