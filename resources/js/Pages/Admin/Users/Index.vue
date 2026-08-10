<script setup lang="ts">
import { ref } from 'vue';
import { Head, Link, router } from '@inertiajs/vue3';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import AppButton from '@/Components/ui/AppButton.vue';
import AppEmptyState from '@/Components/ui/AppEmptyState.vue';
import AppPagination from '@/Components/ui/AppPagination.vue';
import { t, formatNumber } from '@/lib/i18n';

/*
 * The Admin Users Workspace (spec §8): member accounts as a follow-up
 * surface — who is active, who linked Telegram, what each person is looking
 * for right now, and the audited door to a phone number where consent allows
 * one.
 *
 * Every claim renders at its true strength. The phone is PRESENT or ABSENT
 * and always user-provided; the number itself is never in this payload — the
 * per-row reveal is the same ceremony as the detail page, hitting the same
 * audited endpoint, and the digits live only in transient component state
 * after a successful reveal.
 */
interface LatestRequest {
    objective: string | null;
    property_type: string | null;
    stage: string;
    updated_at: string | null;
}

interface Row {
    id: number;
    name: string;
    display_name: string | null;
    thumb: string | null;
    initials: string;
    preferred_locale: string;
    is_suspended: boolean;
    telegram_linked: boolean;
    telegram_linked_at: string | null;
    phone_present: boolean;
    phone_status: string;
    registered_at: string | null;
    last_login_at: string | null;
    last_seen_at: string | null;
    online: boolean;
    advisor_request_count: number;
    portfolio_count: number;
    contact_consent: boolean;
    latest_request: LatestRequest | null;
}

const props = defineProps<{
    users: { data: Row[]; links: Array<{ url: string | null; label: string; active: boolean }> };
    filters: {
        q?: string | null;
        status?: string | null;
        locale?: string | null;
        active?: string | null;
        sort?: string | null;
        registered_from?: string | null;
        registered_to?: string | null;
        has_request?: string | null;
        objective?: string | null;
        property_type?: string | null;
        stage?: string | null;
    };
    stages: string[];
    can_reveal: boolean;
    can_export: boolean;
    reveal_reasons: Array<{ value: string; requires_note: boolean }>;
}>();

function applyFilter(patch: Record<string, string | null>): void {
    router.get('/admin/users', { ...props.filters, ...patch }, { preserveState: true, preserveScroll: true });
}

/* The sheet is the CURRENT view: same endpoint family, same filters. */
function exportUrl(): string {
    const params = new URLSearchParams();
    for (const [key, value] of Object.entries(props.filters)) {
        if (value) params.set(key, value);
    }
    const query = params.toString();
    return '/admin/users/export' + (query ? `?${query}` : '');
}

const requestSummary = (request: LatestRequest): string => {
    const parts: string[] = [];
    if (request.objective) parts.push(t(`identity.users.objective_${request.objective}`));
    if (request.property_type) parts.push(t(`identity.users.type_${request.property_type}`));
    return parts.join(' · ');
};

/*
 * The per-row reveal ceremony: same reason/note contract, same endpoint,
 * same audit as the detail page. One row at a time; the revealed number
 * lives only here, in memory, until navigation discards it.
 */
const revealFor = ref<number | null>(null);
const revealReason = ref('');
const revealNote = ref('');
const revealBusy = ref(false);
const revealError = ref<string | null>(null);
const revealed = ref<Record<number, string>>({});

const revealNeedsNote = (): boolean =>
    props.reveal_reasons.find((r) => r.value === revealReason.value)?.requires_note ?? false;

function openReveal(row: Row): void {
    revealError.value = null;
    revealReason.value = '';
    revealNote.value = '';
    revealFor.value = revealFor.value === row.id ? null : row.id;
}

