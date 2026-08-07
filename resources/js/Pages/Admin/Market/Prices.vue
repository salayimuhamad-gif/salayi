<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import { Head, router } from '@inertiajs/vue3';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import AppCard from '@/Components/ui/AppCard.vue';
import AppButton from '@/Components/ui/AppButton.vue';
import AppAlert from '@/Components/ui/AppAlert.vue';
import AppEmptyState from '@/Components/ui/AppEmptyState.vue';
import { t, formatNumber } from '@/lib/i18n';
import AppPagination from '@/Components/ui/AppPagination.vue';

interface Record_ {
    id: number;
    scope_type: string | null;
    scope_external_id: string | null;
    property_type: string | null;
    price_type: string | null;
    family: string | null;
    requires_qualifier: boolean;
    currency: string;
    price: string | null;
    period: string;
    sample_size: number | null;
    source: string | null;
    confidence: string;
    status: string;
    is_outlier: boolean;
}

const props = defineProps<{
    records: { data: Record_[]; links: Array<{ url: string | null; label: string; active: boolean }> };
    filters: { status: string; price_type: string; period: string; batch: number };
    priceTypes: Array<{ value: string; label: string; family: string }>;
    counts: { draft: number; published: number };
    can: { publish: boolean };
}>();

const filters = ref({ ...props.filters, batch: props.filters.batch || '' });
const selected = ref<number[]>([]);
let timer: ReturnType<typeof setTimeout> | undefined;

watch(filters, () => {
    clearTimeout(timer);
    timer = setTimeout(() => {
        router.get('/admin/market/prices', filters.value, { preserveState: true, replace: true });
    }, 300);
}, { deep: true });

// A selection spanning more than one provenance family is a review error, not
// a publish error — the records are fine individually. Warning here means the
// reviewer notices before acting rather than after.
const selectedFamilies = computed(() => {
    const families = props.records.data
        .filter((r) => selected.value.includes(r.id))
        .map((r) => r.family)
        .filter((f): f is string => f !== null);
    return [...new Set(families)];
});

const selectableIds = computed(() =>
    props.records.data.filter((r) => !r.is_outlier).map((r) => r.id),
);

function act(action: 'publish' | 'unpublish'): void {
    router.post('/admin/market/prices/publish', { ids: selected.value, action }, {
        preserveScroll: true,
        onSuccess: () => { selected.value = []; },
    });
}

function toggleAll(): void {
    selected.value = selected.value.length === selectableIds.value.length ? [] : [...selectableIds.value];
}

const tone = (family: string | null): string => ({
    verified: 'bg-positive/10 text-positive',
    official: 'bg-brand/10 text-brand',
    asking: 'bg-caution/10 text-caution',
}[family ?? ''] ?? 'bg-surface-sunken text-ink-muted');
</script>

