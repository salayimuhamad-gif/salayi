<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import AppCard from '@/Components/ui/AppCard.vue';
import AppAlert from '@/Components/ui/AppAlert.vue';
import { t, formatNumber } from '@/lib/i18n';

/*
 * The company portal shell (File one §8.2).
 *
 * The acting company is named prominently and permanently. A broker who works
 * for two agencies and cannot tell at a glance which one they are acting for is
 * one click from publishing an offer under the wrong brand — and that mistake
 * is visible to the market before anybody notices it internally.
 */
defineProps<{
    company: {
        id: number;
        name: string;
        verification_status: string;
        subscription_plan: string | null;
    };
    capabilities: { manage_offers: boolean; view_leads: boolean; view_lead_contacts: boolean };
    counts: { offers: number; published_offers: number; branches: number; staff: number };
    memberships: Array<{ company_id: number; company_name: string | null; role: string }>;
}>();

function switchTo(companyId: number): void {
    router.post('/portal/switch', { company_id: companyId }, { preserveScroll: true });
}
</script>

<template>
    <Head :title="t('companies.portal.title')" />

    <main class="mx-auto max-w-4xl px-4 py-8">
        <header class="mb-6">
            <p class="mh-label">{{ t('companies.portal.title') }}</p>
            <h1 class="mt-1 font-display text-2xl font-bold text-ink">{{ company.name }}</h1>

            <!-- An unverified company should know it is unverified before it
                 wonders why nothing it publishes appears. -->
            <AppAlert v-if="company.verification_status !== 'verified'" variant="warning" class="mt-4">
                {{ t('companies.portal.not_verified') }}
            </AppAlert>
        </header>

        <!-- Only rendered when the person genuinely belongs to more than one. -->
        <AppCard v-if="memberships.length > 1" class="mb-6" :title="t('companies.portal.acting_as')">
            <div class="flex flex-wrap gap-2">
                <button
                    v-for="membership in memberships"
                    :key="membership.company_id"
                    type="button"
                    class="rounded-card border px-3 py-1.5 text-sm transition-colors
                           focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-accent"
                    :class="membership.company_id === company.id
                        ? 'border-brand bg-brand text-white'
                        : 'border-line text-ink-muted hover:bg-surface-sunken'"
                    :aria-pressed="membership.company_id === company.id"
                    @click="switchTo(membership.company_id)"
                >
                    {{ membership.company_name }}
                </button>
            </div>
        </AppCard>

        <div class="grid gap-5 sm:grid-cols-2 lg:grid-cols-4">
            <AppCard :title="t('companies.portal.offers')">
                <p class="numeral font-display text-2xl font-semibold text-ink">
                    {{ formatNumber(counts.offers) }}
                </p>
                <p class="numeral mt-1 text-xs text-ink-muted">
                    {{ formatNumber(counts.published_offers) }} {{ t('companies.portal.published') }}
                </p>
            </AppCard>

            <AppCard :title="t('companies.portal.branches')">
                <p class="numeral font-display text-2xl font-semibold text-ink">
                    {{ formatNumber(counts.branches) }}
                </p>
            </AppCard>

            <AppCard :title="t('companies.portal.staff')">
                <p class="numeral font-display text-2xl font-semibold text-ink">
                    {{ formatNumber(counts.staff) }}
                </p>
            </AppCard>

            <AppCard :title="t('companies.portal.plan')">
                <p class="text-sm text-ink">{{ company.subscription_plan ?? '—' }}</p>
            </AppCard>
        </div>

        <nav class="mt-6 flex flex-wrap gap-3">
            <Link
                v-if="capabilities.manage_offers"
                href="/portal/offers"
                class="rounded-card bg-brand px-4 py-2 text-sm font-medium text-white transition-opacity
                       hover:opacity-90 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-accent"
            >
                {{ t('companies.portal.offers') }}
            </Link>
        </nav>
    </main>
</template>