async function revealPhone(row: Row): Promise<void> {
    if (revealReason.value === '' || revealBusy.value) return;

    revealBusy.value = true;
    revealError.value = null;

    try {
        const response = await fetch(`/admin/users/${row.id}/phone`, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                Accept: 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
                'X-XSRF-TOKEN': decodeURIComponent(document.cookie.match(/XSRF-TOKEN=([^;]+)/)?.[1] ?? ''),
            },
            body: JSON.stringify({ reason: revealReason.value, note: revealNote.value || null }),
        });
        const body = (await response.json()) as { ok?: boolean; phone?: string; reason?: string };

        if (response.ok && body.ok === true && body.phone) {
            revealed.value = { ...revealed.value, [row.id]: body.phone };
            revealFor.value = null;
        } else {
            revealError.value = t('leads.detail.reveal_refused', { reason: body.reason ?? 'denied' });
        }
    } catch {
        revealError.value = t('leads.detail.reveal_refused', { reason: 'network' });
    } finally {
        revealBusy.value = false;
    }
}
</script>

<template>
    <Head :title="t('identity.users.title')" />

    <AdminLayout>
        <template #title>{{ t('identity.users.title') }}</template>

        <!-- ------------------------------------------------- filter bar -->
        <div class="mb-5 flex flex-wrap items-end gap-3">
            <div class="min-w-56 flex-1">
                <label for="users-q" class="mb-1 block text-xs text-ink-muted">
                    {{ t('identity.users.search') }}
                </label>
                <input
                    id="users-q"
                    :value="filters.q ?? ''"
                    type="search"
                    class="block min-h-11 w-full rounded-card border border-line bg-surface px-3 text-sm text-ink
                           focus:border-accent focus:outline-none focus:ring-2 focus:ring-accent/40"
                    @change="applyFilter({ q: ($event.target as HTMLInputElement).value || null })"
                >
            </div>

            <div>
                <label for="users-status" class="mb-1 block text-xs text-ink-muted">
                    {{ t('identity.users.status') }}
                </label>
                <select
                    id="users-status"
                    :value="filters.status ?? ''"
                    class="block min-h-11 rounded-card border border-line bg-surface px-3 text-sm text-ink
                           focus:border-accent focus:outline-none focus:ring-2 focus:ring-accent/40"
                    @change="applyFilter({ status: ($event.target as HTMLSelectElement).value || null })"
                >
                    <option value="">—</option>
                    <option value="active">{{ t('identity.users.status_active') }}</option>
                    <option value="suspended">{{ t('identity.users.status_suspended') }}</option>
                    <option value="unlinked">{{ t('identity.users.status_unlinked') }}</option>
                </select>
            </div>

            <div>
                <label for="users-locale" class="mb-1 block text-xs text-ink-muted">
                    {{ t('identity.register.locale') }}
                </label>
                <select
                    id="users-locale"
                    :value="filters.locale ?? ''"
                    class="block min-h-11 rounded-card border border-line bg-surface px-3 text-sm text-ink
                           focus:border-accent focus:outline-none focus:ring-2 focus:ring-accent/40"
                    @change="applyFilter({ locale: ($event.target as HTMLSelectElement).value || null })"
                >
                    <option value="">—</option>
                    <option value="ckb">کوردیی ناوەندی</option>
                    <option value="ar">العربية</option>
                    <option value="en">English</option>
                </select>
            </div>

            <div>
                <label for="users-active" class="mb-1 block text-xs text-ink-muted">
                    {{ t('identity.users.activity') }}
                </label>
                <select
                    id="users-active"
                    :value="filters.active ?? ''"
                    class="block min-h-11 rounded-card border border-line bg-surface px-3 text-sm text-ink
                           focus:border-accent focus:outline-none focus:ring-2 focus:ring-accent/40"
                    @change="applyFilter({ active: ($event.target as HTMLSelectElement).value || null })"
                >
                    <option value="">—</option>
                    <option value="online">{{ t('identity.users.activity_online') }}</option>
                    <option value="today">{{ t('identity.users.activity_today') }}</option>
                    <option value="week">{{ t('identity.users.activity_week') }}</option>
                    <option value="month">{{ t('identity.users.activity_month') }}</option>
                </select>
            </div>

            <div>
                <label for="users-has-request" class="mb-1 block text-xs text-ink-muted">
                    {{ t('identity.users.request_filter') }}
                </label>
                <select
                    id="users-has-request"
                    :value="filters.has_request ?? ''"
                    class="block min-h-11 rounded-card border border-line bg-surface px-3 text-sm text-ink
                           focus:border-accent focus:outline-none focus:ring-2 focus:ring-accent/40"
                    @change="applyFilter({ has_request: ($event.target as HTMLSelectElement).value || null })"
                >
                    <option value="">—</option>
                    <option value="1">{{ t('identity.users.request_any') }}</option>
                    <option value="0">{{ t('identity.users.request_none_filter') }}</option>
                </select>
            </div>

            <div>
                <label for="users-objective" class="mb-1 block text-xs text-ink-muted">
                    {{ t('identity.users.objective') }}
                </label>
                <select
                    id="users-objective"
                    :value="filters.objective ?? ''"
                    class="block min-h-11 rounded-card border border-line bg-surface px-3 text-sm text-ink
                           focus:border-accent focus:outline-none focus:ring-2 focus:ring-accent/40"
                    @change="applyFilter({ objective: ($event.target as HTMLSelectElement).value || null })"
                >
                    <option value="">—</option>
                    <option value="investment">{{ t('identity.users.objective_investment') }}</option>
                    <option value="residence">{{ t('identity.users.objective_residence') }}</option>
                </select>
            </div>

            <div>
                <label for="users-ptype" class="mb-1 block text-xs text-ink-muted">
                    {{ t('identity.users.property_type') }}
                </label>
                <select
                    id="users-ptype"
                    :value="filters.property_type ?? ''"
                    class="block min-h-11 rounded-card border border-line bg-surface px-3 text-sm text-ink
                           focus:border-accent focus:outline-none focus:ring-2 focus:ring-accent/40"
                    @change="applyFilter({ property_type: ($event.target as HTMLSelectElement).value || null })"
                >
                    <option value="">—</option>
                    <option value="apartment">{{ t('identity.users.type_apartment') }}</option>
                    <option value="villa">{{ t('identity.users.type_villa') }}</option>
                    <option value="house">{{ t('identity.users.type_house') }}</option>
                </select>
            </div>

            <div>
                <label for="users-stage" class="mb-1 block text-xs text-ink-muted">
                    {{ t('identity.users.stage') }}
                </label>
                <select
                    id="users-stage"
                    :value="filters.stage ?? ''"
                    class="block min-h-11 rounded-card border border-line bg-surface px-3 text-sm text-ink
                           focus:border-accent focus:outline-none focus:ring-2 focus:ring-accent/40"
                    @change="applyFilter({ stage: ($event.target as HTMLSelectElement).value || null })"
                >
                    <option value="">—</option>
                    <option v-for="stage in stages" :key="stage" :value="stage">
                        {{ t(`identity.users.stage_${stage}`) }}
                    </option>
                </select>
            </div>

            <div>
                <label for="users-sort" class="mb-1 block text-xs text-ink-muted">
                    {{ t('identity.users.sort') }}
                </label>
                <select
                    id="users-sort"
                    :value="filters.sort ?? 'newest'"
                    class="block min-h-11 rounded-card border border-line bg-surface px-3 text-sm text-ink
                           focus:border-accent focus:outline-none focus:ring-2 focus:ring-accent/40"
                    @change="applyFilter({ sort: ($event.target as HTMLSelectElement).value || null })"
                >
                    <option value="newest">{{ t('identity.users.sort_newest') }}</option>
                    <option value="oldest">{{ t('identity.users.sort_oldest') }}</option>
                    <option value="recent_activity">{{ t('identity.users.sort_recent_activity') }}</option>
                </select>
            </div>

            <div>
                <label for="users-from" class="mb-1 block text-xs text-ink-muted">
                    {{ t('identity.users.registered_from') }}
                </label>
                <input
                    id="users-from"
                    :value="filters.registered_from ?? ''"
                    type="date"
                    class="block min-h-11 rounded-card border border-line bg-surface px-3 text-sm text-ink
                           focus:border-accent focus:outline-none focus:ring-2 focus:ring-accent/40"
                    @change="applyFilter({ registered_from: ($event.target as HTMLInputElement).value || null })"
                >
            </div>

            <div>
                <label for="users-to" class="mb-1 block text-xs text-ink-muted">
                    {{ t('identity.users.registered_to') }}
                </label>
                <input
                    id="users-to"
                    :value="filters.registered_to ?? ''"
                    type="date"
                    class="block min-h-11 rounded-card border border-line bg-surface px-3 text-sm text-ink
                           focus:border-accent focus:outline-none focus:ring-2 focus:ring-accent/40"
                    @change="applyFilter({ registered_to: ($event.target as HTMLInputElement).value || null })"
                >
            </div>

            <!-- A plain download link, not an XHR: the browser streams the
                 file and the URL carries exactly the filters on screen. -->
            <a
                v-if="can_export"
                :href="exportUrl()"
                class="inline-flex min-h-11 items-center rounded-card bg-brand px-4 text-sm font-medium text-white
                       transition-colors hover:bg-brand/90 focus-visible:outline-none focus-visible:ring-2
                       focus-visible:ring-accent"
                download
            >
                {{ t('identity.users.export_sheet') }}
            </a>
        </div>

        <AppEmptyState
            v-if="users.data.length === 0"
            :title="t('identity.users.empty')"
            :description="t('identity.users.empty_body')"
        />

        <!-- ------------------------------------------ desktop workspace -->
        <div v-if="users.data.length > 0" class="hidden overflow-x-auto rounded-card border border-line lg:block">
            <table class="w-full min-w-[960px] text-sm">
                <thead>
                    <tr class="border-b border-line bg-surface-sunken text-start text-xs text-ink-muted">
                        <th class="px-4 py-3 text-start font-medium">{{ t('identity.users.col_user') }}</th>
                        <th class="px-4 py-3 text-start font-medium">{{ t('identity.users.col_status') }}</th>
                        <th class="px-4 py-3 text-start font-medium">{{ t('identity.users.col_contact') }}</th>
                        <th class="px-4 py-3 text-start font-medium">{{ t('identity.users.col_activity') }}</th>
                        <th class="px-4 py-3 text-start font-medium">{{ t('identity.users.col_request') }}</th>
                        <th class="px-4 py-3 text-end font-medium">{{ t('identity.users.requests') }}</th>
                        <th class="px-4 py-3 text-end font-medium">{{ t('identity.users.portfolio') }}</th>
                    </tr>
                </thead>
                <tbody>
                    <template v-for="user in users.data" :key="user.id">
                        <tr class="border-b border-line align-top last:border-b-0 hover:bg-surface-sunken/50">
                            <td class="px-4 py-3">
                                <div class="flex items-center gap-3">
                                    <img
                                        v-if="user.thumb"
                                        :src="user.thumb"
                                        :alt="user.display_name ?? user.name"
                                        class="h-9 w-9 shrink-0 rounded-full border border-line object-cover"
                                    >
                                    <span
                                        v-else
                                        class="flex h-9 w-9 shrink-0 select-none items-center justify-center rounded-full
                                               border border-line bg-surface-sunken text-xs font-semibold text-ink-muted"
                                        aria-hidden="true"
                                    >{{ user.initials }}</span>
                                    <div class="min-w-0">
                                        <Link
                                            :href="`/admin/users/${user.id}`"
                                            class="block truncate font-medium text-ink underline-offset-2 hover:underline
                                                   focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-accent"
                                        >
                                            {{ user.display_name ?? user.name }}
                                        </Link>
                                        <span class="text-xs uppercase text-ink-faint">{{ user.preferred_locale }}</span>
                                    </div>
                                </div>
                            </td>

                            <td class="px-4 py-3">
                                <span v-if="user.is_suspended" class="mh-lux-chip text-xs text-negative">
                                    {{ t('identity.users.status_suspended') }}
                                </span>
                                <span v-else-if="user.online" class="mh-lux-chip text-xs text-positive">
                                    {{ t('identity.users.online') }}
                                </span>
                                <span v-else class="mh-lux-chip text-xs">{{ t('identity.users.status_active') }}</span>
                            </td>

                            <td class="px-4 py-3">
                                <p class="text-xs" :class="user.telegram_linked ? 'text-positive' : 'text-caution'">
                                    {{ user.telegram_linked
                                        ? `${t('leads.detail.telegram_verified')} · ${user.telegram_linked_at ?? ''}`
                                        : t('leads.detail.telegram_missing') }}
                                </p>
                                <p class="mt-1 text-xs text-ink-muted">
                                    <template v-if="!user.phone_present">{{ t('identity.users.phone_absent') }}</template>
                                    <template v-else-if="revealed[user.id]">
                                        <span class="numeral font-medium text-ink" dir="ltr">{{ revealed[user.id] }}</span>
                                    </template>
                                    <template v-else-if="user.contact_consent && can_reveal">
                                        <button
                                            type="button"
                                            class="text-accent underline-offset-2 hover:underline focus-visible:outline-none
                                                   focus-visible:ring-2 focus-visible:ring-accent"
                                            @click="openReveal(user)"
                                        >
                                            {{ t('identity.users.reveal_phone') }}
                                        </button>
                                    </template>
                                    <template v-else>{{ t('identity.users.phone_reveal_not_permitted') }}</template>
                                </p>
                            </td>

                            <td class="px-4 py-3 text-xs text-ink-muted">
                                <p v-if="user.last_seen_at">{{ t('identity.users.last_seen') }}: {{ user.last_seen_at }}</p>
                                <p v-if="user.last_login_at" class="mt-0.5">{{ t('identity.users.last_login') }}: {{ user.last_login_at }}</p>
                                <p class="mt-0.5">{{ t('identity.users.registered') }}: {{ user.registered_at }}</p>
                            </td>

                            <td class="px-4 py-3 text-xs">
                                <template v-if="user.latest_request">
                                    <p class="font-medium text-ink">
                                        {{ requestSummary(user.latest_request) || '—' }}
                                    </p>
                                    <p class="mt-0.5 text-ink-muted">
                                        {{ t(`identity.users.stage_${user.latest_request.stage}`) }}
                                        <span v-if="user.latest_request.updated_at" class="text-ink-faint">
                                            · {{ user.latest_request.updated_at }}
                                        </span>
                                    </p>
                                </template>
                                <span v-else class="text-ink-faint">{{ t('identity.users.request_none') }}</span>
                            </td>

                            <td class="numeral px-4 py-3 text-end" dir="ltr">{{ formatNumber(user.advisor_request_count) }}</td>
                            <td class="numeral px-4 py-3 text-end" dir="ltr">{{ formatNumber(user.portfolio_count) }}</td>
                        </tr>

                        <tr v-if="revealFor === user.id" class="border-b border-line bg-surface-sunken/40 last:border-b-0">
                            <td colspan="7" class="px-4 py-3">
                                <form class="flex flex-wrap items-end gap-3" @submit.prevent="revealPhone(user)">
                                    <div>
                                        <label :for="`reveal-reason-${user.id}`" class="mb-1 block text-xs text-ink-muted">
                                            {{ t('leads.detail.reveal_reason') }}
                                        </label>
                                        <select
                                            :id="`reveal-reason-${user.id}`"
                                            v-model="revealReason"
                                            required
                                            class="block min-h-10 rounded-card border border-line bg-surface px-3 text-sm text-ink
                                                   focus:border-accent focus:outline-none focus:ring-2 focus:ring-accent/40"
                                        >
                                            <option value="" disabled>—</option>
                                            <option v-for="reason in reveal_reasons" :key="reason.value" :value="reason.value">
                                                {{ t(`leads.reveal_reasons.${reason.value}`) }}
                                            </option>
                                        </select>
                                    </div>
                                    <div v-if="revealNeedsNote()" class="min-w-56 flex-1">
                                        <label :for="`reveal-note-${user.id}`" class="mb-1 block text-xs text-ink-muted">
                                            {{ t('leads.detail.reveal_note') }}
                                        </label>
                                        <input
                                            :id="`reveal-note-${user.id}`"
                                            v-model="revealNote"
                                            type="text"
                                            maxlength="500"
                                            class="block min-h-10 w-full rounded-card border border-line bg-surface px-3 text-sm
                                                   text-ink focus:border-accent focus:outline-none focus:ring-2 focus:ring-accent/40"
                                        >
                                    </div>
                                    <AppButton type="submit" size="sm" :loading="revealBusy" :disabled="revealReason === ''">
                                        {{ t('leads.detail.reveal_confirm') }}
                                    </AppButton>
                                    <AppButton type="button" size="sm" variant="secondary" @click="revealFor = null">
                                        {{ t('app.actions.cancel') }}
                                    </AppButton>
                                    <p v-if="revealError" class="text-xs text-negative">{{ revealError }}</p>
                                </form>
                            </td>
                        </tr>
                    </template>
                </tbody>
            </table>
        </div>

        <!-- ------------------------------------------- mobile workspace -->
        <div v-if="users.data.length > 0" class="space-y-3 lg:hidden">
            <div v-for="user in users.data" :key="user.id" class="rounded-card border border-line bg-surface-raised p-4">
                <div class="flex items-start gap-3">
                    <img
                        v-if="user.thumb"
                        :src="user.thumb"
                        :alt="user.display_name ?? user.name"
                        class="h-10 w-10 shrink-0 rounded-full border border-line object-cover"
                    >
                    <span
                        v-else
                        class="flex h-10 w-10 shrink-0 select-none items-center justify-center rounded-full border
                               border-line bg-surface-sunken text-xs font-semibold text-ink-muted"
                        aria-hidden="true"
                    >{{ user.initials }}</span>

                    <div class="min-w-0 flex-1">
                        <div class="flex flex-wrap items-center gap-2">
                            <Link
                                :href="`/admin/users/${user.id}`"
                                class="text-sm font-medium text-ink underline-offset-2 hover:underline
                                       focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-accent"
                            >
                                {{ user.display_name ?? user.name }}
                            </Link>
                            <span class="mh-lux-chip text-xs uppercase">{{ user.preferred_locale }}</span>
                            <span v-if="user.is_suspended" class="mh-lux-chip text-xs text-negative">
                                {{ t('identity.users.status_suspended') }}
                            </span>
                            <span v-else-if="user.online" class="mh-lux-chip text-xs text-positive">
                                {{ t('identity.users.online') }}
                            </span>
                        </div>

                        <p class="mt-1 flex flex-wrap gap-x-3 gap-y-1 text-xs">
                            <span :class="user.telegram_linked ? 'text-positive' : 'text-caution'">
                                {{ user.telegram_linked
                                    ? `${t('leads.detail.telegram_verified')} · ${user.telegram_linked_at ?? ''}`
                                    : t('leads.detail.telegram_missing') }}
                            </span>
                            <span class="text-ink-faint">
                                <template v-if="!user.phone_present">{{ t('identity.users.phone_absent') }}</template>
                                <template v-else-if="revealed[user.id]">
                                    <span class="numeral font-medium text-ink" dir="ltr">{{ revealed[user.id] }}</span>
                                </template>
                                <template v-else-if="user.contact_consent && can_reveal">
                                    <button
                                        type="button"
                                        class="text-accent underline-offset-2 hover:underline"
                                        @click="openReveal(user)"
                                    >
                                        {{ t('identity.users.reveal_phone') }}
                                    </button>
                                </template>
                                <template v-else>{{ t('identity.users.phone_reveal_not_permitted') }}</template>
                            </span>
                        </p>

                        <p class="mt-1 text-xs">
                            <template v-if="user.latest_request">
                                <span class="font-medium text-ink">{{ requestSummary(user.latest_request) || '—' }}</span>
                                <span class="text-ink-muted">
                                    · {{ t(`identity.users.stage_${user.latest_request.stage}`) }}
                                    <template v-if="user.latest_request.updated_at"> · {{ user.latest_request.updated_at }}</template>
                                </span>
                            </template>
                            <span v-else class="text-ink-faint">{{ t('identity.users.request_none') }}</span>
                        </p>

                        <p class="mt-1 flex flex-wrap gap-x-3 gap-y-1 text-xs text-ink-muted">
                            <span>{{ t('identity.users.registered') }}: {{ user.registered_at }}</span>
                            <span v-if="user.last_login_at">{{ t('identity.users.last_login') }}: {{ user.last_login_at }}</span>
                            <span v-if="user.last_seen_at">{{ t('identity.users.last_seen') }}: {{ user.last_seen_at }}</span>
                            <span class="numeral" dir="ltr">
                                {{ t('identity.users.requests') }}: {{ formatNumber(user.advisor_request_count) }}
                            </span>
                            <span class="numeral" dir="ltr">
                                {{ t('identity.users.portfolio') }}: {{ formatNumber(user.portfolio_count) }}
                            </span>
                        </p>

                        <form
                            v-if="revealFor === user.id"
                            class="mt-3 space-y-2 border-t border-line pt-3"
                            @submit.prevent="revealPhone(user)"
                        >
                            <select
                                v-model="revealReason"
                                required
                                :aria-label="t('leads.detail.reveal_reason')"
                                class="block min-h-10 w-full rounded-card border border-line bg-surface px-3 text-sm text-ink
                                       focus:border-accent focus:outline-none focus:ring-2 focus:ring-accent/40"
                            >
                                <option value="" disabled>{{ t('leads.detail.reveal_reason') }}</option>
                                <option v-for="reason in reveal_reasons" :key="reason.value" :value="reason.value">
                                    {{ t(`leads.reveal_reasons.${reason.value}`) }}
                                </option>
                            </select>
                            <input
                                v-if="revealNeedsNote()"
                                v-model="revealNote"
                                type="text"
                                maxlength="500"
                                :aria-label="t('leads.detail.reveal_note')"
                                class="block min-h-10 w-full rounded-card border border-line bg-surface px-3 text-sm text-ink
                                       focus:border-accent focus:outline-none focus:ring-2 focus:ring-accent/40"
                            >
                            <div class="flex gap-2">
                                <AppButton type="submit" size="sm" :loading="revealBusy" :disabled="revealReason === ''">
                                    {{ t('leads.detail.reveal_confirm') }}
                                </AppButton>
                                <AppButton type="button" size="sm" variant="secondary" @click="revealFor = null">
                                    {{ t('app.actions.cancel') }}
                                </AppButton>
                            </div>
                            <p v-if="revealError" class="text-xs text-negative">{{ revealError }}</p>
                        </form>
                    </div>
                </div>
            </div>
        </div>

        <AppPagination v-if="users.data.length > 0" :links="users.links" class="mt-5" />
    </AdminLayout>
</template>