<template>
    <Head :title="t('nav.market.prices')" />

    <AdminLayout>
        <template #title>{{ t('nav.market.prices') }}</template>

        <div class="mb-6 grid gap-5 sm:grid-cols-2">
            <AppCard :title="t('market.records.awaiting_review')">
                <p
                    class="numeral font-display text-2xl font-semibold"
                    :class="counts.draft > 0 ? 'text-caution' : 'text-ink-faint'"
                >
                    {{ formatNumber(counts.draft) }}
                </p>
                <p class="mt-1 text-xs text-ink-muted">{{ t('market.records.draft_hint') }}</p>
            </AppCard>

            <AppCard :title="t('market.records.published')">
                <p class="numeral font-display text-2xl font-semibold text-positive">
                    {{ formatNumber(counts.published) }}
                </p>
                <p class="mt-1 text-xs text-ink-muted">{{ t('market.records.published_hint') }}</p>
            </AppCard>
        </div>

        <div class="mb-6 grid gap-3 sm:grid-cols-3">
            <div>
                <label for="status" class="mh-label mb-1 block">{{ t('projects.publication.status') }}</label>
                <select
                    id="status" v-model="filters.status"
                    class="w-full rounded-card border border-line bg-surface-raised px-3 py-2 text-sm text-ink
                           focus:border-brand focus:outline-none focus:ring-2 focus:ring-accent"
                >
                    <option value="">{{ t('app.actions.filter') }}</option>
                    <option value="draft">{{ t('projects.publication_statuses.draft') }}</option>
                    <option value="published">{{ t('projects.publication_statuses.published') }}</option>
                </select>
            </div>

            <div>
                <label for="pt" class="mh-label mb-1 block">{{ t('market.price_type') }}</label>
                <select
                    id="pt" v-model="filters.price_type"
                    class="w-full rounded-card border border-line bg-surface-raised px-3 py-2 text-sm text-ink
                           focus:border-brand focus:outline-none focus:ring-2 focus:ring-accent"
                >
                    <option value="">{{ t('app.actions.filter') }}</option>
                    <option v-for="pt in priceTypes" :key="pt.value" :value="pt.value">{{ pt.label }}</option>
                </select>
            </div>

            <div>
                <label for="period" class="mh-label mb-1 block">{{ t('market.period') }}</label>
                <input
                    id="period" v-model="filters.period" type="text" placeholder="2026-01" dir="ltr"
                    class="w-full rounded-card border border-line bg-surface-raised px-3 py-2 text-sm text-ink
                           focus:border-brand focus:outline-none focus:ring-2 focus:ring-accent"
                >
            </div>
        </div>

        <AppAlert v-if="selectedFamilies.length > 1" variant="warning" class="mb-4">
            {{ t('market.records.mixed_family_warning') }}
        </AppAlert>

        <AppEmptyState
            v-if="records.data.length === 0"
            :title="t('market.records.none')"
            :description="t('market.records.none_hint')"
        />

        <div v-else class="mh-card overflow-hidden">
            <div class="flex flex-wrap items-center justify-between gap-3 border-b border-line px-5 py-3">
                <button type="button" class="text-sm text-brand hover:underline" @click="toggleAll">
                    {{ t('imports.toggle_all') }}
                </button>

                <div v-if="can.publish && selected.length > 0" class="flex gap-2">
                    <AppButton variant="secondary" size="sm" @click="act('unpublish')">
                        {{ t('market.records.unpublish') }}
                    </AppButton>
                    <AppButton size="sm" @click="act('publish')">
                        {{ t('market.records.publish') }} ({{ selected.length }})
                    </AppButton>
                </div>
            </div>

            <ul class="divide-y divide-line">
                <li v-for="record in records.data" :key="record.id" class="flex items-start gap-3 px-5 py-3">
                    <input
                        v-model="selected"
                        type="checkbox"
                        :value="record.id"
                        :disabled="record.is_outlier"
                        class="mt-1 rounded border-line text-brand focus:ring-accent disabled:opacity-30"
                        :aria-label="`${record.period} ${record.price_type}`"
                    >

                    <div class="min-w-0 flex-1">
                        <div class="flex flex-wrap items-center gap-2">
                            <span :class="['rounded-full px-2 py-0.5 text-xs', tone(record.family)]">
                                {{ t(`market.price_types.${record.price_type}`) }}
                            </span>
                            <span class="numeral text-sm font-medium text-ink" dir="ltr">
                                {{ record.price ? formatNumber(Number(record.price)) : '—' }} {{ record.currency }}
                            </span>
                            <span class="numeral text-xs text-ink-muted" dir="ltr">{{ record.period }}</span>
                        </div>

                        <p class="mt-0.5 truncate text-xs text-ink-muted">
                            {{ record.scope_type }}<span v-if="record.scope_external_id" dir="ltr"> · {{ record.scope_external_id }}</span>
                            <span v-if="record.property_type"> · {{ record.property_type }}</span>
                        </p>

                        <!-- A published fact needs a source; this is the last
                             point at which that can be caught. -->
                        <p v-if="!record.source" class="mt-1 text-xs text-caution">
                            {{ t('app.meta.no_source') }}
                        </p>
                    </div>

                    <div class="flex shrink-0 flex-col items-end gap-1">
                        <span
                            class="rounded-full px-2.5 py-0.5 text-xs"
                            :class="record.status === 'published' ? 'bg-positive/10 text-positive' : 'bg-surface-sunken text-ink-muted'"
                        >
                            {{ t(`projects.publication_statuses.${record.status}`) }}
                        </span>

                        <!-- Excluded from bulk publish: readmitting exactly the
                             rows the detector caught should be deliberate. -->
                        <span v-if="record.is_outlier" class="rounded-full bg-negative/10 px-2 py-0.5 text-xs text-negative">
                            {{ t('market.records.outlier') }}
                        </span>
                    </div>
                </li>
            </ul>
        </div>

        <AppPagination :links="records.links" />
    </AdminLayout>
</template>
