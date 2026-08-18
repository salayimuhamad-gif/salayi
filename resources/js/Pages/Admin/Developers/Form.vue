<script setup lang="ts">
import { computed } from 'vue';
import { Head, useForm } from '@inertiajs/vue3';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import AppCard from '@/Components/ui/AppCard.vue';
import AppButton from '@/Components/ui/AppButton.vue';
import AppInput from '@/Components/ui/AppInput.vue';
import AppToggle from '@/Components/ui/AppToggle.vue';
import { t } from '@/lib/i18n';

const props = defineProps<{ developer: Record<string, unknown> | null }>();

const isEdit = computed(() => props.developer !== null);
const developerId = computed(() => Number(props.developer?.id ?? 0));
const str = (key: string, fallback = ''): string => String(props.developer?.[key] ?? fallback);

const form = useForm({
    name_ckb: str('name_ckb'),
    name_ar: str('name_ar'),
    name_en: str('name_en'),
    slug: str('slug'),
    description_ckb: str('description_ckb'),
    description_ar: str('description_ar'),
    description_en: str('description_en'),
    website: str('website'),
    founded_year: props.developer?.founded_year ? Number(props.developer.founded_year) : null,
    country: str('country'),
    source: str('source'),
    is_verified: Boolean(props.developer?.is_verified ?? false),
});

function submit(): void {
    if (isEdit.value) {
        form.put(`/admin/developers/${developerId.value}`, { preserveScroll: true });
    } else {
        form.post('/admin/developers');
    }
}

function deriveSlug(): void {
    if (isEdit.value || form.slug) return;
    form.slug = String(form.name_en || form.name_ckb)
        .toLowerCase().trim()
        .replace(/[^\p{L}\p{N}]+/gu, '-')
        .replace(/^-+|-+$/g, '');
}
</script>

<template>
    <Head :title="isEdit ? form.name_ckb : t('projects.developers.create')" />

    <AdminLayout>
        <template #title>{{ isEdit ? form.name_ckb : t('projects.developers.create') }}</template>

        <form class="space-y-6" @submit.prevent="submit">
            <AppCard :title="t('projects.wizard.identity')">
                <div class="space-y-5">
                    <AppInput v-model="form.name_ckb" :label="t('projects.fields.name_ckb')" :error="form.errors.name_ckb" required @blur="deriveSlug" />
                    <AppInput v-model="form.name_ar" :label="t('projects.fields.name_ar')" :error="form.errors.name_ar" />
                    <AppInput v-model="form.name_en" :label="t('projects.fields.name_en')" :error="form.errors.name_en" dir="ltr" />
                    <AppInput v-model="form.slug" :label="t('projects.fields.slug')" :error="form.errors.slug" dir="ltr" required />
                    <AppInput v-model="form.website" :label="t('projects.fields.official_url')" :error="form.errors.website" dir="ltr" />
                    <div class="grid gap-5 sm:grid-cols-2">
                        <AppInput v-model="form.founded_year" :label="t('projects.developers.founded')" :error="form.errors.founded_year" dir="ltr" />
                        <AppInput v-model="form.country" :label="t('projects.developers.country')" :error="form.errors.country" :hint="t('projects.developers.country_hint')" dir="ltr" />
                    </div>
                </div>
            </AppCard>

            <AppCard :title="t('companies.verification.title')" :description="t('projects.developers.verification_hint')">
                <AppToggle
                    v-model="form.is_verified"
                    :label="t('companies.verification.verified')"
                    :description="t('projects.developers.verification_meaning')"
                />
                <p v-if="form.errors.is_verified" role="alert" class="mt-2 text-xs text-negative">
                    {{ form.errors.is_verified }}
                </p>
            </AppCard>

            <AppCard :title="t('projects.wizard.sources')">
                <AppInput v-model="form.source" :label="t('projects.fields.source')" :error="form.errors.source" />
            </AppCard>

            <div class="flex justify-end">
                <AppButton type="submit" :loading="form.processing">
                    {{ isEdit ? t('app.actions.save') : t('projects.developers.create') }}
                </AppButton>
            </div>
        </form>
    </AdminLayout>
</template>
