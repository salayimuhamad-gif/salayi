<script setup lang="ts">
import { computed, ref } from 'vue';
import { Head, router, useForm, usePage } from '@inertiajs/vue3';
import type { SharedPageProps } from '@/Types/inertia';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import AppCard from '@/Components/ui/AppCard.vue';
import AppButton from '@/Components/ui/AppButton.vue';
import AppInput from '@/Components/ui/AppInput.vue';
import AppSelect from '@/Components/ui/AppSelect.vue';
import AppAlert from '@/Components/ui/AppAlert.vue';
import MapPicker from '@/Components/MapPicker.vue';
import PublicationGate from '@/Components/PublicationGate.vue';
import { t } from '@/lib/i18n';

interface Option { value: string; label: string }
interface AreaOption { id: number; name: string; depth: number }
interface DeveloperOption { id: number; name: string }

const props = defineProps<{
    project: Record<string, unknown> | null;
    options: {
        types: Option[];
        construction: Option[];
        delivery: Option[];
        areas: AreaOption[];
        developers: DeveloperOption[];
    };
    can: { publish: boolean; update: boolean };
}>();

const isEdit = computed(() => props.project !== null);

/*
 * Typed accessors for the readiness payload. A Vue template expression is not
 * full TypeScript — an inline `as { ok: boolean }` assertion fails to parse —
 * so the narrowing happens here where the compiler can see it.
 */
const projectId = computed(() => Number(props.project?.id ?? 0));
const publicationStatus = computed(() => String(props.project?.publication_status ?? 'draft'));
const readiness = computed(() => (props.project?.readiness ?? { ok: false, blockers: [] }) as {
    ok: boolean;
    blockers: string[];
});
const allowedTransitions = computed(() => (props.project?.allowed_transitions ?? []) as string[]);
const nearbyCount = computed(() => Number(props.project?.nearby_count ?? 0));
const nearbyStale = computed(() => Boolean(props.project?.nearby_stale ?? false));
const amenityScore = computed(() => props.project?.amenity_score as number | null ?? null);

function recalculateNearby(): void {
    router.post(`/admin/projects/${projectId.value}/nearby/recalculate`, {}, { preserveScroll: true });
}
const page = usePage<SharedPageProps>();
const mapStyleUrl = computed(() => (page.props.map as { style_url?: string } | undefined)?.style_url ?? null);
/*
 * Field accessors. Typed per return shape rather than a single `never`-returning
 * helper: `never` silently satisfies every assignment and then fails at the one
 * call site the compiler happens to check first, which is what happened here.
 */
const str = (key: string, fallback = ''): string => String(props.project?.[key] ?? fallback);
const num = (key: string): number | null => {
    const value = props.project?.[key];
    return value === null || value === undefined || value === '' ? null : Number(value);
};

const form = useForm({
    slug: str('slug'),
    name_ckb: str('name_ckb'),
    name_ar: str('name_ar'),
    name_en: str('name_en'),
    description_ckb: str('description_ckb'),
    description_ar: str('description_ar'),
    description_en: str('description_en'),
    project_type: str('project_type', 'residential'),
    construction_status: str('construction_status', 'announced'),
    delivery_status: str('delivery_status', 'not_started'),
    completion_percent: num('completion_percent'),
    developer_id: num('developer_id'),
    area_id: num('area_id'),
    latitude: num('latitude'),
    longitude: num('longitude'),
    boundary_wkt: str('boundary_wkt'),
    launch_date: str('launch_date'),
    expected_delivery: str('expected_delivery'),
    land_area_sqm: num('land_area_sqm'),
    building_count: num('building_count'),
    unit_count: num('unit_count'),
    source: str('source'),
    official_url: str('official_url'),
    confidence: str('confidence', 'medium'),
});

/*
 * Sections rather than a fourteen-screen wizard.
 *
 * Spec 12.3 lists fourteen steps and permits saving a draft at any stage. A
 * single scrolling form with anchored sections satisfies both and is markedly
 * faster for the common case, which is an editor correcting two fields on an
 * existing project rather than creating one from nothing. The wizard shape
 * makes sense for first entry; it makes every subsequent edit slower.
 */
const sections = [
    { key: 'identity', fields: 'identity' },
    { key: 'location', fields: 'location' },
    { key: 'delivery', fields: 'delivery' },
    { key: 'scale', fields: 'scale' },
    { key: 'sources', fields: 'sources' },
] as const;

