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
    type: string;
    depth: number;
    status: string;
    has_boundary: boolean;
    missing_translations: string[];
}

const props = defineProps<{
    areas: Row[];
    filters: { q: string };
    can: { create: boolean; publish: boolean };
}>();

const search = ref(props.filters.q);
let timer: ReturnType<typeof setTimeout> | undefined;

watch(search, () => {
    clearTimeout(timer);
    timer = setTimeout(() => {
        router.get('/admin/areas', { q: search.value }, { preserveState: true, replace: true });
    }, 300);
});
</script>

<template>
    <Head :title="t('nav.geography.areas')" />

    <AdminLayout>
        <template #title>{{ t('nav.geography.areas') }}</template>

        <!-- Areas gate everything downstream: a project cannot publish while
             area_missing blocks it, and valuation's wider-area fallback walks
             this hierarchy. Worth saying so on an empty list. -->
        <AppAlert v-if="areas.length === 0" variant="info" class="mb-6">
            {{ t('geography.areas_are_prerequisite') }}
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

            <Link v-if="can.create" href="/admin/areas/create">
                <AppButton>{{ t('geography.create_area') }}</AppButton>
            </Link>
        </div>

        <AppEmptyState
            v-if="areas.length === 0"
            :title="t('geography.no_areas')"
            :description="t('geography.no_areas_hint')"
        >
            <template v-if="can.create" #action>
                <Link href="/admin/areas/create">
                    <AppButton>{{ t('geography.create_area') }}</AppButton>
                </Link>
            </template>
        </AppEmptyState>

        <div v-else class="mh-card overflow-hidden">
            <ul class="divide-y divide-line">
                <li v-for="area in areas" :key="area.id">
                    <Link
                        :href="`/admin/areas/${area.id}/edit`"
                        class="flex items-center gap-3 px-5 py-3 transition-colors hover:bg-surface-sunken
                               focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-accent"
                    >
                        <!-- Depth rendered as indentation. The list is ordered
                             by materialised path, so the tree reads correctly
                             without any nesting in the markup. -->
                        <span
                            class="shrink-0"
                            :style="{ inlineSize: `${area.depth * 20}px` }"
                            aria-hidden="true"
                        />
                        <span
                            v-if="area.depth > 0"
                            class="shrink-0 text-ink-faint"
                            aria-hidden="true"
                        >└</span>

                        <div class="min-w-0 flex-1">
                            <p class="truncate font-medium text-ink">{{ area.name }}</p>
                            <p class="mt-0.5 text-xs text-ink-muted">
                                {{ t(`geography.area_types.${area.type}`) }}
                            </p>
                        </div>

                        <div class="flex shrink-0 items-center gap-2">
                            <span
                                v-if="!area.has_boundary"
                                class="rounded-full bg-surface-sunken px-2 py-0.5 text-xs text-ink-faint"
                            >{{ t('geography.no_boundary') }}</span>
                            <span
                                v-if="area.missing_translations.length > 0"
                                class="rounded-full bg-caution/10 px-2 py-0.5 text-xs text-caution"
                            >{{ area.missing_translations.join(' / ') }}</span>
                            <span
                                class="rounded-full px-2.5 py-0.5 text-xs"
                                :class="area.status === 'published'
                                    ? 'bg-positive/10 text-positive'
                                    : 'bg-surface-sunken text-ink-muted'"
                            >{{ t(`projects.publication_statuses.${area.status}`) }}</span>
                        </div>
                    </Link>
                </li>
            </ul>
        </div>
    </AdminLayout>
</template>
