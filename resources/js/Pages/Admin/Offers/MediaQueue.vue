<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import AppCard from '@/Components/ui/AppCard.vue';
import AppButton from '@/Components/ui/AppButton.vue';
import AppEmptyState from '@/Components/ui/AppEmptyState.vue';
import { t } from '@/lib/i18n';

interface Pending {
    id: number; offer_id: number; offer_title: string | null;
    url: string; alt_ckb: string | null; original_name: string | null;
}

defineProps<{ pending: Pending[]; can: { moderate: boolean } }>();

function moderate(item: Pending, status: string): void {
    router.post(`/admin/offers/${item.offer_id}/media/${item.id}/moderate`,
                { moderation_status: status }, { preserveScroll: true });
}
</script>

<template>
    <Head :title="t('media.queue')" />

    <AdminLayout>
        <template #title>{{ t('media.queue') }}</template>

        <AppEmptyState
            v-if="pending.length === 0"
            :title="t('media.queue_empty')"
            :description="t('media.queue_empty_hint')"
        />

        <div v-else class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
            <AppCard v-for="item in pending" :key="item.id" :padded="false">
                <img :src="item.url" :alt="item.alt_ckb ?? ''" class="aspect-video w-full object-cover" loading="lazy">

                <div class="space-y-3 p-4">
                    <p class="truncate text-sm text-ink">{{ item.offer_title }}</p>
                    <p v-if="!item.alt_ckb" class="text-xs text-caution">{{ t('media.alt_required') }}</p>

                    <div v-if="can.moderate" class="flex gap-2">
                        <AppButton size="sm" @click="moderate(item, 'approved')">
                            {{ t('marketplace.statuses.approved') }}
                        </AppButton>
                        <AppButton variant="secondary" size="sm" @click="moderate(item, 'rejected')">
                            {{ t('marketplace.statuses.rejected') }}
                        </AppButton>
                    </div>
                </div>
            </AppCard>
        </div>
    </AdminLayout>
</template>
