<script setup lang="ts">
import { computed } from 'vue';
import { Head, router, useForm, usePage } from '@inertiajs/vue3';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import AppCard from '@/Components/ui/AppCard.vue';
import AppButton from '@/Components/ui/AppButton.vue';
import AppInput from '@/Components/ui/AppInput.vue';
import AppSelect from '@/Components/ui/AppSelect.vue';
import AppToggle from '@/Components/ui/AppToggle.vue';
import AppAlert from '@/Components/ui/AppAlert.vue';
import MapPicker from '@/Components/MapPicker.vue';
import { t } from '@/lib/i18n';
import type { SharedPageProps } from '@/Types/inertia';

interface Option { value: number | string; label: string; depth?: number }

const props = defineProps<{
    place: Record<string, unknown> | null;
    options: { categories: Option[]; areas: Option[]; operational_statuses: Option[] };
}>();

const page = usePage<SharedPageProps>();
const mapStyleUrl = computed(() => (page.props.map as { style_url?: string } | undefined)?.style_url ?? null);

const isEdit = computed(() => props.place !== null);
const placeId = computed(() => Number(props.place?.id ?? 0));
const str = (key: string, fallback = ''): string => String(props.place?.[key] ?? fallback);
const num = (key: string): number | null => {
    const v = props.place?.[key];
    return v === null || v === undefined || v === '' ? null : Number(v);
};

const form = useForm({
    name_ckb: str('name_ckb'),
    name_ar: str('name_ar'),
    name_en: str('name_en'),
    place_category_id: num('place_category_id'),
    subcategory: str('subcategory'),
    area_id: num('area_id'),
    latitude: num('latitude'),
    longitude: num('longitude'),
    address_ckb: str('address_ckb'),
    address_ar: str('address_ar'),
    address_en: str('address_en'),
    website: str('website'),
    operational_status: str('operational_status', 'operating'),
    is_public: Boolean(props.place?.is_public ?? true),
    source: str('source'),
    source_url: str('source_url'),
    confidence: str('confidence', 'medium'),
});

const allowedTransitions = computed(() => (props.place?.allowed_transitions ?? []) as string[]);

function submit(): void {
    if (isEdit.value) {
        form.put(`/admin/places/${placeId.value}`, { preserveScroll: true });
    } else {
        form.post('/admin/places');
    }
}

function move(status: string): void {
    router.post(`/admin/places/${placeId.value}/transition`, { status }, { preserveScroll: true });
}
</script>

<template>
    <Head :title="isEdit ? form.name_ckb : t('geography.create_place')" />

    <AdminLayout>
        <template #title>{{ isEdit ? form.name_ckb : t('geography.create_place') }}</template>

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
                    <AppInput v-model="form.name_ckb" :label="t('projects.fields.name_ckb')" :error="form.errors.name_ckb" required />
                    <AppInput v-model="form.name_ar" :label="t('projects.fields.name_ar')" :error="form.errors.name_ar" />
                    <AppInput v-model="form.name_en" :label="t('projects.fields.name_en')" :error="form.errors.name_en" dir="ltr" />

                    <AppSelect
                        v-model="form.place_category_id"
                        :label="t('geography.fields.category')"
                        :options="options.categories"
                        :error="form.errors.place_category_id"
                        :placeholder="t('app.actions.filter')"
                        required
                    />

                    <AppSelect
                        v-model="form.operational_status"
                        :label="t('geography.fields.operational_status')"
                        :options="options.operational_statuses"
                        :error="form.errors.operational_status"
                        required
                    />
                </div>
            </AppCard>

            <AppCard :title="t('projects.wizard.location')">
                <AppAlert variant="info" class="mb-5">
                    {{ t('geography.place_location_required') }}
                </AppAlert>

                <div class="space-y-5">
                    <MapPicker
                        v-model:latitude="form.latitude"
                        v-model:longitude="form.longitude"
                        :boundary-wkt="''"
                        :style-url="mapStyleUrl"
                        mode="point"
                        height="320px"
                    />

                    <div class="grid gap-5 sm:grid-cols-2">
                        <AppInput v-model="form.latitude" :label="t('projects.fields.latitude')" :error="form.errors.latitude" dir="ltr" required />
                        <AppInput v-model="form.longitude" :label="t('projects.fields.longitude')" :error="form.errors.longitude" dir="ltr" required />
                    </div>

                    <AppSelect
                        v-model="form.area_id"
                        :label="t('projects.fields.area')"
                        :options="options.areas"
                        :placeholder="t('app.states.empty')"
                        :error="form.errors.area_id"
                    />

                    <AppInput v-model="form.address_ckb" :label="t('geography.fields.address_ckb')" :error="form.errors.address_ckb" />
                </div>
            </AppCard>

            <AppCard :title="t('projects.wizard.sources')" :description="t('geography.place_source_hint')">
                <div class="space-y-5">
                    <AppInput v-model="form.source" :label="t('projects.fields.source')" :error="form.errors.source" />
                    <AppInput v-model="form.source_url" :label="t('geography.fields.source_url')" :error="form.errors.source_url" dir="ltr" />
                    <AppInput v-model="form.website" :label="t('projects.fields.official_url')" :error="form.errors.website" dir="ltr" />
                    <AppToggle
                        v-model="form.is_public"
                        :label="t('geography.fields.is_public')"
                        :description="t('geography.fields.is_public_hint')"
                    />
                </div>
            </AppCard>

            <div class="flex justify-end">
                <AppButton type="submit" :loading="form.processing">
                    {{ isEdit ? t('app.actions.save') : t('geography.create_place') }}
                </AppButton>
            </div>
        </form>
    </AdminLayout>
</template>
