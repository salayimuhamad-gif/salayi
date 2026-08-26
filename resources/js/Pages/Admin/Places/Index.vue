<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import { Head, Link, router } from '@inertiajs/vue3';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import AppButton from '@/Components/ui/AppButton.vue';
import AppEmptyState from '@/Components/ui/AppEmptyState.vue';
import AppAlert from '@/Components/ui/AppAlert.vue';
import { t } from '@/lib/i18n';

interface Row {
    id: number;
    name: string;
    category: string | null;
    area: string | null;
    status: string;
    operational_status: string;
    source: string | null;
    verification_status: string;
    has_source: boolean;
    missing_translations: string[];
}

const props = defineProps<{
    places: { data: Row[] };
    filters: { q: string; category: number; status: string; verification: string; source: string; area: number };
    categories: Array<{ value: number; label: string }>;
    areas: Array<{ value: number; label: string }>;
    sources: string[];
    can: { create: boolean; verify: boolean; update: boolean };
}>();

const search = ref(props.filters.q);
const category = ref(props.filters.category || '');
const status = ref(props.filters.status || '');
const verification = ref(props.filters.verification || '');
const source = ref(props.filters.source || '');
const area = ref(props.filters.area || '');
let timer: ReturnType<typeof setTimeout> | undefined;

watch([search, category, status, verification, source, area], () => {
    clearTimeout(timer);
    timer = setTimeout(() => {
        router.get('/admin/places', {
            q: search.value,
            category: category.value,
            status: status.value,
            verification: verification.value,
            source: source.value,
            area: area.value,
        }, { preserveState: true, replace: true });
    }, 300);
});

/*
 * Bulk review (Map Phase 2). Selection is page-local by design: the server
 * caps one request at 200 ids, and a reviewer approving "everything ever"
 * without looking is exactly what the flow must not encourage.
 */
const selected = ref<number[]>([]);
const bulkBusy = ref(false);

const allSelected = computed(() =>
    props.places.data.length > 0 && selected.value.length === props.places.data.length);

function toggleAll(): void {
    selected.value = allSelected.value ? [] : props.places.data.map((row) => row.id);
}

function toggleRow(id: number): void {
    selected.value = selected.value.includes(id)
        ? selected.value.filter((v) => v !== id)
        : [...selected.value, id];
}

function bulk(action: 'publish' | 'unpublish'): void {
    if (selected.value.length === 0 || bulkBusy.value) return;
    bulkBusy.value = true;

    router.post('/admin/places/bulk-transition', { action, ids: selected.value }, {
        preserveScroll: true,
        onFinish: () => {
            bulkBusy.value = false;
            selected.value = [];
        },
    });
}
</script>

