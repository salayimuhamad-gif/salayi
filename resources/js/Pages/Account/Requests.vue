<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import AuthLayout from '@/Layouts/AuthLayout.vue';
import AppCard from '@/Components/ui/AppCard.vue';
import AppEmptyState from '@/Components/ui/AppEmptyState.vue';
import AppPagination from '@/Components/ui/AppPagination.vue';
import { t, formatNumber } from '@/lib/i18n';

/*
 * "My requests" (spec §7.3): each card is one submission — the advisor's
 * localized summary of what the person asked for, and where it stands. The
 * stage renders through its translation; the raw pipeline word never shows.
 */
interface RequestRow {
    id: number;
    stage: string;
    summary: string | null;
    summary_locale: string | null;
    objective: string | null;
    property_type: string | null;
    budget_max: number | null;
    currency: string | null;
    currency_source: string | null;
    submitted_at: string | null;
    updated_at: string | null;
}

/*
 * H9: paginated (20 per page, fixed server-side) — the cards read `data`,
 * the links feed AppPagination.
 */
interface PaginationLink { url: string | null; label: string; active: boolean }

defineProps<{ requests: { data: RequestRow[]; links: PaginationLink[] } }>();

function stageLabel(stage: string): string {
    const key = `leads.requests.stage_${stage}`;
    const label = t(key);

    // A stage this build does not know renders as "in progress" rather than
    // as a translation key.
    return label === key ? t('leads.requests.stage_in_progress') : label;
}
</script>

<template>
    <Head :title="t('leads.requests.title')" />

    <AuthLayout :title="t('leads.requests.title')" :subtitle="t('leads.requests.subtitle')">
        <AppEmptyState
            v-if="requests.data.length === 0"
            :title="t('leads.requests.empty_title')"
            :description="t('leads.requests.empty_body')"
        />

        <div v-else class="space-y-4">
            <AppCard v-for="row in requests.data" :key="row.id">
                <div class="space-y-3 p-5">
                    <div class="flex flex-wrap items-center justify-between gap-2">
                        <span
                            class="inline-flex items-center rounded-full bg-brand-soft px-3 py-1 text-xs
                                   font-medium text-brand"
                        >
                            {{ stageLabel(row.stage) }}
                        </span>
                        <span class="text-xs text-ink-faint">
                            {{ t('leads.requests.submitted') }}: {{ row.submitted_at }}
                        </span>
                    </div>

                    <p
                        v-if="row.summary"
                        class="whitespace-pre-line text-sm leading-relaxed text-ink"
                        :dir="row.summary_locale === 'en' ? 'ltr' : 'rtl'"
                    >
                        {{ row.summary }}
                    </p>

                    <p v-if="row.budget_max" class="text-xs text-ink-muted" dir="ltr">
                        <!--
                            v4 BLOCKER 3: never print an amount beside a
                            currency nobody stated. A null currency renders
                            the amount with an explicit "currency not
                            stated" label instead of silently inheriting
                            the old USD assumption.
                        -->
                        <template v-if="row.currency">
                            {{ row.currency }} {{ formatNumber(row.budget_max) }}
                        </template>
                        <template v-else>
                            {{ formatNumber(row.budget_max) }}
                            <span class="opacity-70">({{ t('leads.summary.currency_unknown') }})</span>
                        </template>
                    </p>
                </div>
            </AppCard>

            <AppPagination :links="requests.links" spa />
        </div>
    </AuthLayout>
</template>
