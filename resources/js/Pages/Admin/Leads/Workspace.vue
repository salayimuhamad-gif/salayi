<script setup lang="ts">
import { ref } from 'vue';
import { Head, Link, router } from '@inertiajs/vue3';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import AppCard from '@/Components/ui/AppCard.vue';
import AppAlert from '@/Components/ui/AppAlert.vue';
import AppEmptyState from '@/Components/ui/AppEmptyState.vue';
import AppPagination from '@/Components/ui/AppPagination.vue';
import { t, formatNumber } from '@/lib/i18n';

/*
 * The sales pipeline (File one §9 Leads 2).
 *
 * There is no contact column, and its absence is the design. §9 Leads 5
 * forbids exposing contact details without valid consent, and a masked number
 * on a list page has already been sent to a browser, cached, and possibly
 * screenshotted. Revealing a number is a separate audited act on the lead's own
 * page, not a column.
 *
 * Score reasons are shown inline rather than behind a tooltip: a salesperson
 * scanning twenty-five rows needs to know WHY one is worth calling first, and
 * a reason nobody hovers over is a reason nobody reads.
 */
interface Lead {
    lead_user?: {
        name: string;
        thumb: string | null;
        initials: string;
        telegram_linked: boolean;
        phone_present: boolean;
        phone_status: string;
    } | null;
    admin_summary?: string;
    updated_at?: string | null;
    id: number;
    stage: string;
    objective: string | null;
    property_type: string | null;
    budget_min: string | null;
    budget_max: string | null;
    currency: string | null;
    currency_source: string | null;
    timeframe: string | null;
    source: string | null;
    owner: string | null;
    company: string | null;
    created_at: string | null;
    score: {
        total: number;
        reasons: Array<{ code?: string; label?: string; points?: number }>;
        is_overridden: boolean;
        calculated_total: number | null;
    } | null;
}

const props = defineProps<{
    leads: { data: Lead[]; links: Array<{ url: string | null; label: string; active: boolean }> };
    stages: string[];
    outcomes: string[];
    filters: { stage?: string; min_score?: number; q?: string };
}>();

const stage = ref(props.filters.stage ?? '');
const query = ref(props.filters.q ?? '');

function applyFilters(): void {
    router.get('/admin/leads', {
        stage: stage.value || undefined,
        q: query.value || undefined,
    }, { preserveState: true, replace: true });
}

const scoreTone = (total: number): string =>
    total >= 70 ? 'text-positive' : total >= 40 ? 'text-ink' : 'text-ink-muted';
</script>

