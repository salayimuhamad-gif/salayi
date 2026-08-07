<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import AppCard from '@/Components/ui/AppCard.vue';
import AppEmptyState from '@/Components/ui/AppEmptyState.vue';
import AppPagination from '@/Components/ui/AppPagination.vue';
import { t, formatNumber } from '@/lib/i18n';

/*
 * Member accounts (spec §8).
 *
 * Every row states each claim at its true strength: the Telegram link is a
 * verified fact with a date, the phone is PRESENT or ABSENT and always
 * user-provided. The number itself is not in this payload and not in this
 * page — the reveal lives on the detail screen, behind its own permission
 * and its own ceremony.
 */
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
    advisor_request_count: number;
    portfolio_count: number;
}

const props = defineProps<{
    users: { data: Row[]; links: Array<{ url: string | null; label: string; active: boolean }> };
    filters: { q?: string | null; status?: string | null; locale?: string | null };
}>();

function applyFilter(patch: Record<string, string | null>): void {
    router.get('/admin/users', { ...props.filters, ...patch }, { preserveState: true, preserveScroll: true });
}
</script>

<template>
    <Head :title="t('identity.users.title')" />

    <AdminLayout>
        <template #title>{{ t('identity.users.title') }}</template>

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
        </div>

        <AppEmptyState
            v-if="users.data.length === 0"
            :title="t('identity.users.empty')"
            :description="t('identity.users.empty_body')"
        />

        <AppCard v-for="user in users.data" v-else :key="user.id" class="mb-3">
            <div class="flex items-start gap-4">
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
                    </div>

                    <p class="mt-1 flex flex-wrap gap-x-3 gap-y-1 text-xs">
                        <span :class="user.telegram_linked ? 'text-positive' : 'text-caution'">
                            {{ user.telegram_linked
                                ? `${t('leads.detail.telegram_verified')} · ${user.telegram_linked_at ?? ''}`
                                : t('leads.detail.telegram_missing') }}
                        </span>
                        <span class="text-ink-faint">
                            {{ user.phone_present
                                ? t('identity.users.phone_user_provided')
                                : t('identity.users.phone_absent') }}
                        </span>
                    </p>

                    <p class="mt-1 flex flex-wrap gap-x-3 gap-y-1 text-xs text-ink-muted">
                        <span>{{ t('identity.users.registered') }}: {{ user.registered_at }}</span>
                        <span v-if="user.last_login_at">{{ t('identity.users.last_login') }}: {{ user.last_login_at }}</span>
                        <span class="numeral" dir="ltr">
                            {{ t('identity.users.requests') }}: {{ formatNumber(user.advisor_request_count) }}
                        </span>
                        <span class="numeral" dir="ltr">
                            {{ t('identity.users.portfolio') }}: {{ formatNumber(user.portfolio_count) }}
                        </span>
                    </p>
                </div>
            </div>
        </AppCard>

        <AppPagination v-if="users.data.length > 0" :links="users.links" class="mt-5" />
    </AdminLayout>
</template>
