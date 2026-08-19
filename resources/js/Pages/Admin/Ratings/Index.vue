<script setup lang="ts">
import { computed } from 'vue';
import { Head, router, useForm } from '@inertiajs/vue3';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import AppCard from '@/Components/ui/AppCard.vue';
import AppButton from '@/Components/ui/AppButton.vue';
import AppInput from '@/Components/ui/AppInput.vue';
import AppSelect from '@/Components/ui/AppSelect.vue';
import AppAlert from '@/Components/ui/AppAlert.vue';
import AppEmptyState from '@/Components/ui/AppEmptyState.vue';
import RatingPanel from '@/Components/RatingPanel.vue';
import { t } from '@/lib/i18n';

interface Rating {
    id: number; category: string; type: string; value: string;
    sample_size: number | null; reason: string | null; source: string | null;
    review_status: string; contributes_to_official: boolean; minimum_sample: number;
}

interface TypeOption { value: string; label: string; contributes: boolean; minimum_sample: number; ai_generated: boolean }

const props = defineProps<{
    project: { id: number; name: string };
    ratings: Rating[];
    preview: { categories: never[]; has_any: boolean; official_count: number };
    options: {
        categories: Array<{ value: string; label: string; group: string; inverted: boolean }>;
        types: TypeOption[];
    };
    can: { review: boolean };
}>();

const form = useForm({
    category: 'overall_quality',
    type: 'internal_expert',
    value: '',
    sample_size: null as number | null,
    reason: '',
    source: '',
});

const selectedType = computed(() => props.options.types.find((t_) => t_.value === form.type));

// A type that feeds the official score is a public fact and needs a source.
const sourceRequired = computed(() => selectedType.value?.contributes ?? false);

const pending = computed(() => props.ratings.filter((r) => r.review_status === 'pending'));

function submit(): void {
    form.post(`/admin/projects/${props.project.id}/ratings`, {
        preserveScroll: true,
        onSuccess: () => form.reset('value', 'reason', 'source'),
    });
}

function review(rating: Rating, status: string): void {
    router.post(`/admin/projects/${props.project.id}/ratings/${rating.id}/review`,
                { review_status: status }, { preserveScroll: true });
}
</script>

<template>
    <Head :title="t('projects.ratings.title')" />

    <AdminLayout>
        <template #title>{{ project.name }} · {{ t('projects.ratings.title') }}</template>

        <AppAlert variant="info" class="mb-6">
            {{ t('projects.ratings.review_notice') }}
        </AppAlert>

        <AppCard :title="t('projects.ratings.add')" class="mb-6">
            <form class="space-y-5" @submit.prevent="submit">
                <AppSelect
                    v-model="form.category" :label="t('projects.ratings.category')"
                    :options="options.categories" :error="form.errors.category" required
                />

                <AppSelect
                    v-model="form.type" :label="t('projects.ratings.type')"
                    :options="options.types" :error="form.errors.type" required
                />

                <!-- Below its minimum sample a type is not shown as an
                     aggregate at all. Stated while the editor chooses, so a
                     single public-user rating is not entered expecting it to
                     appear. -->
                <AppAlert v-if="selectedType && selectedType.minimum_sample > 1" variant="warning">
                    {{ t('projects.ratings.minimum_sample_notice') }}
                    <span class="numeral">{{ selectedType.minimum_sample }}</span>
                </AppAlert>

                <AppInput
                    v-model="form.value" :label="t('projects.ratings.value')"
                    :hint="t('projects.ratings.value_hint')" :error="form.errors.value" dir="ltr" required
                />

                <AppInput
                    v-model="form.sample_size" :label="t('market.explanation.sample')"
                    :error="form.errors.sample_size" dir="ltr"
                />

                <AppInput
                    v-model="form.source" :label="t('projects.fields.source')"
                    :hint="sourceRequired ? t('projects.ratings.source_required') : undefined"
                    :error="form.errors.source" :required="sourceRequired"
                />

                <AppInput v-model="form.reason" :label="t('projects.ratings.reason')" :error="form.errors.reason" />

                <div class="flex justify-end">
                    <AppButton type="submit" size="sm" :loading="form.processing">
                        {{ t('app.actions.add') }}
                    </AppButton>
                </div>
            </form>
        </AppCard>

        <AppCard v-if="pending.length > 0" :title="t('projects.ratings.awaiting_review')" class="mb-6">
            <ul class="divide-y divide-line">
                <li v-for="rating in pending" :key="rating.id" class="flex items-center gap-3 py-3">
                    <div class="min-w-0 flex-1">
                        <p class="text-sm text-ink">
                            {{ t(`projects.rating_categories.${rating.category}`) }}
                            <span class="numeral text-ink-muted" dir="ltr">· {{ rating.value }}/5</span>
                        </p>
                        <p class="mt-0.5 text-xs text-ink-muted">
                            {{ t(`projects.rating_types.${rating.type}`) }}
                            <span v-if="rating.source"> · {{ rating.source }}</span>
                        </p>
                    </div>

                    <div v-if="can.review" class="flex shrink-0 gap-2">
                        <AppButton variant="secondary" size="sm" @click="review(rating, 'rejected')">
                            {{ t('marketplace.statuses.rejected') }}
                        </AppButton>
                        <AppButton size="sm" @click="review(rating, 'approved')">
                            {{ t('marketplace.statuses.approved') }}
                        </AppButton>
                    </div>
                </li>
            </ul>
        </AppCard>

        <AppCard :title="t('projects.ratings.public_preview')" :description="t('projects.ratings.preview_hint')">
            <AppEmptyState
                v-if="!preview.has_any"
                :title="t('projects.ratings.nothing_public')"
                :description="t('projects.ratings.nothing_public_hint')"
            />
            <RatingPanel v-else :ratings="preview" />
        </AppCard>
    </AdminLayout>
</template>
