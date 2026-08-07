<script setup lang="ts">
import { computed } from 'vue';
import { Head, router, useForm, usePage } from '@inertiajs/vue3';
import type { SharedPageProps } from '@/Types/inertia';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import AppCard from '@/Components/ui/AppCard.vue';
import AppButton from '@/Components/ui/AppButton.vue';
import AppInput from '@/Components/ui/AppInput.vue';
import AppSelect from '@/Components/ui/AppSelect.vue';
import AppAlert from '@/Components/ui/AppAlert.vue';
import MapPicker from '@/Components/MapPicker.vue';
import { t } from '@/lib/i18n';

interface TypeOption { value: string; label: string; depth: number }
interface ParentOption { id: number; name: string; depth: number; type: string }

const props = defineProps<{
    area: Record<string, unknown> | null;
    options: { types: TypeOption[]; parents: ParentOption[] };
}>();

const isEdit = computed(() => props.area !== null);
const areaId = computed(() => Number(props.area?.id ?? 0));
const str = (key: string, fallback = ''): string => String(props.area?.[key] ?? fallback);
const num = (key: string): number | null => {
    const v = props.area?.[key];
    return v === null || v === undefined || v === '' ? null : Number(v);
};

const form = useForm({
    name_ckb: str('name_ckb'),
    name_ar: str('name_ar'),
    name_en: str('name_en'),
    slug: str('slug'),
    type: str('type', 'district'),
    parent_id: num('parent_id'),
    description_ckb: str('description_ckb'),
    description_ar: str('description_ar'),
    description_en: str('description_en'),
    latitude: num('latitude'),
    longitude: num('longitude'),
    boundary_wkt: str('boundary_wkt'),
    source: str('source'),
    confidence: str('confidence', 'medium'),
});

const selectedType = computed(() => props.options.types.find((tt) => tt.value === form.type));

/*
 * Only areas strictly coarser than the selected type may be a parent. The
 * model enforces this and throws; filtering the list means an editor never
 * reaches that error in the first place.
 */
const eligibleParents = computed(() => {
    const depth = selectedType.value?.depth ?? 0;
    return props.options.parents
        .filter((parent) => {
            const parentType = props.options.types.find((tt) => tt.value === parent.type);
            return (parentType?.depth ?? 99) < depth;
        })
        .map((parent) => ({ value: parent.id, label: parent.name, depth: parent.depth }));
});

const allowedTransitions = computed(() => (props.area?.allowed_transitions ?? []) as string[]);
const page = usePage<SharedPageProps>();
const mapStyleUrl = computed(() => (page.props.map as { style_url?: string } | undefined)?.style_url ?? null);

function submit(): void {
    if (isEdit.value) {
        form.put(`/admin/areas/${areaId.value}`, { preserveScroll: true });
    } else {
        form.post('/admin/areas');
    }
}

function move(status: string): void {
    router.post(`/admin/areas/${areaId.value}/transition`, { status }, { preserveScroll: true });
}

function deriveSlug(): void {
    if (isEdit.value || form.slug) return;
    form.slug = String(form.name_en || form.name_ckb)
        .toLowerCase().trim()
        .replace(/[^\p{L}\p{N}]+/gu, '-')
        .replace(/^-+|-+$/g, '');
}
</script>

<template>
    <Head :title="isEdit ? form.name_ckb : t('geography.create_area')" />

    <AdminLayout>
        <template #title>{{ isEdit ? form.name_ckb : t('geography.create_area') }}</template>

        <div v-if="isEdit && allowedTransitions.length > 0" class="mb-6 flex flex-wrap gap-2">
            <AppButton
                v-for="next in allowedTransitions"
                :key="next"
                variant="secondary"
                size="sm"
                @click="move(next)"
            >
                {{ t(`projects.publication_statuses.${next}`) }}
            </AppButton>
        </div>

        <form class="space-y-6" @submit.prevent="submit">
            <AppCard :title="t('projects.wizard.identity')">
                <div class="space-y-5">
                    <AppInput
                        v-model="form.name_ckb"
                        :label="t('projects.fields.name_ckb')"
                        :error="form.errors.name_ckb"
                        required
                        @blur="deriveSlug"
                    />
                    <AppInput v-model="form.name_ar" :label="t('projects.fields.name_ar')" :error="form.errors.name_ar" />
                    <AppInput v-model="form.name_en" :label="t('projects.fields.name_en')" :error="form.errors.name_en" dir="ltr" />
                    <AppInput v-model="form.slug" :label="t('projects.fields.slug')" :error="form.errors.slug" dir="ltr" required />

                    <AppSelect
                        v-model="form.type"
                        :label="t('geography.fields.type')"
                        :options="options.types"
                        :error="form.errors.type"
                        required
                    />

                    <AppSelect
                        v-model="form.parent_id"
                        :label="t('geography.fields.parent')"
                        :options="eligibleParents"
                        :placeholder="t('geography.fields.no_parent')"
                        :error="form.errors.parent_id"
                    />

                    <AppAlert v-if="eligibleParents.length === 0 && form.type !== 'city'" variant="info">
                        {{ t('geography.no_eligible_parent') }}
                    </AppAlert>
                </div>
            </AppCard>

            <AppCard :title="t('projects.wizard.location')" :description="t('geography.fields.boundary_hint')">
                <div class="space-y-5">
                    <!-- The picker and the text fields are two views of the
                         same values, bound both ways: an editor may draw, or
                         paste WKT from an import, and each updates the other. -->
                    <MapPicker
                        v-model:latitude="form.latitude"
                        v-model:longitude="form.longitude"
                        v-model:boundary-wkt="form.boundary_wkt"
                        :style-url="mapStyleUrl"
                    />

                    <div class="grid gap-5 sm:grid-cols-2">
                        <AppInput v-model="form.latitude" :label="t('projects.fields.latitude')" :error="form.errors.latitude" dir="ltr" />
                        <AppInput v-model="form.longitude" :label="t('projects.fields.longitude')" :error="form.errors.longitude" dir="ltr" />
                    </div>
                    <AppInput
                        v-model="form.boundary_wkt"
                        :label="t('projects.fields.boundary')"
                        :error="form.errors.boundary_wkt"
                        :hint="t('geography.fields.boundary_derives_centre')"
                        dir="ltr"
                    />
                </div>
            </AppCard>

            <AppCard :title="t('projects.wizard.sources')">
                <div class="space-y-5">
                    <AppInput v-model="form.source" :label="t('projects.fields.source')" :error="form.errors.source" />
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

            <div class="flex justify-end">
                <AppButton type="submit" :loading="form.processing">
                    {{ isEdit ? t('app.actions.save') : t('geography.create_area') }}
                </AppButton>
            </div>
        </form>
    </AdminLayout>
</template>
