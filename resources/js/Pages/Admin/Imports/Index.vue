<script setup lang="ts">
import { computed, ref } from 'vue';
import { Head, router, useForm } from '@inertiajs/vue3';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import AppCard from '@/Components/ui/AppCard.vue';
import AppButton from '@/Components/ui/AppButton.vue';
import AppAlert from '@/Components/ui/AppAlert.vue';
import AppEmptyState from '@/Components/ui/AppEmptyState.vue';
import { t } from '@/lib/i18n';

interface PreviewRow {
    row_number: number;
    status: string;
    errors: Array<{ field: string; code: string }>;
    warnings: Array<{ field: string; code: string; detail?: string }>;
    normalised: Record<string, unknown> | null;
    raw: Record<string, string>;
}

interface Preview {
    reference: string;
    filename: string;
    total: number;
    valid: number;
    errors: number;
    warnings: number;
    truncated: boolean;
    delimiter: string;
    rows: PreviewRow[];
}

interface Batch {
    id: number;
    reference: string;
    status: string;
    total_rows: number;
    accepted_rows: number;
    can_rollback: boolean;
}

const props = defineProps<{
    batches: Batch[];
    preview: Preview | null;
    requiredColumns: string[];
}>();

const upload = useForm<{ file: File | null }>({ file: null });
const showErrorsOnly = ref(false);

// Valid rows are pre-selected; invalid ones cannot be selected at all. The
// server re-checks anyway — posting a row number does not force a bad row
// through — but offering the choice would misrepresent what is possible.
const selected = ref<number[]>(
    props.preview?.rows.filter((r) => r.status === 'valid').map((r) => r.row_number) ?? [],
);

const visibleRows = computed(() =>
    showErrorsOnly.value
        ? (props.preview?.rows ?? []).filter((r) => r.status === 'error' || r.warnings.length > 0)
        : (props.preview?.rows ?? []),
);

function submitFile(event: Event): void {
    const input = event.target as HTMLInputElement;
    upload.file = input.files?.[0] ?? null;
    if (upload.file) upload.post('/admin/imports/prices/preview', { forceFormData: true });
}

function accept(): void {
    router.post('/admin/imports/prices/accept', { rows: selected.value });
}

function toggleAll(): void {
    const valid = (props.preview?.rows ?? []).filter((r) => r.status === 'valid').map((r) => r.row_number);
    selected.value = selected.value.length === valid.length ? [] : valid;
}
</script>

