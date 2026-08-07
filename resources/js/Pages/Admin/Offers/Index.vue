<script setup lang="ts">
import { ref, watch } from 'vue';
import { Head, Link, router } from '@inertiajs/vue3';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import AppCard from '@/Components/ui/AppCard.vue';
import AppButton from '@/Components/ui/AppButton.vue';
import AppEmptyState from '@/Components/ui/AppEmptyState.vue';
import { t, formatNumber } from '@/lib/i18n';

interface Transition { value: string; requires_moderator: boolean }

interface Row {
    id: number;
    title: string;
    company: string | null;
    project: string | null;
    price: string | null;
    currency: string;
    status: string;
    is_pending: boolean;
    is_sponsored: boolean;
    disclosure_label: string | null;
    has_source: boolean;
    allowed_transitions: Transition[];
}

const props = defineProps<{
    offers: { data: Row[] };
    filters: { q: string; status: string; queue: boolean };
    statuses: Array<{ value: string; label: string }>;
    counts: { pending: number; published: number };
    can: { create: boolean; moderate: boolean };
}>();

const filters = ref({ ...props.filters });
let timer: ReturnType<typeof setTimeout> | undefined;

watch(filters, () => {
    clearTimeout(timer);
    timer = setTimeout(() => {
        router.get('/admin/offers', filters.value, { preserveState: true, replace: true });
    }, 300);
}, { deep: true });

function move(offer: Row, status: string): void {
    router.post(`/admin/offers/${offer.id}/transition`, { status }, { preserveScroll: true });
}

const tone = (status: string): string => ({
    published: 'bg-positive/10 text-positive',
    submitted: 'bg-caution/10 text-caution',
    under_review: 'bg-caution/10 text-caution',
    changes_requested: 'bg-caution/10 text-caution',
    rejected: 'bg-negative/10 text-negative',
    expired: 'bg-surface-sunken text-ink-faint',
}[status] ?? 'bg-surface-sunken text-ink-muted');
</script>

<template>
    <Head :title="t('nav.marketplace.offers')" />

    <AdminLayout>
        <template #title>{{ t('nav.marketplace.offers') }}</template>

        <div class="mb-6 grid gap-5 sm:grid-cols-2">
            <AppCard :title="t('marketplace.awaiting_moderation')">
                <p
                    class="numeral font-display text-2xl font-semibold"
                    :class="counts.pending > 0 ? 'text-caution' : 'text-ink-faint'"
                >
                    {{ formatNumber(counts.pending) }}
                </p>
                <p class="mt-1 text-xs text-ink-muted">{{ t('marketplace.awaiting_hint') }}</p>
            </AppCard>

            <AppCard :title="t('marketplace.published_offers')">
                <p class="numeral font-display text-2xl font-semibold text-positive">
                    {{ formatNumber(counts.published) }}
                </p>
            </AppCard>
        </div>

        <div class="mb-6 flex flex-wrap items-end gap-3">
            <div class="min-w-0 flex-1">
                <label for="q" class="mh-label mb-1 block">{{ t('app.actions.search') }}</label>
                <input
                    id="q" v-model="filters.q" type="search"
                    class="w-full rounded-card border border-line bg-surface-raised px-3 py-2 text-ink
                           focus:border-brand focus:outline-none focus:ring-2 focus:ring-accent"
                >
            </div>

            <div>
                <label for="s" class="mh-label mb-1 block">{{ t('projects.publication.status') }}</label>
                <select
                    id="s" v-model="filters.status"
                    class="rounded-card border border-line bg-surface-raised px-3 py-2 text-ink
                           focus:border-brand focus:outline-none focus:ring-2 focus:ring-accent"
                >
                    <option value="">{{ t('app.actions.filter') }}</option>
                    <option v-for="s in statuses" :key="s.value" :value="s.value">{{ s.label }}</option>
                </select>
            </div>

            <label class="flex cursor-pointer items-center gap-2 pb-2 text-sm text-ink-muted">
                <input v-model="filters.queue" type="checkbox" class="rounded border-line text-brand focus:ring-accent">
                {{ t('marketplace.queue_only') }}
            </label>

            <Link v-if="can.create" href="/admin/offers/create">
                <AppButton>{{ t('marketplace.create') }}</AppButton>
            </Link>
        </div>

        <AppEmptyState
            v-if="offers.data.length === 0"
            :title="t('marketplace.empty')"
            :description="t('marketplace.empty_hint')"
        />

        <div v-else class="mh-card overflow-hidden">
            <ul class="divide-y divide-line">
                <li v-for="offer in offers.data" :key="offer.id" class="px-5 py-4">
                    <div class="flex flex-wrap items-start gap-3">
                        <div class="min-w-0 flex-1">
                            <Link
                                :href="`/admin/offers/${offer.id}/edit`"
                                class="truncate font-medium text-ink hover:text-brand
                                         focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-accent"
                            >
                                {{ offer.title }}
                            </Link>

                            <p class="mt-0.5 truncate text-xs text-ink-muted">
                                {{ offer.company ?? t('marketplace.no_company') }}
                                <span v-if="offer.project"> · {{ offer.project }}</span>
                                <span v-if="offer.price" class="numeral" dir="ltr">
                                    · {{ formatNumber(Number(offer.price)) }} {{ offer.currency }}
                                </span>
                            </p>

                            <div class="mt-1.5 flex flex-wrap gap-2">
                                <!-- A sponsored offer always shows its label
                                     here. An undisclosed one cannot be saved,
                                     but showing it makes the state visible to
                                     the moderator deciding. -->
                                <span
                                    v-if="offer.is_sponsored"
                                    class="rounded-full bg-caution/10 px-2 py-0.5 text-xs text-caution"
                                >
                                    {{ offer.disclosure_label ?? t('marketplace.sponsorship.sponsored') }}
                                </span>
                                <span
                                    v-if="!offer.has_source"
                                    class="rounded-full bg-caution/10 px-2 py-0.5 text-xs text-caution"
                                >
                                    {{ t('app.meta.no_source') }}
                                </span>
                            </div>
                        </div>

                        <span :class="['shrink-0 rounded-full px-2.5 py-0.5 text-xs', tone(offer.status)]">
                            {{ t(`marketplace.statuses.${offer.status}`) }}
                        </span>
                    </div>

                    <div v-if="offer.allowed_transitions.length > 0" class="mt-3 flex flex-wrap gap-2">
                        <AppButton
                            v-for="transition in offer.allowed_transitions"
                            :key="transition.value"
                            size="sm"
                            :variant="transition.value === 'published' ? 'primary'
                                : transition.value === 'rejected' ? 'danger' : 'secondary'"
                            :disabled="transition.requires_moderator && !can.moderate"
                            @click="move(offer, transition.value)"
                        >
                            {{ t(`marketplace.statuses.${transition.value}`) }}
                        </AppButton>
                    </div>
                </li>
            </ul>
        </div>
    </AdminLayout>
</template>
