<script setup lang="ts">
import { computed } from 'vue';
import { Head, router, useForm } from '@inertiajs/vue3';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import AppCard from '@/Components/ui/AppCard.vue';
import AppButton from '@/Components/ui/AppButton.vue';
import AppInput from '@/Components/ui/AppInput.vue';
import AppAlert from '@/Components/ui/AppAlert.vue';
import AppEmptyState from '@/Components/ui/AppEmptyState.vue';
import { t, formatNumber } from '@/lib/i18n';

interface Media {
    id: number; url: string; original_name: string;
    width: number | null; height: number | null; size_bytes: number | null;
    alt_ckb: string | null; alt_ar: string | null; alt_en: string | null;
    credit: string | null; is_cover: boolean; missing_alt: boolean;
}

const props = defineProps<{
    project: { id: number; name: string };
    media: Media[];
}>();

const upload = useForm<{ file: File | null; alt_ckb: string; credit: string }>({
    file: null, alt_ckb: '', credit: '',
});

const missingAlt = computed(() => props.media.filter((m) => m.missing_alt).length);

function submit(event: Event): void {
    const input = event.target as HTMLInputElement;
    upload.file = input.files?.[0] ?? null;
    if (upload.file) {
        upload.post(`/admin/projects/${props.project.id}/media`, {
            forceFormData: true,
            preserveScroll: true,
            onSuccess: () => upload.reset(),
        });
    }
}

function setCover(item: Media): void {
    router.put(`/admin/projects/${props.project.id}/media/${item.id}`,
               { is_cover: true, alt_ckb: item.alt_ckb }, { preserveScroll: true });
}

function remove(item: Media): void {
    router.delete(`/admin/projects/${props.project.id}/media/${item.id}`, { preserveScroll: true });
}

function saveAlt(item: Media, value: string): void {
    router.put(`/admin/projects/${props.project.id}/media/${item.id}`,
               { alt_ckb: value }, { preserveScroll: true });
}
</script>

<template>
    <Head :title="t('media.title')" />

    <AdminLayout>
        <template #title>{{ project.name }} · {{ t('media.title') }}</template>

        <!-- Alt text is the most-skipped field in any upload form, and an
             image without it is invisible to a screen reader and to search. -->
        <AppAlert v-if="missingAlt > 0" variant="warning" class="mb-6">
            {{ t('media.missing_alt_notice') }}
            <span class="numeral">{{ missingAlt }}</span>
        </AppAlert>

        <AppCard :title="t('media.upload')" :description="t('media.upload_hint')" class="mb-6">
            <div class="space-y-5">
                <AppInput v-model="upload.alt_ckb" :label="t('media.alt_text')" :hint="t('media.alt_hint')" />
                <AppInput v-model="upload.credit" :label="t('media.credit')" />

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

        <AppEmptyState
            v-if="media.length === 0"
            :title="t('media.empty')"
            :description="t('media.empty_hint')"
        />

        <div v-else class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
            <AppCard v-for="item in media" :key="item.id" :padded="false">
                <img :src="item.url" :alt="item.alt_ckb ?? ''" class="aspect-video w-full object-cover" loading="lazy">

                <div class="space-y-3 p-4">
                    <AppInput
                        :model-value="item.alt_ckb ?? ''"
                        :label="t('media.alt_text')"
                        :error="item.missing_alt ? t('media.alt_required') : null"
                        @update:model-value="(v: string) => saveAlt(item, v)"
                    />

                    <p class="numeral text-xs text-ink-faint" dir="ltr">
                        {{ item.width }}×{{ item.height }}
                        <span v-if="item.size_bytes">· {{ formatNumber(Math.round(item.size_bytes / 1024)) }} KB</span>
                    </p>

                    <div class="flex flex-wrap gap-2">
                        <AppButton v-if="!item.is_cover" variant="secondary" size="sm" @click="setCover(item)">
                            {{ t('media.set_cover') }}
                        </AppButton>
                        <span v-else class="rounded-full bg-positive/10 px-2.5 py-1 text-xs text-positive">
                            {{ t('media.cover') }}
                        </span>
                        <AppButton variant="ghost" size="sm" @click="remove(item)">
                            {{ t('app.actions.delete') }}
                        </AppButton>
                    </div>
                </div>
            </AppCard>
        </div>
    </AdminLayout>
</template>