const active = ref<string>('identity');

function submit(): void {
    if (isEdit.value) {
        form.put(`/admin/projects/${projectId.value}`, { preserveScroll: true });
    } else {
        form.post('/admin/projects');
    }
}

// Slug is derived once for a new project and never auto-changed afterwards:
// a published URL that shifts because someone corrected a spelling breaks
// every inbound link and every shared WhatsApp message.
function deriveSlug(): void {
    if (isEdit.value || form.slug) return;
    form.slug = String(form.name_en || form.name_ckb)
        .toLowerCase().trim()
        .replace(/[^\p{L}\p{N}]+/gu, '-')
        .replace(/^-+|-+$/g, '');
}
</script>

<template>
    <Head :title="isEdit ? String(form.name_ckb) : t('projects.create')" />

    <AdminLayout>
        <template #title>{{ isEdit ? form.name_ckb : t('projects.create') }}</template>

        <PublicationGate
            v-if="isEdit && project"
            class="mb-6"
            :project-id="projectId"
            :status="publicationStatus"
            :readiness="readiness"
            :allowed-transitions="allowedTransitions"
            :can-publish="can.publish"
        />

        <nav class="mb-6 flex flex-wrap gap-1 border-b border-line" :aria-label="t('projects.sections')">
            <button
                v-for="section in sections"
                :key="section.key"
                type="button"
                class="-mb-px border-b-2 px-4 py-2 text-sm transition-colors focus-visible:outline-none
                       focus-visible:ring-2 focus-visible:ring-accent"
                :class="active === section.key
                    ? 'border-brand font-medium text-brand'
                    : 'border-transparent text-ink-muted hover:text-ink'"
                :aria-current="active === section.key ? 'true' : undefined"
                @click="active = section.key"
            >
                {{ t(`projects.wizard.${section.key}`) }}
            </button>
        </nav>

        <form class="space-y-6" @submit.prevent="submit">
            <AppCard v-show="active === 'identity'" :title="t('projects.wizard.identity')">
                <div class="space-y-5">
                    <AppInput
                        v-model="form.name_ckb"
                        :label="t('projects.fields.name_ckb')"
                        :error="form.errors.name_ckb"
                        :hint="t('projects.fields.name_ckb_hint')"
                        required
                        @blur="deriveSlug"
                    />
                    <AppInput v-model="form.name_ar" :label="t('projects.fields.name_ar')" :error="form.errors.name_ar" />
                    <AppInput v-model="form.name_en" :label="t('projects.fields.name_en')" :error="form.errors.name_en" dir="ltr" />
                    <AppInput
                        v-model="form.slug"
                        :label="t('projects.fields.slug')"
                        :error="form.errors.slug"
                        :hint="isEdit ? t('projects.fields.slug_locked_hint') : t('projects.fields.slug_hint')"
                        dir="ltr"
                        required
                    />
                    <AppSelect
                        v-model="form.project_type"
                        :label="t('projects.fields.type')"
                        :options="options.types"
                        :error="form.errors.project_type"
                        required
                    />
                    <AppSelect
                        v-model="form.developer_id"
                        :label="t('projects.fields.developer')"
                        :options="options.developers.map((d) => ({ value: d.id, label: d.name }))"
                        :placeholder="t('app.states.empty')"
                        :error="form.errors.developer_id"
                    />
                </div>
            </AppCard>

            <AppCard v-show="active === 'location'" :title="t('projects.wizard.location')">
                <AppAlert variant="info" class="mb-5">
                    {{ t('projects.fields.geometry_requirement') }}
                </AppAlert>

                <div class="space-y-5">
                    <MapPicker
                        v-model:latitude="form.latitude"
                        v-model:longitude="form.longitude"
                        v-model:boundary-wkt="form.boundary_wkt"
                        :style-url="mapStyleUrl"
                    />

                    <AppSelect
                        v-model="form.area_id"
                        :label="t('projects.fields.area')"
                        :options="options.areas.map((a) => ({ value: a.id, label: a.name, depth: a.depth }))"
                        :placeholder="t('app.states.empty')"
                        :error="form.errors.area_id"
                    />

                    <div class="grid gap-5 sm:grid-cols-2">
                        <AppInput
                            v-model="form.latitude"
                            :label="t('projects.fields.latitude')"
                            :error="form.errors.latitude"
                            dir="ltr"
                        />
                        <AppInput
                            v-model="form.longitude"
                            :label="t('projects.fields.longitude')"
                            :error="form.errors.longitude"
                            dir="ltr"
                        />
                    </div>

                    <AppInput
                        v-model="form.boundary_wkt"
                        :label="t('projects.fields.boundary')"
                        :error="form.errors.boundary_wkt"
                        :hint="t('projects.fields.boundary_hint')"
                        dir="ltr"
                    />
                </div>
            </AppCard>

            <AppCard v-show="active === 'delivery'" :title="t('projects.wizard.delivery')">
                <div class="space-y-5">
                    <AppSelect
                        v-model="form.construction_status"
                        :label="t('projects.fields.construction_status')"
                        :options="options.construction"
                        :error="form.errors.construction_status"
                        required
                    />
                    <AppSelect
                        v-model="form.delivery_status"
                        :label="t('projects.fields.delivery_status')"
                        :options="options.delivery"
                        :error="form.errors.delivery_status"
                        required
                    />
                    <AppInput
                        v-model="form.completion_percent"
                        :label="t('projects.fields.completion_percent')"
                        :error="form.errors.completion_percent"
                        :hint="t('projects.fields.completion_hint')"
                        dir="ltr"
                    />
                    <div class="grid gap-5 sm:grid-cols-2">
                        <AppInput v-model="form.launch_date" type="date" :label="t('projects.fields.launch_date')" :error="form.errors.launch_date" />
                        <AppInput v-model="form.expected_delivery" type="date" :label="t('projects.fields.expected_delivery')" :error="form.errors.expected_delivery" />
                    </div>
                </div>
            </AppCard>

            <AppCard v-if="isEdit" v-show="active === 'location'" :title="t('geography.nearby.title')">
                <div class="flex flex-wrap items-center justify-between gap-3">
                    <div>
                        <p class="numeral text-sm text-ink">
                            {{ nearbyCount }} {{ t('geography.nearby.calculated') }}
                            <span v-if="amenityScore !== null" class="text-ink-muted">
                                · {{ t('geography.nearby.amenity_score') }} {{ amenityScore }}
                            </span>
                        </p>
                        <p v-if="nearbyStale" class="mt-1 text-xs text-caution">
                            {{ t('geography.nearby.stale') }}
                        </p>
                    </div>

                    <AppButton variant="secondary" size="sm" @click="recalculateNearby">
                        {{ t('geography.nearby.recalculate') }}
                    </AppButton>
                </div>
            </AppCard>

            <AppCard v-show="active === 'scale'" :title="t('projects.wizard.scale')">
                <div class="grid gap-5 sm:grid-cols-3">
                    <AppInput v-model="form.land_area_sqm" :label="t('projects.fields.land_area')" :error="form.errors.land_area_sqm" dir="ltr" />
                    <AppInput v-model="form.building_count" :label="t('projects.fields.buildings')" :error="form.errors.building_count" dir="ltr" />
                    <AppInput v-model="form.unit_count" :label="t('projects.fields.units')" :error="form.errors.unit_count" dir="ltr" />
                </div>
            </AppCard>

            <AppCard v-show="active === 'sources'" :title="t('projects.wizard.sources')" :description="t('projects.fields.source_requirement')">
                <div class="space-y-5">
                    <AppInput v-model="form.source" :label="t('projects.fields.source')" :error="form.errors.source" required />
                    <AppInput v-model="form.official_url" :label="t('projects.fields.official_url')" :error="form.errors.official_url" dir="ltr" />
                    <AppSelect
                        v-model="form.confidence"
                        :label="t('projects.fields.confidence')"
                        :options="[
                            { value: 'low', label: t('market.confidence.low') },
                            { value: 'medium', label: t('market.confidence.moderate') },
                            { value: 'high', label: t('market.confidence.high') },
                        ]"
                    />
                </div>
            </AppCard>

            <div class="flex justify-end gap-3">
                <AppButton type="submit" :loading="form.processing" :disabled="!can.update && isEdit">
                    {{ isEdit ? t('app.actions.save') : t('projects.create') }}
                </AppButton>
            </div>
        </form>
    </AdminLayout>
</template>
