<script setup lang="ts">
import { computed, ref } from 'vue';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import AppButton from '@/Components/ui/AppButton.vue';
import AppCard from '@/Components/ui/AppCard.vue';
import { t } from '@/lib/i18n';

/*
 * OpenStreetMap import (Map Phase 2), for a non-technical administrator:
 * pick a scope, tick the service groups, PREVIEW (writes nothing), read the
 * honest numbers, then confirm. The preview lives server-side in the
 * session; this page only renders it.
 */

interface Preview {
    criteria: { scope: string; area_id: number | null; groups: string[] };
    counts: Record<string, number | boolean>;
    categories: Array<{ key: string; count: number }>;
    sample: Array<{
        name: string;
        category: string;
        external_id: string;
        status: string;
        name_fallback: string | null;
    }>;
    previewed_at: string;
}

const props = defineProps<{
    groups: Array<{ key: string; label: string }>;
    areas: Array<{ value: number; label: string; depth: number }>;
    preview: Preview | null;
    import_cap: number;
}>();

const form = useForm<{ scope: string; area_id: number | ''; groups: string[] }>({
    scope: props.preview?.criteria.scope ?? 'operating_area',
    area_id: props.preview?.criteria.area_id ?? '',
    groups: props.preview?.criteria.groups ?? [],
});

const running = ref(false);

/*
 * Server-side failures that belong to no single field (Overpass down, the
 * preview expired) arrive under keys the typed form doesn't declare.
 */
const generalError = computed(() => {
    const errors = form.errors as Record<string, string | undefined>;

    return errors.overpass ?? errors.preview ?? null;
});

function toggleGroup(key: string): void {
    form.groups = form.groups.includes(key)
        ? form.groups.filter((g) => g !== key)
        : [...form.groups, key];
}

function previewNow(): void {
    form.post('/admin/places/import/preview', { preserveScroll: true });
}

function runImport(): void {
    if (running.value) return;
    running.value = true;
    router.post('/admin/places/import/run', {}, {
        onFinish: () => {
            running.value = false;
        },
    });
}

function discard(): void {
    router.post('/admin/places/import/discard', {}, { preserveScroll: true });
}

// The count tiles worth a headline; the skip breakdown renders as quiet rows.
const skipKeys = [
    'protected', 'deleted_protected', 'foreign_source', 'missing_category',
    'skipped_unmapped', 'skipped_unnamed', 'skipped_out_of_bounds', 'outside_area',
] as const;

const skipRows = computed(() =>
    skipKeys
        .map((key) => ({ key, count: Number(props.preview?.counts[key] ?? 0) }))
        .filter((row) => row.count > 0));

const writable = computed(() =>
    Number(props.preview?.counts.new ?? 0) + Number(props.preview?.counts.refreshable ?? 0));
</script>

