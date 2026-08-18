<script setup lang="ts">
import { computed, ref } from 'vue';
import { Head, router, useForm } from '@inertiajs/vue3';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import AppCard from '@/Components/ui/AppCard.vue';
import AppButton from '@/Components/ui/AppButton.vue';
import AppInput from '@/Components/ui/AppInput.vue';
import AppSelect from '@/Components/ui/AppSelect.vue';
import AppAlert from '@/Components/ui/AppAlert.vue';
import AppEmptyState from '@/Components/ui/AppEmptyState.vue';
import { t, formatNumber } from '@/lib/i18n';

interface Option { value: string; label: string; family?: string }

interface Index_ {
    id: number;
    key: string;
    name: string;
    scope_type: string;
    property_type: string | null;
    price_type: string;
    family: string;
    requires_qualifier: boolean;
    basis: string;
    currency: string;
    methodology_version: string;
    minimum_sample: number;
    status: string;
    value_count: number;
    latest: { period: string; value: string | null; confidence: string; sample_size: number } | null;
}

const props = defineProps<{
    indices: Index_[];
    options: {
        scope_types: Option[];
        property_types: Option[];
        price_types: Option[];
        bases: Option[];
    };
    can: { manage: boolean };
}>();

const creating = ref(false);

const form = useForm({
    key: '',
    name_ckb: '',
    name_ar: '',
    name_en: '',
    scope_type: 'city',
    scope_id: null as number | null,
    property_type: '',
    price_type: 'sale_verified',
    basis: 'median',
    currency: 'USD',
    methodology_version: 'v1',
    minimum_sample: 5,
});

// The family of the selected price type drives the qualifier warning: an index
// built from asking prices reads as a market rate unless it says otherwise.
const selectedFamily = computed(
    () => props.options.price_types.find((p) => p.value === form.price_type)?.family ?? null,
);

function create(): void {
    form.post('/admin/market/indices', {
        preserveScroll: true,
        onSuccess: () => { form.reset(); creating.value = false; },
    });
}

const tone = (family: string): string => ({
    verified: 'bg-positive/10 text-positive',
    official: 'bg-brand/10 text-brand',
    asking: 'bg-caution/10 text-caution',
}[family] ?? 'bg-surface-sunken text-ink-muted');
</script>

<template>
    <Head :title="t('nav.market.indices')" />

    <AdminLayout>
        <template #title>{{ t('nav.market.indices') }}</template>

        <div v-if="can.manage" class="mb-6 flex justify-end">
            <AppButton v-if="!creating" @click="creating = true">
                {{ t('market.indices.create') }}
            </AppButton>
        </div>

        <AppCard v-if="creating" :title="t('market.indices.create')" class="mb-6">
            <form class="space-y-5" @submit.prevent="create">
                <AppInput
                    v-model="form.key" :label="t('market.indices.key')" :error="form.errors.key"
                    :hint="t('market.indices.key_hint')" dir="ltr" required
                />
                <AppInput v-model="form.name_ckb" :label="t('projects.fields.name_ckb')" :error="form.errors.name_ckb" required />
                <AppInput v-model="form.name_en" :label="t('projects.fields.name_en')" :error="form.errors.name_en" dir="ltr" />

                <div class="grid gap-5 sm:grid-cols-2">
                    <AppSelect
                        v-model="form.scope_type" :label="t('market.indices.scope')"
                        :options="options.scope_types" :error="form.errors.scope_type" required
                    />
                    <AppSelect
                        v-model="form.property_type" :label="t('market.indices.property_type')"
                        :options="options.property_types" :placeholder="t('market.indices.all_types')"
                        :error="form.errors.property_type"
                    />
                </div>

                <AppSelect
                    v-model="form.price_type" :label="t('market.price_type')"
                    :options="options.price_types" :error="form.errors.price_type" required
                />

                <!-- An index declares exactly one price type and it cannot be
                     changed once published, because that would silently
                     redefine every historical value beneath it. -->
                <AppAlert v-if="selectedFamily === 'asking'" variant="warning">
                    {{ t('market.indices.asking_warning') }}
                </AppAlert>
                <AppAlert v-else-if="selectedFamily === 'official'" variant="info">
                    {{ t('market.indices.official_warning') }}
                </AppAlert>

                <div class="grid gap-5 sm:grid-cols-3">
                    <AppSelect
                        v-model="form.basis" :label="t('market.indices.basis')"
                        :options="options.bases" :error="form.errors.basis" required
                    />
                    <AppInput
                        v-model="form.minimum_sample" :label="t('market.indices.minimum_sample')"
                        :hint="t('market.indices.minimum_sample_hint')"
                        :error="form.errors.minimum_sample" dir="ltr" required
                    />
                    <AppInput
                        v-model="form.methodology_version" :label="t('market.indices.version')"
                        :error="form.errors.methodology_version" dir="ltr" required
                    />
                </div>

                <div class="flex justify-end gap-2">
                    <AppButton variant="ghost" size="sm" @click="creating = false">
                        {{ t('app.actions.cancel') }}
                    </AppButton>
                    <AppButton type="submit" size="sm" :loading="form.processing">
                        {{ t('app.actions.save') }}
                    </AppButton>
                </div>
            </form>
        </AppCard>

        <AppEmptyState
            v-if="indices.length === 0"
            :title="t('market.indices.none')"
            :description="t('market.indices.none_hint')"
        />

        <div v-else class="space-y-4">
            <AppCard v-for="index in indices" :key="index.id" :title="index.name">
                <div class="flex flex-wrap items-start justify-between gap-4">
                    <div class="min-w-0 flex-1 space-y-2">
                        <div class="flex flex-wrap items-center gap-2">
                            <span :class="['rounded-full px-2 py-0.5 text-xs', tone(index.family)]">
                                {{ t(`market.price_types.${index.price_type}`) }}
                            </span>
                            <span class="font-mono text-xs text-ink-muted" dir="ltr">{{ index.key }}</span>
                            <span class="numeral text-xs text-ink-faint" dir="ltr">
                                {{ index.basis }} · {{ index.currency }} · {{ index.methodology_version }}
                            </span>
                        </div>

                        <p v-if="index.requires_qualifier" class="text-xs text-caution">
                            {{ t('market.indices.qualifier_required') }}
                        </p>

                        <p v-if="index.latest" class="numeral text-sm text-ink" dir="ltr">
                            {{ index.latest.period }}:
                            {{ index.latest.value ? formatNumber(Number(index.latest.value)) : '—' }}
                            <span class="text-ink-muted">
                                (n={{ index.latest.sample_size }}, {{ index.latest.confidence }})
                            </span>
                        </p>
                        <p v-else class="text-xs text-ink-faint">{{ t('market.indices.never_built') }}</p>
                    </div>

                    <div class="flex shrink-0 flex-col items-end gap-2">
                        <span
                            class="rounded-full px-2.5 py-0.5 text-xs"
                            :class="index.status === 'published' ? 'bg-positive/10 text-positive' : 'bg-surface-sunken text-ink-muted'"
                        >
                            {{ t(`projects.publication_statuses.${index.status}`) }}
                        </span>

                        <span class="numeral text-xs text-ink-faint">
                            {{ index.value_count }} {{ t('market.indices.values') }}
                        </span>

                        <AppButton
                            v-if="can.manage" variant="secondary" size="sm"
                            @click="router.post(`/admin/market/indices/${index.id}/rebuild`)"
                        >
                            {{ t('market.indices.rebuild') }}
                        </AppButton>
                    </div>
                </div>
            </AppCard>
        </div>
    </AdminLayout>
</template>
