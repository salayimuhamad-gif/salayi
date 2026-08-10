<script setup lang="ts">
import { reactive, ref } from 'vue';
import { Head, router, usePage } from '@inertiajs/vue3';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import AppAlert from '@/Components/ui/AppAlert.vue';
import AppButton from '@/Components/ui/AppButton.vue';
import AppCard from '@/Components/ui/AppCard.vue';
import AppEmptyState from '@/Components/ui/AppEmptyState.vue';
import AppPagination from '@/Components/ui/AppPagination.vue';
import { t } from '@/lib/i18n';

/*
 * The operators surface (spec 3.2): who holds which role.
 *
 * Deliberately phone-free — an operator's reachable identity here is their
 * name, their roles and their security posture. Members are administered on
 * the accounts surface with its own privacy ceremony; this page 404s them
 * exactly as that page 404s operators.
 */
interface Row {
    id: number;
    name: string;
    initials: string;
    roles: string[];
    is_super_admin: boolean;
    is_suspended: boolean;
    suspended_reason: string | null;
    mfa_enabled: boolean;
    telegram_linked: boolean;
    registered_at: string | null;
    last_login_at: string | null;
    last_seen_at: string | null;
    online: boolean;
}

const props = defineProps<{
    administrators: { data: Row[]; links: Array<{ url: string | null; label: string; active: boolean }> };
    assignable_roles: string[];
    active_super_admins: number;
    can: {
        assign_roles: boolean;
        suspend: boolean;
        revoke_sessions: boolean;
        grant_super_admin: boolean;
    };
}>();

const page = usePage();
const selfId = (page.props as { auth?: { user?: { id?: number } } }).auth?.user?.id;

const roleLabel = (key: string): string => t(`identity.roles.${key}`);

/*
 * Per-row editing state. The draft starts from the row's current roles so an
 * opened editor shows the truth, and closing without saving discards it.
 */
const editing = ref<number | null>(null);
const draft = reactive<Record<number, Record<string, boolean>>>({});

function openEditor(row: Row): void {
    const state: Record<string, boolean> = {};
    for (const key of props.assignable_roles) {
        state[key] = row.roles.includes(key);
    }
    draft[row.id] = state;
    editing.value = editing.value === row.id ? null : row.id;
}

function saveRoles(row: Row): void {
    const roles = Object.entries(draft[row.id] ?? {})
        .filter(([, on]) => on)
        .map(([key]) => key);

    router.put(`/admin/administrators/${row.id}/roles`, { roles }, {
        preserveScroll: true,
        onSuccess: () => { editing.value = null; },
    });
}

/*
 * Whether THIS actor may edit THIS row at all. The server enforces the same
 * rule with a 403; the page simply does not offer what the server will
 * refuse.
 */
const mayEdit = (row: Row): boolean =>
    props.can.assign_roles && (props.can.grant_super_admin || !row.is_super_admin);

const suspending = ref<number | null>(null);
const suspendReason = ref('');

function suspend(row: Row): void {
    router.post(`/admin/administrators/${row.id}/suspend`, { reason: suspendReason.value }, {
        preserveScroll: true,
        onSuccess: () => { suspending.value = null; suspendReason.value = ''; },
    });
}

function reactivate(row: Row): void {
    router.post(`/admin/administrators/${row.id}/reactivate`, {}, { preserveScroll: true });
}

function forceLogout(row: Row): void {
    router.post(`/admin/administrators/${row.id}/logout`, {}, { preserveScroll: true });
}

const errors = (): Record<string, string> => (page.props.errors ?? {}) as Record<string, string>;
</script>

