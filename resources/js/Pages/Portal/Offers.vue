<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import AppCard from '@/Components/ui/AppCard.vue';
import AppEmptyState from '@/Components/ui/AppEmptyState.vue';
import AppPagination from '@/Components/ui/AppPagination.vue';
import { t } from '@/lib/i18n';

/*
 * The company's own offers.
 *
 * The lifecycle state is shown verbatim, including changes_requested and
 * rejected. A company that cannot see why its offer is not live will resubmit
 * the same offer — costing a reviewer's time twice and teaching nobody anything.
 */
interface OfferRow {
    id: number;
    public_id: string;
    title: string | null;
    status: string;
    scheduled_for: string | null;
    expires_at: string | null;
    is_sponsored: boolean;
    disclosure_label: string | null;
}

defineProps<{
    offers: { data: OfferRow[]; links: Array<{ url: string | null; label: string; active: boolean }> };
    can_manage: boolean;
}>();

const tone = (status: string): string => ({
    published: 'bg-positive/10 text-positive',
    rejected: 'bg-negative/10 text-negative',
    changes_requested: 'bg-caution/10 text-caution',
    expired: 'bg-surface-sunken text-ink-faint',
}[status] ?? 'bg-surface-sunken text-ink-muted');
</script>

<template>
    <Head :title="t('companies.portal.offers')" />

    <main class="mx-auto max-w-4xl px-4 py-8">
        <Link
            href="/portal"
            class="mb-5 inline-block text-sm text-ink-muted transition-colors hover:text-ink
                   focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-accent"
        >
            {{ t('app.actions.back') }}
        </Link>

        <h1 class="mb-5 font-display text-2xl font-bold text-ink">{{ t('companies.portal.offers') }}</h1>

        <AppEmptyState
            v-if="offers.data.length === 0"
            :title="t('app.states.empty')"
            :description="t('companies.portal.no_offers')"
        />

        <template v-else>
            <AppCard v-for="offer in offers.data" :key="offer.id" class="mb-3">
                <div class="flex flex-wrap items-baseline justify-between gap-3">
                    <p class="min-w-0 flex-1 text-sm font-medium text-ink">{{ offer.title ?? '—' }}</p>

                    <span class="rounded-card px-2 py-0.5 text-xs" :class="tone(offer.status)">
                        {{ t(`marketplace.statuses.${offer.status}`) }}
                    </span>
                </div>

                <p class="numeral mt-1.5 text-xs text-ink-faint" dir="ltr">
                    {{ offer.public_id }}
                    <template v-if="offer.expires_at"> · {{ offer.expires_at }}</template>
                </p>

                <!-- Paid placement is disclosed to the advertiser too, so the
                     label they are shown under is never a surprise. -->
                <p v-if="offer.is_sponsored" class="mt-1.5 text-xs text-caution">
                    {{ offer.disclosure_label ?? t('advertising.disclosure.sponsored') }}
                </p>
            </AppCard>

            <AppPagination :links="offers.links" />
        </template>
    </main>
</template>
