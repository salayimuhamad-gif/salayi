<script setup lang="ts">
import { computed, ref } from 'vue';
import { Head, Link, router } from '@inertiajs/vue3';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import AppCard from '@/Components/ui/AppCard.vue';
import AppButton from '@/Components/ui/AppButton.vue';
import AppAlert from '@/Components/ui/AppAlert.vue';
import AppEmptyState from '@/Components/ui/AppEmptyState.vue';
import AppPagination from '@/Components/ui/AppPagination.vue';
import { t, formatNumber } from '@/lib/i18n';

/*
 * Index value review and publication (spec 15.3).
 *
 * IndexValueController rendered this page and the file did not exist — the
 * route resolved, the controller ran, and Inertia then failed on a missing
 * component. This is the reviewer's side of the gate the controller describes:
 * the step between having calculated a number and telling Erbil it is the
 * market.
 *
 * Every figure is therefore shown with its own evidence — sample size,
 * confidence, excluded outliers, revision, methodology version and any warning
 * — because spec 15.3 requires all of that to travel with a public number, and
 * a reviewer who cannot see it is not reviewing anything. A value with no
 * figure, or one carrying a publication-floor warning, is not selectable here;
 * the calculator already refused it and this screen must not route around that.
 */
interface Explanation {
    period: string;
    effective_date: string | null;
    sample_size: number | null;
    excluded_outliers: number | null;
    sources: string[];
    confidence: string;
    revision_status: string;
    revision_number: number;
    methodology_version: string | null;
    is_limited: boolean;
    warning: string | null;
}

interface ValueRow {
    id: number;
    period: string;
    value: string | null;
    median: string | null;
    minimum: string | null;
    maximum: string | null;
    change_percent: string | null;
    status: string;
    explanation: Explanation;
}

const props = defineProps<{
    index: {
        id: number;
        key: string;
        name: string;
        price_type: string;
        family: string;
        requires_qualifier: boolean;
        currency: string;
        basis: string;
        methodology_version: string | null;
        status: string;
    };
    values: {
        data: ValueRow[];
        links: Array<{ url: string | null; label: string; active: boolean }>;
    };
    can: { publish: boolean };
}>();

const selected = ref<number[]>([]);

// A row with no computed figure has nothing to publish. The controller enforces
// this server-side as well; mirroring it here means the reviewer never selects
// something that will be silently dropped.
const selectableIds = computed(() =>
    props.values.data.filter((row) => row.value !== null).map((row) => row.id),
);

const draftCount = computed(() => props.values.data.filter((r) => r.status !== 'published').length);
const publishedCount = computed(() => props.values.data.filter((r) => r.status === 'published').length);

// Any selected row that is flagged limited or carries a warning is worth
// surfacing before the reviewer acts, not after.
const selectedLimited = computed(() =>
    props.values.data.filter((r) => selected.value.includes(r.id) && r.explanation.is_limited),
);

function act(action: 'publish' | 'unpublish'): void {
    if (selected.value.length === 0) return;

    router.post(`/admin/market/indices/${props.index.id}/values/publish`, {
        ids: selected.value,
        action,
    }, {
        preserveScroll: true,
        onSuccess: () => { selected.value = []; },
    });
}

function toggleAll(): void {
    selected.value =
        selected.value.length === selectableIds.value.length ? [] : [...selectableIds.value];
}

const familyTone = (family: string): string => ({
    verified: 'bg-positive/10 text-positive',
    official: 'bg-brand/10 text-brand',
    asking: 'bg-caution/10 text-caution',
}[family] ?? 'bg-surface-sunken text-ink-muted');

const confidenceTone = (confidence: string): string => ({
    high: 'text-positive',
    moderate: 'text-ink',
    low: 'text-caution',
    insufficient: 'text-negative',
}[confidence] ?? 'text-ink-muted');
</script>