<template>
    <Head :title="t('nav.market.imports')" />

    <AdminLayout>
        <template #title>{{ t('nav.market.imports') }}</template>

        <AppCard v-if="!preview" :title="t('imports.upload')" :description="t('imports.upload_hint')" class="mb-6">
            <AppAlert variant="info" class="mb-5">
                {{ t('imports.csv_only_notice') }}
            </AppAlert>

            <p class="mh-label mb-2">{{ t('imports.required_columns') }}</p>
            <p class="mb-5 font-mono text-xs text-ink-muted" dir="ltr">{{ requiredColumns.join(', ') }}</p>

            <input
                type="file"
                accept=".csv,text/csv"
                class="block w-full text-sm text-ink file:me-4 file:rounded-card file:border-0
                       file:bg-brand file:px-4 file:py-2 file:text-sm file:text-white"
                @change="submitFile"
            >
            <p v-if="upload.errors.file" role="alert" class="mt-2 text-xs text-negative">{{ upload.errors.file }}</p>
        </AppCard>

        <template v-if="preview">
            <AppCard :title="t('imports.preview')" :description="preview.filename" class="mb-6">
                <div class="grid grid-cols-2 gap-4 sm:grid-cols-4">
                    <div>
                        <p class="mh-label">{{ t('imports.total') }}</p>
                        <p class="numeral font-display text-xl font-semibold text-ink">{{ preview.total }}</p>
                    </div>
                    <div>
                        <p class="mh-label">{{ t('imports.valid') }}</p>
                        <p class="numeral font-display text-xl font-semibold text-positive">{{ preview.valid }}</p>
                    </div>
                    <div>
                        <p class="mh-label">{{ t('imports.with_errors') }}</p>
                        <p class="numeral font-display text-xl font-semibold" :class="preview.errors > 0 ? 'text-negative' : 'text-ink-faint'">
                            {{ preview.errors }}
                        </p>
                    </div>
                    <div>
                        <p class="mh-label">{{ t('imports.with_warnings') }}</p>
                        <p class="numeral font-display text-xl font-semibold" :class="preview.warnings > 0 ? 'text-caution' : 'text-ink-faint'">
                            {{ preview.warnings }}
                        </p>
                    </div>
                </div>

                <AppAlert v-if="preview.truncated" variant="warning" class="mt-5">
                    {{ t('imports.truncated') }}
                </AppAlert>

                <!-- Nothing is written until Accept. Worth stating, because an
                     operator who assumes the upload already saved will not
                     review. -->
                <AppAlert variant="info" class="mt-5">
                    {{ t('imports.nothing_written_yet') }}
                </AppAlert>

                <template #footer>
                    <div class="flex flex-wrap items-center justify-between gap-3">
                        <label class="flex cursor-pointer items-center gap-2 text-sm text-ink-muted">
                            <input v-model="showErrorsOnly" type="checkbox" class="rounded border-line text-brand focus:ring-accent">
                            {{ t('imports.show_problems_only') }}
                        </label>

                        <div class="flex gap-2">
                            <AppButton variant="ghost" size="sm" @click="router.post('/admin/imports/prices/discard')">
                                {{ t('app.actions.cancel') }}
                            </AppButton>
                            <AppButton size="sm" :disabled="selected.length === 0" @click="accept">
                                {{ t('imports.accept_selected') }} ({{ selected.length }})
                            </AppButton>
                        </div>
                    </div>
                </template>
            </AppCard>

            <div class="mh-card overflow-hidden">
                <div class="flex items-center gap-3 border-b border-line px-5 py-3">
                    <button type="button" class="text-sm text-brand hover:underline" @click="toggleAll">
                        {{ t('imports.toggle_all') }}
                    </button>
                </div>

                <ul class="divide-y divide-line">
                    <li v-for="row in visibleRows" :key="row.row_number" class="px-5 py-3">
                        <div class="flex items-start gap-3">
                            <input
                                v-model="selected"
                                type="checkbox"
                                :value="row.row_number"
                                :disabled="row.status !== 'valid'"
                                class="mt-1 rounded border-line text-brand focus:ring-accent disabled:opacity-30"
                                :aria-label="`${t('imports.row')} ${row.row_number}`"
                            >

                            <div class="min-w-0 flex-1">
                                <p class="numeral text-sm text-ink">
                                    {{ t('imports.row') }} {{ row.row_number }}
                                    <span v-if="row.normalised" class="text-ink-muted">
                                        · {{ row.normalised.price_type }} · {{ row.normalised.period }}
                                    </span>
                                </p>

                                <!-- Errors name the field, so a 400-row file is
                                     fixable rather than merely rejected. -->
                                <ul v-if="row.errors.length" class="mt-1 space-y-0.5">
                                    <li v-for="(e, i) in row.errors" :key="i" class="text-xs text-negative">
                                        <span class="font-mono" dir="ltr">{{ e.field }}</span>
                                        — {{ t(`imports.codes.${e.code}`) }}
                                    </li>
                                </ul>

                                <ul v-if="row.warnings.length" class="mt-1 space-y-0.5">
                                    <li v-for="(w, i) in row.warnings" :key="i" class="text-xs text-caution">
                                        <span class="font-mono" dir="ltr">{{ w.field }}</span>
                                        — {{ t(`imports.codes.${w.code}`) }}
                                        <span v-if="w.detail" class="numeral" dir="ltr">({{ w.detail }})</span>
                                    </li>
                                </ul>
                            </div>
                        </div>
                    </li>
                </ul>
            </div>
        </template>

        <AppCard :title="t('imports.batches')" class="mt-6">
            <AppEmptyState
                v-if="batches.length === 0"
                :title="t('imports.no_batches')"
                :description="t('imports.no_batches_hint')"
            />

            <ul v-else class="divide-y divide-line">
                <li v-for="batch in batches" :key="batch.id" class="flex items-center gap-4 py-3">
                    <div class="min-w-0 flex-1">
                        <p class="font-mono text-sm text-ink" dir="ltr">{{ batch.reference }}</p>
                        <p class="numeral mt-0.5 text-xs text-ink-muted">
                            {{ batch.accepted_rows }} / {{ batch.total_rows }} · {{ t(`marketplace.statuses.${batch.status}`) }}
                        </p>
                    </div>

                    <!-- Rollback disappears once published: after that a figure
                         may already be in an index someone acted on. -->
                    <AppButton
                        v-if="batch.can_rollback"
                        variant="secondary"
                        size="sm"
                        @click="router.post(`/admin/imports/prices/${batch.id}/rollback`)"
                    >
                        {{ t('imports.rollback') }}
                    </AppButton>
                </li>
            </ul>
        </AppCard>
    </AdminLayout>
</template>
