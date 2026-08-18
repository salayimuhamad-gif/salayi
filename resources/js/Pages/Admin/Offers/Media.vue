<script setup lang="ts">
import { computed } from 'vue';
import { Head, router, useForm } from '@inertiajs/vue3';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import AppCard from '@/Components/ui/AppCard.vue';
import AppButton from '@/Components/ui/AppButton.vue';
import AppInput from '@/Components/ui/AppInput.vue';
import AppAlert from '@/Components/ui/AppAlert.vue';
import AppEmptyState from '@/Components/ui/AppEmptyState.vue';
import { t } from '@/lib/i18n';

interface Media {
    id: number; url: string; alt_ckb: string | null;
    is_cover: boolean; moderation_status: string; missing_alt: boolean;
}

const props = defineProps<{
    offer: { id: number; title: string; status: string };
    media: Media[];
    can: { moderate: boolean };
}>();

const upload = useForm<{ file: File | null; alt_ckb: string }>({ file: null, alt_ckb: '' });

const pending = computed(() => props.media.filter((m) => m.moderation_status === 'pending').length);
const approved = computed(() => props.media.filter((m) => m.moderation_status === 'approved').length);

function submit(event: Event): void {
    const input = event.target as HTMLInputElement;
    upload.file = input.files?.[0] ?? null;
    if (upload.file) {
        upload.post(`/admin/offers/${props.offer.id}/media`, {
            forceFormData: true, preserveScroll: true,
            onSuccess: () => upload.reset(),
        });
    }
}

function moderate(item: Media, status: string): void {
    router.post(`/admin/offers/${props.offer.id}/media/${item.id}/moderate`,
                { moderation_status: status }, { preserveScroll: true });
}

const tone = (status: string): string => ({
    approved: 'bg-positive/10 text-positive',
    rejected: 'bg-negative/10 text-negative',
    pending: 'bg-caution/10 text-caution',
}[status] ?? 'bg-surface-sunken text-ink-muted');
</script>

<template>
    <Head :title="t('media.title')" />

    <AdminLayout>
        <template #title>{{ offer.title }} · {{ t('media.title') }}</template>

        <!-- The state that matters: a listing whose images are all pending
             shows no picture to a buyer at all. -->
        <AppAlert v-if="approved === 0 && media.length > 0" variant="warning" class="mb-6">
            {{ t('media.none_approved_notice') }}
        </AppAlert>

        <AppAlert v-else-if="pending > 0" variant="info" class="mb-6">
            {{ t('media.pending_count') }} <span class="numeral">{{ pending }}</span>
        </AppAlert>

        <AppCard :title="t('media.upload')" :description="t('media.offer_upload_hint')" class="mb-6">
            <div class="space-y-5">
                <AppInput v-model="upload.alt_ckb" :label="t('media.alt_text')" :hint="t('media.alt_hint')" />
                <input
                    type="file"
                    accept="image/jpeg,image/png,image/webp,image/avif"
                    class="block w-full text-sm text-ink file:me-4 file:rounded-card file:border-0
                           file:bg-brand file:px-4 file:py-2 file:text-sm file:text-white"
                    @change="submit"
                >
                <p v-if="upload.errors.file" role="alert" class="text-xs text-negative">{{ upload.errors.file }}</p>
            </div>
        </AppCard>

        <AppEmptyState v-if="media.length === 0" :title="t('media.empty')" :description="t('media.empty_hint')" />

        <div v-else class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
            <AppCard v-for="item in media" :key="item.id" :padded="false">
                <div class="relative">
                    <img :src="item.url" :alt="item.alt_ckb ?? ''" class="aspect-video w-full object-cover" loading="lazy">
                    <span :class="['absolute top-2 start-2 rounded-full px-2.5 py-0.5 text-xs', tone(item.moderation_status)]">
                        {{ t(`media.moderation.${item.moderation_status}`) }}
                    </span>
                </div>

                <div class="space-y-3 p-4">
                    <p v-if="item.missing_alt" class="text-xs text-caution">{{ t('media.alt_required') }}</p>
                    <span v-if="item.is_cover" class="inline-block rounded-full bg-positive/10 px-2.5 py-1 text-xs text-positive">
                        {{ t('media.cover') }}
                    </span>

                    <div v-if="can.moderate" class="flex flex-wrap gap-2">
                        <AppButton v-if="item.moderation_status !== 'approved'" size="sm" @click="moderate(item, 'approved')">
                            {{ t('marketplace.statuses.approved') }}
                        </AppButton>
                        <AppButton
                            v-if="item.moderation_status !== 'rejected'" variant="secondary" size="sm"
                            @click="moderate(item, 'rejected')"
                        >
                            {{ t('marketplace.statuses.rejected') }}
                        </AppButton>
                    </div>

                    <AppButton
                        variant="ghost" size="sm"
                        @click="router.delete(`/admin/offers/${offer.id}/media/${item.id}`, { preserveScroll: true })"
                    >
                        {{ t('app.actions.delete') }}
                    </AppButton>
                </div>
            </AppCard>
        </div>
    </AdminLayout>
</template>
