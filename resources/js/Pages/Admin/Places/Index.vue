<script setup lang="ts">
import { ref, watch } from 'vue';
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
    has_source: boolean;
    missing_translations: string[];
}

const props = defineProps<{
    places: { data: Row[] };
    filters: { q: string; category: number };
    categories: Array<{ value: number; label: string }>;
    can: { create: boolean };
}>();

const search = ref(props.filters.q);
const category = ref(props.filters.category || '');
let timer: ReturnType<typeof setTimeout> | undefined;

watch([search, category], () => {
    clearTimeout(timer);
    timer = setTimeout(() => {
        router.get('/admin/places', { q: search.value, category: category.value }, {
            preserveState: true, replace: true,
        });
    }, 300);
});
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
            <ul class="divide-y divide-line">
                <li v-for="place in places.data" :key="place.id">
                    <Link
                        :href="`/admin/places/${place.id}/edit`"
                        class="flex items-center gap-4 px-5 py-3 transition-colors hover:bg-surface-sunken
                               focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-accent"
                    >
                        <div class="min-w-0 flex-1">
                            <p class="truncate font-medium text-ink">{{ place.name }}</p>
                            <p class="mt-0.5 truncate text-xs text-ink-muted">
                                {{ place.category }}<span v-if="place.area"> · {{ place.area }}</span>
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