<template>
    <Head :title="t('leads.workspace')" />

    <AdminLayout>
        <template #title>{{ t('leads.workspace') }}</template>

        <!-- Stated once, prominently: the pipeline does not carry numbers. -->
        <AppAlert variant="info" class="mb-5">
            {{ t('leads.contact_hidden') }}
        </AppAlert>

        <div class="mb-5 flex flex-wrap items-end gap-3">
            <div>
                <label for="stage" class="mh-label mb-1 block">{{ t('app.actions.filter') }}</label>
                <select
                    id="stage" v-model="stage"
                    class="rounded-card border border-line bg-surface-raised px-3 py-2 text-sm text-ink
                           focus:border-brand focus:outline-none focus:ring-2 focus:ring-accent"
                    @change="applyFilters"
                >
                    <option value="">—</option>
                    <option v-for="s in stages" :key="s" :value="s">{{ s }}</option>
                </select>
            </div>

            <div class="min-w-0 flex-1">
                <label for="q" class="mh-label mb-1 block">{{ t('app.actions.search') }}</label>
                <input
                    id="q" v-model="query" type="search"
                    class="w-full rounded-card border border-line bg-surface-raised px-3 py-2 text-sm text-ink
                           focus:border-brand focus:outline-none focus:ring-2 focus:ring-accent"
                    @keyup.enter="applyFilters"
                >
            </div>
        </div>

        <AppEmptyState v-if="leads.data.length === 0" :title="t('leads.no_leads')" />

        <template v-else>
            <AppCard v-for="lead in leads.data" :key="lead.id" class="mb-3">
                <div class="flex flex-wrap items-baseline justify-between gap-3">
                    <img
                        v-if="lead.lead_user?.thumb"
                        :src="lead.lead_user.thumb"
                        :alt="lead.lead_user.name"
                        class="me-2 inline-block h-8 w-8 rounded-full border border-line object-cover align-middle"
                    >
                    <span
                        v-else-if="lead.lead_user"
                        class="me-2 inline-flex h-8 w-8 select-none items-center justify-center rounded-full
                               border border-line bg-surface-sunken align-middle text-xs font-semibold text-ink-muted"
                        aria-hidden="true"
                    >{{ lead.lead_user.initials }}</span>
                    <Link
                        :href="`/admin/leads/${lead.id}`"
                        class="font-medium text-ink underline-offset-2 hover:underline"
                    >
                        {{ lead.objective ?? `#${lead.id}` }}
                    </Link>

                    <div class="flex items-center gap-3">
                        <span class="rounded-card bg-surface-sunken px-2 py-0.5 text-xs text-ink-muted">
                            {{ lead.stage }}
                        </span>
                        <span
                            v-if="lead.score"
                            class="numeral font-display text-lg font-semibold"
                            :class="scoreTone(lead.score.total)"
                            dir="ltr"
                        >{{ formatNumber(lead.score.total) }}</span>
                    </div>
                </div>

                <p class="numeral mt-1.5 text-xs text-ink-muted" dir="ltr">
                    <template v-if="lead.budget_min || lead.budget_max">
                        {{ lead.budget_min ?? '—' }} – {{ lead.budget_max ?? '—' }}
                        <!--
                            v4 BLOCKER 3: `legacy` marks a row written before
                            currency was recorded, when every lead was
                            stamped USD regardless of what was said. Showing
                            the marker lets the sales team tell a stated
                            dollar budget from an assumed one.
                        -->
                        <template v-if="lead.currency">
                            {{ lead.currency }}
                            <span v-if="lead.currency_source === 'legacy'" class="opacity-60">*</span>
                        </template>
                        <span v-else class="opacity-70">({{ t('leads.summary.currency_unknown') }})</span>
                    </template>
                    <template v-if="lead.timeframe"> · {{ lead.timeframe }}</template>
                    <template v-if="lead.owner"> · {{ lead.owner }}</template>
                </p>

                <p v-if="lead.admin_summary" class="mt-2 text-xs leading-relaxed text-ink-muted">
                    {{ lead.admin_summary }}
                </p>
                <p v-if="lead.lead_user" class="mt-1 flex flex-wrap gap-x-3 text-xs">
                    <span :class="lead.lead_user.telegram_linked ? 'text-positive' : 'text-caution'">
                        {{ lead.lead_user.telegram_linked
                            ? t('leads.detail.telegram_verified')
                            : t('leads.detail.telegram_missing') }}
                    </span>
                    <span class="text-ink-faint">
                        {{ lead.lead_user.phone_present
                            ? t('leads.summary.phone_user_provided')
                            : t('leads.summary.phone_absent') }}
                    </span>
                    <span v-if="lead.updated_at" class="text-ink-faint">{{ lead.updated_at }}</span>
                </p>

                <!-- §9: the reasons, not just the number. -->
                <ul
                    v-if="lead.score && lead.score.reasons.length > 0"
                    class="mt-2 flex flex-wrap gap-x-3 gap-y-1"
                >
                    <li
                        v-for="(reason, index) in lead.score.reasons.slice(0, 4)"
                        :key="index"
                        class="text-xs text-ink-faint"
                    >
                        {{ reason.label ?? reason.code }}
                        <span v-if="reason.points" class="numeral" dir="ltr">+{{ reason.points }}</span>
                    </li>
                </ul>

                <!-- A human overrode the heuristic; the manager should see that
                     without opening the lead. -->
                <p v-if="lead.score?.is_overridden" class="numeral mt-1.5 text-xs text-caution">
                    {{ t('leads.score_reasons') }} · {{ formatNumber(lead.score.calculated_total ?? 0) }}
                </p>
            </AppCard>

            <AppPagination :links="leads.links" />
        </template>
    </AdminLayout>
</template>
