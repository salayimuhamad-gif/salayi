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
interface Transition { value: string; requires_moderator: boolean }
interface HistoryEntry {
    from: string | null; to: string; actor: string | null;
    was_moderator: boolean; reason: string | null; at: string | null;
}

const props = defineProps<{
    offer: Record<string, unknown> | null;
    options: {
        companies: Option[]; projects: Option[]; areas: Option[]; property_types: Option[];
    };
}>();

const page = usePage<SharedPageProps>();
const mapStyleUrl = computed(() => (page.props.map as { style_url?: string } | undefined)?.style_url ?? null);

const isEdit = computed(() => props.offer !== null);
const offerId = computed(() => Number(props.offer?.id ?? 0));
const str = (k: string, d = ''): string => String(props.offer?.[k] ?? d);
const num = (k: string): number | null => {
    const v = props.offer?.[k];
    return v === null || v === undefined || v === '' ? null : Number(v);
};

const form = useForm({
    title_ckb: str('title_ckb'),
    title_ar: str('title_ar'),
    title_en: str('title_en'),
    description_ckb: str('description_ckb'),
    offer_type: str('offer_type', 'sale'),
    property_type: str('property_type', 'apartment'),
    unit_type: str('unit_type'),
    company_id: num('company_id'),
    project_id: num('project_id'),
    area_id: num('area_id'),
    location_precision: str('location_precision', 'approximate'),
    latitude: num('latitude'),
    longitude: num('longitude'),
    size_sqm: num('size_sqm'),
    rooms: num('rooms'),
    bathrooms: num('bathrooms'),
    floor: num('floor'),
    price: num('price'),
    currency: str('currency', 'USD'),
    availability: str('availability', 'available'),
    contact_method: str('contact_method', 'platform'),
    source: str('source'),
    expires_at: str('expires_at'),
    is_sponsored: Boolean(props.offer?.is_sponsored ?? false),
    disclosure_label: str('disclosure_label'),
    terms: str('terms'),
});

const transitions = computed(() => (props.offer?.allowed_transitions ?? []) as Transition[]);
const history = computed(() => (props.offer?.history ?? []) as HistoryEntry[]);
const status = computed(() => str('status', 'draft'));

function save(): void {
    // An if/else rather than a ternary: the branches are side effects, not a
    // value, which is what @typescript-eslint/no-unused-expressions objected to.
    if (isEdit.value) {
        form.put(`/admin/offers/${offerId.value}`, { preserveScroll: true });
    } else {
        form.post('/admin/offers');
    }
}

function move(next: string): void {
    router.post(`/admin/offers/${offerId.value}/transition`, { status: next }, { preserveScroll: true });
}
</script>