<template>
    <Head :title="`${index.name} — ${t('market.values.review')}`" />

    <AdminLayout>
        <template #title>{{ t('market.values.review') }}</template>

        <Link
            href="/admin/market/indices"
            class="mb-5 inline-flex items-center gap-1.5 text-sm text-ink-muted transition-colors
                   hover:text-ink focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-accent"
        >
            {{ t('market.indices.values') }} — {{ index.name }}
        </Link>

        <!-- Provenance of the whole index, stated once at the top. A reviewer
             publishing asking-price figures should not have to infer that. -->
        <AppCard class="mb-6">
            <div class="flex flex-wrap items-center gap-2.5">
                <span class="rounded-card px-2.5 py-1 text-xs font-medium" :class="familyTone(index.family)">
                    {{ t(`market.price_type_families.${index.family}`) }}
                </span>
                <span class="numeral text-xs text-ink-muted" dir="ltr">{{ index.key }}</span>
                <span class="text-xs text-ink-muted">
                    {{ t(`market.price_types.${index.price_type}`) }} ·
                    {{ t(`market.basis.${index.basis}`) }} ·
                    <span class="numeral" dir="ltr">{{ index.currency }}</span>
                </span>
                <span v-if="index.methodology_version" class="numeral text-xs text-ink-faint" dir="ltr">
                    {{ t('market.explanation.methodology') }} {{ index.methodology_version }}
                </span>
            </div>

            <AppAlert v-if="index.family === 'asking'" variant="warning" class="mt-4">
                {{ t('market.indices.asking_warning') }}
            </AppAlert>
            <AppAlert v-else-if="index.family === 'official'" variant="info" class="mt-4">
                {{ t('market.indices.official_warning') }}
            </AppAlert>
            <AppAlert v-if="index.requires_qualifier" variant="warning" class="mt-3">
                {{ t('market.indices.qualifier_required') }}
            </AppAlert>
        </AppCard>

        <div class="mb-6 grid gap-5 sm:grid-cols-2">
            <AppCard :title="t('market.records.awaiting_review')">
                <p
                    class="numeral font-display text-2xl font-semibold"
                    :class="draftCount > 0 ? 'text-caution' : 'text-ink-faint'"
                >
                    {{ formatNumber(draftCount) }}
                </p>
                <p class="mt-1 text-xs text-ink-muted">{{ t('market.records.draft_hint') }}</p>
            </AppCard>

            <AppCard :title="t('market.records.published')">
                <p class="numeral font-display text-2xl font-semibold text-positive">
                    {{ formatNumber(publishedCount) }}
                </p>
                <p class="mt-1 text-xs text-ink-muted">{{ t('market.records.published_hint') }}</p>
            </AppCard>
        </div>

        <AppEmptyState
            v-if="values.data.length === 0"
            :title="t('market.indices.never_built')"
            :description="t('market.warnings.no_observations')"
        />

        <template v-else>
            <div v-if="can.publish" class="mb-4 flex flex-wrap items-center gap-3">
                <AppButton variant="ghost" @click="toggleAll">
                    {{ t('app.actions.filter') }} ({{ formatNumber(selected.length) }})
                </AppButton>
                <AppButton
                    variant="primary" :disabled="selected.length === 0"
                    @click="act('publish')"
                >
                    {{ t('market.records.publish') }}
                </AppButton>
                <AppButton
                    variant="ghost" :disabled="selected.length === 0"
                    @click="act('unpublish')"
                >
                    {{ t('market.records.unpublish') }}
                </AppButton>
            </div>

            <AppAlert v-if="selectedLimited.length > 0" variant="warning" class="mb-4">
                {{ t('market.warnings.sample_below_minimum') }}
            </AppAlert>

            <ul class="space-y-3">
                <li
                    v-for="row in values.data"
                    :key="row.id"
                    class="rounded-card border border-line bg-surface-raised p-4"
                >
                    <div class="flex flex-wrap items-start gap-3">
                        <input
                            v-if="can.publish"
                            :id="`v-${row.id}`"
                            v-model="selected"
                            type="checkbox"
                            :value="row.id"
                            :disabled="row.value === null"
                            class="mt-1 h-4 w-4 rounded border-line text-brand
                                   focus:ring-2 focus:ring-accent disabled:opacity-40"
                        >

                        <div class="min-w-0 flex-1">
                            <div class="flex flex-wrap items-baseline gap-3">
                                <span class="numeral font-display text-lg font-semibold text-ink" dir="ltr">
                                    {{ row.value ?? '—' }}
                                </span>
                                <span class="numeral text-xs text-ink-muted" dir="ltr">{{ row.period }}</span>
                                <span
                                    v-if="row.change_percent"
                                    class="numeral text-xs"
                                    :class="Number(row.change_percent) >= 0 ? 'text-positive' : 'text-negative'"
                                    dir="ltr"
                                >{{ row.change_percent }}%</span>
                                <span
                                    class="rounded-card px-2 py-0.5 text-xs"
                                    :class="row.status === 'published'
                                        ? 'bg-positive/10 text-positive'
                                        : 'bg-surface-sunken text-ink-muted'"
                                >
                                    {{ t(`projects.publication_statuses.${row.status}`) }}
                                </span>
                            </div>

                            <!-- Spec 15.3: the evidence travels with the figure. -->
                            <div class="mt-2 flex flex-wrap gap-x-4 gap-y-1 text-xs text-ink-muted">
                                <span v-if="row.explanation.sample_size !== null" class="numeral">
                                    {{ t('market.explanation.sample') }}: {{ formatNumber(row.explanation.sample_size) }}
                                </span>
                                <span :class="confidenceTone(row.explanation.confidence)">
                                    {{ t('market.explanation.confidence') }}:
                                    {{ t(`market.confidence.${row.explanation.confidence}`) }}
                                </span>
                                <span v-if="row.explanation.excluded_outliers" class="numeral">
                                    {{ t('market.explanation.excluded') }}:
                                    {{ formatNumber(row.explanation.excluded_outliers) }}
                                </span>
                                <span v-if="row.explanation.effective_date" class="numeral" dir="ltr">
                                    {{ t('market.explanation.effective') }}: {{ row.explanation.effective_date }}
                                </span>
                                <span class="numeral">
                                    {{ t('market.explanation.revision') }}:
                                    {{ t(`market.revision.${row.explanation.revision_status}`) }}
                                    ({{ formatNumber(row.explanation.revision_number) }})
                                </span>
                                <span v-if="row.explanation.methodology_version" class="numeral" dir="ltr">
                                    {{ t('market.explanation.methodology') }}:
                                    {{ row.explanation.methodology_version }}
                                </span>
                            </div>

                            <div
                                v-if="row.median || row.minimum || row.maximum"
                                class="numeral mt-1.5 text-xs text-ink-faint"
                                dir="ltr"
                            >
                                {{ row.minimum ?? '—' }} · {{ row.median ?? '—' }} · {{ row.maximum ?? '—' }}
                            </div>

                            <p v-if="row.explanation.sources.length > 0" class="mt-1.5 text-xs text-ink-faint">
                                {{ row.explanation.sources.join(' · ') }}
                            </p>

                            <AppAlert v-if="row.explanation.warning" variant="warning" class="mt-3">
                                {{ t(`market.warnings.${row.explanation.warning}`) }}
                            </AppAlert>
                        </div>
                    </div>
                </li>
            </ul>

            <AppPagination :links="values.links" />
        </template>
    </AdminLayout>
</template>
