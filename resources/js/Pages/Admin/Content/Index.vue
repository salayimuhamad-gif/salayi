<script setup lang="ts">
import { ref } from 'vue';
import { Head, router } from '@inertiajs/vue3';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import AppCard from '@/Components/ui/AppCard.vue';
import AppButton from '@/Components/ui/AppButton.vue';
import AppEmptyState from '@/Components/ui/AppEmptyState.vue';
import AppPagination from '@/Components/ui/AppPagination.vue';
import { t } from '@/lib/i18n';

/*
 * Content administration (File one §10).
 *
 * Status and visibility are shown as two separate things, because they are two
 * separate things: an item can be `published` and invisible because its
 * unpublish window has closed. Showing only the status would leave an editor
 * hunting for why a live article is not on the site.
 */
interface Item {
    id: number;
    type: string;
    slug: string;
    title: string;
    status: string;
    is_visible: boolean;
    publish_at: string | null;
    unpublish_at: string | null;
    author: string | null;
    updated_at: string | null;
}

defineProps<{
    items: { data: Item[]; links: Array<{ url: string | null; label: string; active: boolean }> };
    types: string[];
    filters: { type?: string; status?: string };
    can: { publish: boolean };
}>();

const type = ref('');

function filter(): void {
    router.get('/admin/content', { type: type.value || undefined }, { preserveState: true, replace: true });
}

function publish(id: number): void {
    router.post(`/admin/content/${id}/publish`, {}, { preserveScroll: true });
}

function unpublish(id: number): void {
    router.post(`/admin/content/${id}/unpublish`, {}, { preserveScroll: true });
}
</script>

<template>
    <Head :title="t('content.title')" />

    <AdminLayout>
        <template #title>{{ t('content.title') }}</template>

        <div class="mb-5">
            <select
                v-model="type"
                class="rounded-card border border-line bg-surface-raised px-3 py-2 text-sm text-ink
                       focus:border-brand focus:outline-none focus:ring-2 focus:ring-accent"
                @change="filter"
            >
                <option value="">—</option>
                <option v-for="option in types" :key="option" :value="option">
                    {{ t(`content.types.${option}`) }}
                </option>
            </select>
        </div>

        <AppEmptyState v-if="items.data.length === 0" :title="t('content.none')" />

        <template v-else>
            <AppCard v-for="item in items.data" :key="item.id" class="mb-3">
                <div class="flex flex-wrap items-baseline justify-between gap-3">
                    <p class="min-w-0 flex-1 text-sm font-medium text-ink">{{ item.title }}</p>

                    <span class="rounded-card bg-surface-sunken px-2 py-0.5 text-xs text-ink-muted">
                        {{ t(`content.types.${item.type}`) }}
                    </span>

                    <!-- Two badges, deliberately. 'published' and 'live' are
                         not the same claim once a window is involved. -->
                    <span
                        class="rounded-card px-2 py-0.5 text-xs"
                        :class="item.status === 'published'
                            ? 'bg-positive/10 text-positive'
                            : 'bg-surface-sunken text-ink-muted'"
                    >{{ item.status }}</span>

                    <span
                        v-if="item.status === 'published' && !item.is_visible"
                        class="rounded-card bg-caution/10 px-2 py-0.5 text-xs text-caution"
                    >{{ t('content.not_live') }}</span>
                </div>

                <p class="numeral mt-1.5 text-xs text-ink-faint" dir="ltr">
                    {{ item.slug }}
                    <template v-if="item.publish_at"> · {{ item.publish_at }}</template>
                    <template v-if="item.unpublish_at"> → {{ item.unpublish_at }}</template>
                </p>

                <div v-if="can.publish" class="mt-3 flex gap-2">
                    <AppButton
                        v-if="item.status !== 'published'"
                        variant="primary" size="sm"
                        @click="publish(item.id)"
                    >
                        {{ t('content.publish') }}
                    </AppButton>

                    <AppButton v-else variant="ghost" size="sm" @click="unpublish(item.id)">
                        {{ t('content.unpublish') }}
                    </AppButton>
                </div>
            </AppCard>

            <AppPagination :links="items.links" />
        </template>
    </AdminLayout>
</template>