<template>
    <Head :title="t('nav.geography.places')" />

    <AdminLayout>
        <template #title>{{ t('nav.geography.places') }}</template>

        <AppAlert v-if="places.data.length === 0" variant="info" class="mb-6">
            {{ t('geography.places_feed_nearby') }}
        </AppAlert>

        <div class="mb-6 flex flex-wrap items-end gap-3">
            <div class="min-w-0 flex-1">
                <label for="q" class="mh-label mb-1 block">{{ t('app.actions.search') }}</label>
                <input
                    id="q"
                    v-model="search"
                    type="search"
                    class="w-full rounded-card border border-line bg-surface-raised px-3 py-2 text-ink
                           focus:border-brand focus:outline-none focus:ring-2 focus:ring-accent"
                >
            </div>

            <div>
                <label for="cat" class="mh-label mb-1 block">{{ t('geography.fields.category') }}</label>
                <select
                    id="cat"
                    v-model="category"
                    class="rounded-card border border-line bg-surface-raised px-3 py-2 text-ink
                           focus:border-brand focus:outline-none focus:ring-2 focus:ring-accent"
                >
                    <option value="">{{ t('app.actions.filter') }}</option>
                    <option v-for="c in categories" :key="c.value" :value="c.value">{{ c.label }}</option>
                </select>
            </div>

            <!-- Review filters (Map Phase 2): the slice that turns an OSM
                 import's hundreds of drafts back into a workable queue. -->
            <div>
                <label for="status" class="mh-label mb-1 block">{{ t('geography.osm.filters.status') }}</label>
                <select
                    id="status"
                    v-model="status"
                    class="rounded-card border border-line bg-surface-raised px-3 py-2 text-ink
                           focus:border-brand focus:outline-none focus:ring-2 focus:ring-accent"
                >
                    <option value="">{{ t('geography.osm.filters.any') }}</option>
                    <option v-for="s in ['draft', 'in_review', 'published', 'unpublished', 'archived']" :key="s" :value="s">
                        {{ t(`projects.publication_statuses.${s}`) }}
                    </option>
                </select>
            </div>

            <div>
                <label for="source" class="mh-label mb-1 block">{{ t('geography.osm.filters.source') }}</label>
                <select
                    id="source"
                    v-model="source"
                    class="rounded-card border border-line bg-surface-raised px-3 py-2 text-ink
                           focus:border-brand focus:outline-none focus:ring-2 focus:ring-accent"
                >
                    <option value="">{{ t('geography.osm.filters.any') }}</option>
                    <option v-for="s in sources" :key="s" :value="s">{{ s }}</option>
                </select>
            </div>

            <div>
                <label for="area" class="mh-label mb-1 block">{{ t('geography.osm.filters.area') }}</label>
                <select
                    id="area"
                    v-model="area"
                    class="max-w-44 rounded-card border border-line bg-surface-raised px-3 py-2 text-ink
                           focus:border-brand focus:outline-none focus:ring-2 focus:ring-accent"
                >
                    <option value="">{{ t('geography.osm.filters.any') }}</option>
                    <option v-for="a in areas" :key="a.value" :value="a.value">{{ a.label }}</option>
                </select>
            </div>

            <Link v-if="can.create" href="/admin/places/import">
                <AppButton variant="secondary">{{ t('geography.osm.import_link') }}</AppButton>
            </Link>

            <Link v-if="can.create" href="/admin/places/create">
                <AppButton>{{ t('geography.create_place') }}</AppButton>
            </Link>
        </div>

        <AppEmptyState
            v-if="places.data.length === 0"
            :title="t('geography.no_places')"
            :description="t('geography.no_places_hint')"
        >
            <template v-if="can.create" #action>
                <Link href="/admin/places/create">
                    <AppButton>{{ t('geography.create_place') }}</AppButton>
                </Link>
            </template>
        </AppEmptyState>

        <div v-else class="mh-card overflow-hidden">
            <!-- Bulk bar: appears with the update permission; publishing is
                 additionally gated on verify, mirroring the server. -->
            <div v-if="can.update" class="flex flex-wrap items-center gap-3 border-b border-line px-5 py-2.5">
                <label class="flex min-h-11 items-center gap-2 text-sm text-ink-muted">
                    <input
                        type="checkbox"
                        class="h-4 w-4 rounded border-line text-brand focus:ring-accent"
                        :checked="allSelected"
                        @change="toggleAll"
                    >
                    {{ t('geography.osm.selected_count', { count: String(selected.length) }) }}
                </label>

                <div class="ms-auto flex items-center gap-2">
                    <AppButton
                        v-if="can.verify"
                        size="sm"
                        :disabled="selected.length === 0 || bulkBusy"
                        @click="bulk('publish')"
                    >
                        {{ t('geography.osm.bulk_publish') }}
                    </AppButton>
                    <AppButton
                        size="sm"
                        variant="secondary"
                        :disabled="selected.length === 0 || bulkBusy"
                        @click="bulk('unpublish')"
                    >
                        {{ t('geography.osm.bulk_unpublish') }}
                    </AppButton>
                </div>
            </div>

            <ul class="divide-y divide-line">
                <li v-for="place in places.data" :key="place.id" class="flex items-center gap-1 ps-4">
                    <input
                        v-if="can.update"
                        type="checkbox"
                        class="h-4 w-4 shrink-0 rounded border-line text-brand focus:ring-accent"
                        :checked="selected.includes(place.id)"
                        :aria-label="place.name"
                        @change="toggleRow(place.id)"
                    >

                    <Link
                        :href="`/admin/places/${place.id}/edit`"
                        class="flex min-w-0 flex-1 items-center gap-4 px-4 py-3 transition-colors hover:bg-surface-sunken
                               focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-accent"
                    >
                        <div class="min-w-0 flex-1">
                            <p class="truncate font-medium text-ink">{{ place.name }}</p>
                            <p class="mt-0.5 truncate text-xs text-ink-muted">
                                {{ place.category }}<span v-if="place.area"> · {{ place.area }}</span>
                                <span v-if="place.source"> · {{ place.source }}</span>
                            </p>
                        </div>

                        <div class="flex shrink-0 items-center gap-2">
                            <!-- A place with no source cannot appear on a
                                 public profile, so the gap is shown here. -->
                            <span
                                v-if="!place.has_source"
                                class="rounded-full bg-caution/10 px-2 py-0.5 text-xs text-caution"
                            >{{ t('app.meta.no_source') }}</span>

                            <span
                                v-if="place.operational_status !== 'operating'"
                                class="rounded-full bg-surface-sunken px-2 py-0.5 text-xs text-ink-faint"
                            >{{ t(`geography.operational.${place.operational_status}`) }}</span>

                            <span
                                v-if="place.missing_translations.length > 0"
                                class="rounded-full bg-caution/10 px-2 py-0.5 text-xs text-caution"
                            >{{ place.missing_translations.join(' / ') }}</span>

                            <span
                                class="rounded-full px-2.5 py-0.5 text-xs"
                                :class="place.status === 'published'
                                    ? 'bg-positive/10 text-positive'
                                    : 'bg-surface-sunken text-ink-muted'"
                            >{{ t(`projects.publication_statuses.${place.status}`) }}</span>
                        </div>
                    </Link>
                </li>
            </ul>
        </div>
    </AdminLayout>
</template>