<template>
    <Head :title="t('geography.osm.title')" />

    <AdminLayout>
        <template #title>{{ t('geography.osm.title') }}</template>

        <AppCard :title="t('geography.osm.title')" :description="t('geography.osm.intro')" class="mb-6">
            <div class="space-y-5">
                <fieldset>
                    <legend class="mh-label mb-2">{{ t('geography.osm.scope') }}</legend>
                    <div class="flex flex-wrap gap-4">
                        <label class="flex min-h-11 items-center gap-2 text-sm text-ink">
                            <input
                                v-model="form.scope"
                                type="radio"
                                value="operating_area"
                                class="h-4 w-4 border-line text-brand focus:ring-accent"
                            >
                            {{ t('geography.osm.scope_operating_area') }}
                        </label>
                        <label class="flex min-h-11 items-center gap-2 text-sm text-ink">
                            <input
                                v-model="form.scope"
                                type="radio"
                                value="area"
                                class="h-4 w-4 border-line text-brand focus:ring-accent"
                            >
                            {{ t('geography.osm.scope_area') }}
                        </label>
                    </div>

                    <div v-if="form.scope === 'area'" class="mt-3">
                        <label for="area" class="mh-label mb-1 block">{{ t('geography.osm.choose_area') }}</label>
                        <select
                            id="area"
                            v-model="form.area_id"
                            class="w-full max-w-md rounded-card border border-line bg-surface-raised px-3 py-2 text-ink
                                   focus:border-brand focus:outline-none focus:ring-2 focus:ring-accent"
                        >
                            <option value="" disabled>{{ t('geography.osm.choose_area') }}</option>
                            <option v-for="a in areas" :key="a.value" :value="a.value">
                                {{ ' '.repeat(a.depth * 2) }}{{ a.label }}
                            </option>
                        </select>
                        <p v-if="form.errors.area_id" role="alert" class="mt-1 text-xs text-negative">
                            {{ form.errors.area_id }}
                        </p>
                    </div>
                </fieldset>

                <fieldset>
                    <legend class="mh-label mb-2">{{ t('geography.osm.groups_title') }}</legend>
                    <div class="grid grid-cols-2 gap-x-4 gap-y-1 sm:grid-cols-3 lg:grid-cols-5">
                        <label
                            v-for="group in groups"
                            :key="group.key"
                            class="flex min-h-11 items-center gap-2 text-sm text-ink"
                        >
                            <input
                                type="checkbox"
                                class="h-4 w-4 rounded border-line text-brand focus:ring-accent"
                                :checked="form.groups.includes(group.key)"
                                @change="toggleGroup(group.key)"
                            >
                            {{ group.label }}
                        </label>
                    </div>
                    <p v-if="form.errors.groups" role="alert" class="mt-1 text-xs text-negative">
                        {{ form.errors.groups }}
                    </p>
                </fieldset>

                <p
                    v-if="generalError"
                    role="alert"
                    class="rounded-card border border-negative/40 bg-negative/5 px-3 py-2 text-sm text-negative"
                >
                    {{ generalError }}
                </p>

                <div class="flex flex-wrap items-center gap-3">
                    <AppButton :loading="form.processing" :disabled="form.groups.length === 0" @click="previewNow">
                        {{ t('geography.osm.preview_button') }}
                    </AppButton>
                    <p class="text-xs text-ink-faint">{{ t('geography.osm.attribution') }}</p>
                </div>
            </div>
        </AppCard>

        <template v-if="preview">
            <AppCard :title="t('geography.osm.sample_title')" class="mb-6">
                <div class="mb-5 grid grid-cols-2 gap-4 sm:grid-cols-4">
                    <div>
                        <p class="mh-label">{{ t('geography.osm.counts.found') }}</p>
                        <p class="numeral font-display text-xl font-semibold text-ink">{{ preview.counts.found }}</p>
                    </div>
                    <div>
                        <p class="mh-label">{{ t('geography.osm.counts.new') }}</p>
                        <p class="numeral font-display text-xl font-semibold text-positive">{{ preview.counts.new }}</p>
                    </div>
                    <div>
                        <p class="mh-label">{{ t('geography.osm.counts.refreshable') }}</p>
                        <p class="numeral font-display text-xl font-semibold text-ink">{{ preview.counts.refreshable }}</p>
                    </div>
                    <div>
                        <p class="mh-label">{{ t('geography.osm.counts.protected') }}</p>
                        <p class="numeral font-display text-xl font-semibold text-ink-faint">{{ preview.counts.protected }}</p>
                    </div>
                </div>

                <ul v-if="skipRows.length > 0" class="mb-5 space-y-1">
                    <li
                        v-for="row in skipRows"
                        :key="row.key"
                        class="flex items-center justify-between gap-3 text-xs text-ink-muted"
                    >
                        <span>{{ t(`geography.osm.counts.${row.key}`) }}</span>
                        <span class="numeral" dir="ltr">{{ row.count }}</span>
                    </li>
                </ul>

                <p v-if="preview.counts.truncated" class="mb-3 text-xs text-caution">
                    {{ t('geography.osm.truncated') }}
                </p>

                <p v-if="writable > import_cap" class="mb-3 text-xs text-caution">
                    {{ t('geography.osm.cap_notice', { cap: String(import_cap) }) }}
                </p>

                <div v-if="preview.categories.length > 0" class="mb-5">
                    <p class="mh-label mb-1.5">{{ t('geography.osm.per_category') }}</p>
                    <div class="flex flex-wrap gap-1.5">
                        <span
                            v-for="category in preview.categories"
                            :key="category.key"
                            class="rounded-full border border-line px-2.5 py-0.5 text-xs text-ink-muted"
                        >
                            {{ t(`geography.place_categories.${category.key}`) }}
                            <span class="numeral text-ink" dir="ltr">{{ category.count }}</span>
                        </span>
                    </div>
                </div>

                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <tbody class="divide-y divide-line">
                            <tr v-for="row in preview.sample" :key="row.external_id">
                                <td class="max-w-56 truncate py-1.5 pe-3 text-ink">{{ row.name }}</td>
                                <td class="py-1.5 pe-3 text-xs text-ink-muted">
                                    {{ t(`geography.place_categories.${row.category}`) }}
                                </td>
                                <td class="py-1.5 pe-3 text-xs">
                                    <span
                                        class="rounded-full px-2 py-0.5"
                                        :class="row.status === 'new'
                                            ? 'bg-positive/10 text-positive'
                                            : 'bg-surface-sunken text-ink-muted'"
                                    >{{ t(`geography.osm.sample_status_${row.status}`) }}</span>
                                </td>
                                <td class="py-1.5 text-xs text-caution">
                                    <span v-if="row.name_fallback">{{ t('geography.osm.name_fallback_flag') }}</span>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <div class="mt-5 flex flex-wrap items-center gap-3">
                    <AppButton :loading="running" :disabled="writable === 0" @click="runImport">
                        {{ t('geography.osm.import_button') }}
                    </AppButton>
                    <AppButton variant="ghost" :disabled="running" @click="discard">
                        {{ t('geography.osm.discard_button') }}
                    </AppButton>
                    <Link href="/admin/places" class="ms-auto text-sm text-ink-muted hover:text-ink">
                        {{ t('nav.geography.places') }}
                    </Link>
                </div>
            </AppCard>
        </template>
    </AdminLayout>
</template>