<template>
    <Head :title="t('identity.administrators.title')" />

    <AdminLayout>
        <template #title>{{ t('identity.administrators.title') }}</template>

        <AppAlert v-if="active_super_admins === 1" variant="info" class="mb-6">
            {{ t('identity.administrators.single_super_admin_hint') }}
        </AppAlert>

        <AppAlert v-if="errors().roles" variant="danger" class="mb-6">{{ errors().roles }}</AppAlert>
        <AppAlert v-if="errors().reason" variant="danger" class="mb-6">{{ errors().reason }}</AppAlert>

        <AppEmptyState
            v-if="administrators.data.length === 0"
            :title="t('identity.administrators.none')"
            :description="t('identity.administrators.none_hint')"
        />

        <div v-else class="space-y-3">
            <AppCard v-for="row in administrators.data" :key="row.id">
                <div class="flex flex-wrap items-start gap-4">
                    <span
                        class="flex h-10 w-10 shrink-0 items-center justify-center rounded-full bg-surface-sunken
                               text-sm font-semibold text-ink-muted"
                        aria-hidden="true"
                    >
                        {{ row.initials }}
                    </span>

                    <div class="min-w-0 flex-1">
                        <div class="flex flex-wrap items-center gap-2">
                            <span class="font-medium text-ink">{{ row.name }}</span>
                            <span
                                v-if="row.is_super_admin"
                                class="rounded-full bg-accent/15 px-2 py-0.5 text-xs font-semibold text-accent"
                            >
                                {{ roleLabel('super_admin') }}
                            </span>
                            <span
                                v-if="row.is_suspended"
                                class="rounded-full bg-negative/15 px-2 py-0.5 text-xs font-semibold text-negative"
                            >
                                {{ t('identity.administrators.suspended') }}
                            </span>
                            <span
                                v-else-if="row.online"
                                class="rounded-full bg-positive/15 px-2 py-0.5 text-xs font-semibold text-positive"
                            >
                                {{ t('identity.users.online') }}
                            </span>
                        </div>

                        <ul class="mt-1.5 flex flex-wrap gap-1.5">
                            <li
                                v-for="role in row.roles"
                                :key="role"
                                class="rounded-full border border-line px-2 py-0.5 text-xs text-ink-muted"
                            >
                                {{ roleLabel(role) }}
                            </li>
                        </ul>

                        <p class="mt-1.5 text-xs text-ink-faint">
                            <span>{{ t('identity.administrators.mfa') }}:
                                {{ row.mfa_enabled ? t('identity.administrators.mfa_enabled') : t('identity.administrators.mfa_missing') }}</span>
                            <span v-if="row.last_seen_at"> · {{ t('identity.users.last_seen') }}: {{ row.last_seen_at }}</span>
                            <span v-if="row.registered_at"> · {{ t('identity.users.registered') }}: {{ row.registered_at }}</span>
                        </p>

                        <p v-if="row.is_suspended && row.suspended_reason" class="mt-1 text-xs text-negative">
                            {{ row.suspended_reason }}
                        </p>
                    </div>

                    <div class="flex shrink-0 flex-wrap gap-2">
                        <AppButton
                            v-if="mayEdit(row)"
                            size="sm"
                            variant="secondary"
                            @click="openEditor(row)"
                        >
                            {{ t('identity.administrators.edit_roles') }}
                        </AppButton>

                        <template v-if="can.suspend && row.id !== selfId">
                            <AppButton
                                v-if="!row.is_suspended"
                                size="sm"
                                variant="secondary"
                                @click="suspending = suspending === row.id ? null : row.id"
                            >
                                {{ t('identity.users.suspend') }}
                            </AppButton>
                            <AppButton
                                v-else
                                size="sm"
                                variant="secondary"
                                @click="reactivate(row)"
                            >
                                {{ t('identity.users.reactivate') }}
                            </AppButton>
                        </template>

                        <AppButton
                            v-if="can.revoke_sessions"
                            size="sm"
                            variant="secondary"
                            @click="forceLogout(row)"
                        >
                            {{ t('identity.users.force_logout') }}
                        </AppButton>
                    </div>
                </div>

                <!-- Suspension asks for its reason inline; the reason is part
                     of the audit record, not decoration. -->
                <form
                    v-if="suspending === row.id"
                    class="mt-4 flex flex-wrap items-end gap-3 border-t border-line pt-4"
                    @submit.prevent="suspend(row)"
                >
                    <div class="min-w-64 flex-1">
                        <label :for="`suspend-reason-${row.id}`" class="mb-1 block text-xs text-ink-muted">
                            {{ t('identity.administrators.suspend_reason') }}
                        </label>
                        <input
                            :id="`suspend-reason-${row.id}`"
                            v-model="suspendReason"
                            type="text"
                            required
                            minlength="3"
                            maxlength="500"
                            class="block min-h-11 w-full rounded-card border border-line bg-surface px-3 text-sm text-ink
                                   focus:border-accent focus:outline-none focus:ring-2 focus:ring-accent/40"
                        >
                    </div>
                    <AppButton type="submit" size="sm" variant="danger">
                        {{ t('identity.users.suspend') }}
                    </AppButton>
                </form>

                <div v-if="editing === row.id && mayEdit(row)" class="mt-4 border-t border-line pt-4">
                    <p class="mb-2 text-xs text-ink-muted">{{ t('identity.administrators.roles_hint') }}</p>

                    <div class="grid gap-2 sm:grid-cols-2 lg:grid-cols-3">
                        <label
                            v-for="key in assignable_roles"
                            :key="key"
                            class="flex items-center gap-2 rounded-card border border-line px-3 py-2 text-sm"
                            :class="key === 'super_admin' && !can.grant_super_admin
                                ? 'cursor-not-allowed opacity-50'
                                : 'cursor-pointer hover:bg-surface-sunken'"
                        >
                            <input
                                v-model="draft[row.id][key]"
                                type="checkbox"
                                class="h-4 w-4 rounded border-line text-accent focus:ring-accent"
                                :disabled="key === 'super_admin' && !can.grant_super_admin"
                            >
                            <span class="text-ink">{{ roleLabel(key) }}</span>
                        </label>
                    </div>

                    <p
                        v-if="!can.grant_super_admin"
                        class="mt-2 text-xs text-ink-faint"
                    >
                        {{ t('identity.administrators.super_admin_restricted') }}
                    </p>

                    <div class="mt-3 flex gap-2">
                        <AppButton size="sm" @click="saveRoles(row)">
                            {{ t('identity.administrators.save_roles') }}
                        </AppButton>
                        <AppButton size="sm" variant="secondary" @click="editing = null">
                            {{ t('app.actions.cancel') }}
                        </AppButton>
                    </div>
                </div>
            </AppCard>

            <AppPagination :links="administrators.links" />
        </div>
    </AdminLayout>
</template>