<template>
    <Head :title="isEdit ? form.title_ckb : t('marketplace.create')" />

    <AdminLayout>
        <template #title>{{ isEdit ? form.title_ckb : t('marketplace.create') }}</template>

        <AppCard v-if="isEdit" class="mb-6">
            <div class="flex flex-wrap items-center justify-between gap-3">
                <div>
                    <p class="mh-label">{{ t('projects.publication.status') }}</p>
                    <p class="mt-1 font-display text-base font-semibold text-ink">
                        {{ t(`marketplace.statuses.${status}`) }}
                    </p>
                </div>

                <div class="flex flex-wrap gap-2">
                    <AppButton
                        v-for="tr in transitions" :key="tr.value" size="sm"
                        :variant="tr.value === 'published' ? 'primary' : tr.value === 'rejected' ? 'danger' : 'secondary'"
                        @click="move(tr.value)"
                    >
                        {{ t(`marketplace.statuses.${tr.value}`) }}
                    </AppButton>
                </div>
            </div>

            <!-- Append-only. The moderation trail survives archiving and any
                 later overwrite of the notes field. -->
            <div v-if="history.length > 0" class="mt-5 border-t border-line pt-4">
                <p class="mh-label mb-2">{{ t('marketplace.history') }}</p>
                <ul class="space-y-1.5">
                    <li v-for="(h, i) in history" :key="i" class="text-xs text-ink-muted">
                        <span class="font-mono" dir="ltr">{{ h.from ?? '—' }} → {{ h.to }}</span>
                        · {{ h.actor ?? t('operations.audit.system') }}
                        <span v-if="h.was_moderator" class="text-brand">({{ t('marketplace.as_moderator') }})</span>
                        <span v-if="h.reason"> — {{ h.reason }}</span>
                    </li>
                </ul>
            </div>
        </AppCard>

        <form class="space-y-6" @submit.prevent="save">
            <AppCard :title="t('projects.wizard.identity')">
                <div class="space-y-5">
                    <AppInput v-model="form.title_ckb" :label="t('marketplace.fields.title_ckb')" :error="form.errors.title_ckb" required />
                    <AppInput v-model="form.title_en" :label="t('marketplace.fields.title_en')" :error="form.errors.title_en" dir="ltr" />

                    <AppSelect
                        v-model="form.company_id" :label="t('nav.companies')"
                        :options="options.companies" :placeholder="t('app.actions.filter')"
                        :hint="t('marketplace.fields.company_hint')"
                        :error="form.errors.company_id"
                    />

                    <div class="grid gap-5 sm:grid-cols-2">
                        <AppSelect
                            v-model="form.offer_type" :label="t('marketplace.fields.offer_type')"
                            :options="[
                                { value: 'sale', label: t('marketplace.offer_types.sale') },
                                { value: 'rent', label: t('marketplace.offer_types.rent') },
                                { value: 'investment', label: t('marketplace.offer_types.investment') },
                                { value: 'pre_launch', label: t('marketplace.offer_types.pre_launch') },
                            ]" :error="form.errors.offer_type" required
                        />
                        <AppSelect
                            v-model="form.property_type" :label="t('projects.fields.type')"
                            :options="options.property_types" :error="form.errors.property_type" required
                        />
                    </div>

                    <AppSelect
                        v-model="form.project_id" :label="t('nav.projects')"
                        :options="options.projects" :placeholder="t('app.states.empty')"
                        :error="form.errors.project_id"
                    />
                </div>
            </AppCard>

            <AppCard :title="t('projects.wizard.location')">
                <div class="space-y-5">
                    <!-- An owner listing an occupied home should not have to
                         publish exact coordinates (spec 19.4). -->
                    <AppSelect
                        v-model="form.location_precision" :label="t('marketplace.fields.precision')"
                        :options="[
                            { value: 'exact', label: t('marketplace.precision.exact') },
                            { value: 'approximate', label: t('marketplace.precision.approximate') },
                            { value: 'area_only', label: t('marketplace.precision.area_only') },
                        ]" :error="form.errors.location_precision" required
                    />

                    <MapPicker
                        v-if="form.location_precision !== 'area_only'"
                        v-model:latitude="form.latitude" v-model:longitude="form.longitude"
                        :boundary-wkt="''" :style-url="mapStyleUrl" mode="point" height="280px"
                    />

                    <AppSelect
                        v-model="form.area_id" :label="t('projects.fields.area')"
                        :options="options.areas" :placeholder="t('app.states.empty')"
                        :error="form.errors.area_id"
                    />
                </div>
            </AppCard>

            <AppCard :title="t('marketplace.fields.details')">
                <div class="grid gap-5 sm:grid-cols-2">
                    <AppInput v-model="form.price" :label="t('marketplace.fields.price')" :error="form.errors.price" dir="ltr" />
                    <AppSelect
                        v-model="form.currency" :label="t('market.explanation.effective')"
                        :options="[
                            { value: 'USD', label: 'USD' },
                            { value: 'IQD', label: 'IQD' },
                            { value: 'EUR', label: 'EUR' },
                        ]" :error="form.errors.currency" required
                    />
                    <AppInput v-model="form.size_sqm" :label="t('projects.fields.land_area')" :error="form.errors.size_sqm" dir="ltr" />
                    <AppInput v-model="form.rooms" :label="t('marketplace.fields.rooms')" :error="form.errors.rooms" dir="ltr" />
                </div>
            </AppCard>

            <AppCard :title="t('marketplace.sponsorship.sponsored')" :description="t('marketplace.fields.sponsored_hint')">
                <AppToggle
                    v-model="form.is_sponsored"
                    :label="t('marketplace.sponsorship.sponsored')"
                    :description="t('marketplace.sponsorship.not_ranked_by_payment')"
                />

                <!-- An undisclosed advertisement cannot be saved. The field
                     appears the moment sponsorship is switched on rather than
                     as a rejection afterwards. -->
                <div v-if="form.is_sponsored" class="mt-5">
                    <AppInput
                        v-model="form.disclosure_label"
                        :label="t('companies.fields.disclosure_label')"
                        :hint="t('marketplace.sponsorship.disclosure_notice')"
                        :error="form.errors.disclosure_label" required
                    />
                </div>

                <AppAlert v-if="form.is_sponsored" variant="info" class="mt-5">
                    {{ t('marketplace.sponsorship.not_ranked_by_payment') }}
                </AppAlert>
            </AppCard>

            <AppCard :title="t('projects.wizard.sources')">
                <AppInput v-model="form.source" :label="t('projects.fields.source')" :error="form.errors.source" />
            </AppCard>

            <div class="flex justify-end">
                <AppButton type="submit" :loading="form.processing">
                    {{ isEdit ? t('app.actions.save') : t('marketplace.create') }}
                </AppButton>
            </div>
        </form>
    </AdminLayout>
</template>
