<script setup lang="ts">
import { ref } from 'vue';
import { Head, Link, useForm } from '@inertiajs/vue3';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import AppAlert from '@/Components/ui/AppAlert.vue';
import AppButton from '@/Components/ui/AppButton.vue';
import AppCard from '@/Components/ui/AppCard.vue';
import { t, formatNumber } from '@/lib/i18n';

/*
 * One member account (spec §8).
 *
 * The claims render at their true strengths — Telegram verified with its
 * date, phone user-provided. The phone renders DIRECTLY for administrators
 * holding identity.users.contact (deliberate product policy for the
 * account-management surface; the server sends null to everyone else),
 * dir="ltr" so digits stay readable in RTL, under no-store headers.
 * Suspension asks for words: a suspended person deserves a recorded
 * reason, and the audit stream gets the same one.
 */
const props = defineProps<{
    account: {
        id: number;
        name: string;
        display_name: string | null;
        photo: string | null;
        initials: string;
        preferred_locale: string;
        is_suspended: boolean;
        suspended_reason: string | null;
        telegram_linked: boolean;
        telegram_linked_at: string | null;
        phone_present: boolean;
        phone_status: string;
        phone: string | null;
        registered_at: string | null;
        last_login_at: string | null;
        last_seen_at: string | null;
        online: boolean;
        advisor_request_count: number;
        portfolio_count: number;
        profile_bio: string | null;
        contact_preference: string | null;
        primary_purpose: string | null;
        onboarding_completed_at: string | null;
    };
    contact_consent: { granted: boolean; granted_at: string | null };
    timeline: Array<{ event: string; at: string }>;
    requests: Array<{ id: number; stage: string; objective: string | null; property_type: string | null; updated_at: string | null }>;
    can_manage: boolean;
    can_view_phone: boolean;
    can_revoke_sessions: boolean;
    can_trigger_recovery: boolean;
    can_assign_roles: boolean;
    assignable_roles: string[];
}>();

const suspendForm = useForm({ reason: '' });

/*
 * Promotion: granting a member their first administrative role. The server
 * redirects to the operators surface on success, because this page 404s a
 * role-holder by the surface contract the moment the grant lands.
 */
const promoteForm = useForm<{ roles: string[] }>({ roles: [] });
const promoting = ref(false);

/*
 * Two-tap confirmation for the sensitive one-shot actions: the first press
 * arms the button and renames it, the second fires. Cheaper than a dialog
 * and impossible to trigger by a single stray click.
 */
// Typed for its one server-set error key: the form itself posts no fields,
// but sendRecovery() can come back with errors.recovery.
const actionForm = useForm<{ recovery?: string }>({});
const confirmingLogout = ref(false);
const confirmingRecovery = ref(false);

function forceLogout(): void {
    confirmingLogout.value = false;
    actionForm.post(`/admin/users/${props.account.id}/logout`, { preserveScroll: true });
}

function sendRecovery(): void {
    confirmingRecovery.value = false;
    actionForm.post(`/admin/users/${props.account.id}/recovery`, { preserveScroll: true });
}

</script>

<template>
    <Head :title="account.display_name ?? account.name" />

    <AdminLayout>
        <template #title>{{ account.display_name ?? account.name }}</template>

        <Link
            href="/admin/users"
            class="mb-5 inline-block text-sm text-ink-muted transition-colors hover:text-ink
                   focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-accent"
        >
            {{ t('app.actions.back') }}
        </Link>

        <AppAlert v-if="account.is_suspended" variant="warning" class="mb-5">
            {{ t('identity.users.suspended_banner') }}
            <span v-if="account.suspended_reason">— {{ account.suspended_reason }}</span>
        </AppAlert>

        <!-- ============================ identity ============================ -->
        <AppCard :title="t('leads.detail.account')" class="mb-5">
            <div class="flex items-start gap-5">
                <img
                    v-if="account.photo"
                    :src="account.photo"
                    :alt="account.display_name ?? account.name"
                    class="h-20 w-20 shrink-0 rounded-full border border-line object-cover"
                >
                <span
                    v-else
                    class="flex h-20 w-20 shrink-0 select-none items-center justify-center rounded-full border
                           border-line bg-surface-sunken text-xl font-semibold text-ink-muted"
                    aria-hidden="true"
                >{{ account.initials }}</span>

                <div class="min-w-0 flex-1 space-y-1 text-sm">
                    <p class="font-medium text-ink">{{ account.name }}</p>
                    <p v-if="account.display_name" class="text-ink-muted">{{ account.display_name }}</p>
                    <p :class="account.telegram_linked ? 'text-positive' : 'text-caution'">
                        {{ account.telegram_linked
                            ? `${t('leads.detail.telegram_verified')} · ${account.telegram_linked_at ?? ''}`
                            : t('leads.detail.telegram_missing') }}
                    </p>
                    <!-- Direct phone display for identity.users.contact
                         holders — no ceremony on this administrative
                         account-management surface. dir="ltr" keeps the
                         digits readable inside RTL layouts. -->
                    <p v-if="account.phone" class="text-ink">
                        <span class="numeral font-semibold" dir="ltr">{{ account.phone }}</span>
                    </p>
                    <p v-else class="text-ink-muted">
                        {{ account.phone_present
                            ? t('leads.detail.phone_user_provided')
                            : t('identity.users.phone_absent') }}
                    </p>
                    <p :class="contact_consent.granted ? 'text-positive' : 'text-ink-faint'">
                        {{ contact_consent.granted
                            ? `${t('leads.detail.consent_granted')} · ${contact_consent.granted_at ?? ''}`
                            : t('leads.detail.consent_missing') }}
                    </p>
                    <p v-if="account.profile_bio" class="pt-1 text-ink-muted">{{ account.profile_bio }}</p>
                    <p v-if="account.last_seen_at" class="text-xs text-ink-faint">
                        {{ t('identity.users.last_seen') }}: {{ account.last_seen_at }}
                        <span v-if="account.online" class="text-positive"> · {{ t('identity.users.online') }}</span>
                    </p>
                    <p class="flex flex-wrap gap-x-4 pt-1 text-xs text-ink-faint">
                        <span class="uppercase">{{ account.preferred_locale }}</span>
                        <span v-if="account.primary_purpose">
                            {{ t(`identity.onboarding.purpose_${account.primary_purpose}`) }}
                        </span>
                        <span v-if="account.contact_preference">
                            {{ t(`identity.profile.contact_${account.contact_preference}`) }}
                        </span>
                    </p>
                </div>
            </div>
        </AppCard>

        <!-- ============================ activity ============================ -->
        <div class="mb-5 grid gap-5 lg:grid-cols-2">
            <AppCard :title="t('identity.users.timeline')">
                <ol class="space-y-2">
                    <li v-for="row in timeline" :key="row.event + row.at" class="flex items-baseline gap-3 text-sm">
                        <span class="numeral shrink-0 text-xs text-ink-faint" dir="ltr">{{ row.at }}</span>
                        <span class="text-ink">{{ t(`identity.users.event_${row.event}`) }}</span>
                    </li>
                </ol>
            </AppCard>

            <AppCard :title="t('identity.users.counts')">
                <dl class="grid grid-cols-2 gap-4 text-sm">
                    <div>
                        <dt class="text-xs text-ink-muted">{{ t('identity.users.requests') }}</dt>
                        <dd class="numeral mt-1 text-2xl font-semibold text-ink" dir="ltr">
                            {{ formatNumber(account.advisor_request_count) }}
                        </dd>
                    </div>
                    <div>
                        <dt class="text-xs text-ink-muted">{{ t('identity.users.portfolio') }}</dt>
                        <dd class="numeral mt-1 text-2xl font-semibold text-ink" dir="ltr">
                            {{ formatNumber(account.portfolio_count) }}
                        </dd>
                    </div>
                </dl>
            </AppCard>
        </div>

        <!-- ============================ requests ============================ -->
        <AppCard v-if="requests.length > 0" :title="t('leads.requests.title')" class="mb-5">
            <ul class="divide-y divide-line">
                <li v-for="request in requests" :key="request.id" class="flex flex-wrap items-center gap-3 py-2.5 text-sm">
                    <Link
                        :href="`/admin/leads/${request.id}`"
                        class="font-medium text-brand underline-offset-2 hover:underline"
                    >
                        #{{ request.id }}
                    </Link>
                    <span class="mh-lux-chip text-xs">{{ request.stage }}</span>
                    <span class="text-ink-muted">
                        {{ [request.objective, request.property_type].filter(Boolean).join(' · ') }}
                    </span>
                    <span class="ms-auto text-xs text-ink-faint">{{ request.updated_at }}</span>
                </li>
            </ul>
        </AppCard>

        <!-- =========================== suspension =========================== -->
        <AppCard v-if="can_manage" :title="t('identity.users.manage')">
            <form
                v-if="!account.is_suspended"
                class="space-y-3"
                @submit.prevent="suspendForm.post(`/admin/users/${account.id}/suspend`, { preserveScroll: true })"
            >
                <label for="suspend-reason" class="block text-sm font-medium text-ink">
                    {{ t('identity.users.suspend_reason') }}
                </label>
                <textarea
                    id="suspend-reason"
                    v-model="suspendForm.reason"
                    rows="2"
                    required
                    maxlength="500"
                    class="block w-full rounded-card border border-line bg-surface px-3 py-2.5 text-sm text-ink
                           focus:border-accent focus:outline-none focus:ring-2 focus:ring-accent/40"
                />
                <p v-if="suspendForm.errors.reason" class="text-sm text-negative">{{ suspendForm.errors.reason }}</p>
                <AppButton type="submit" variant="danger" :loading="suspendForm.processing">
                    {{ t('identity.users.suspend') }}
                </AppButton>
            </form>

            <AppButton
                v-else
                type="button"
                variant="secondary"
                :loading="suspendForm.processing"
                @click="suspendForm.post(`/admin/users/${account.id}/reactivate`, { preserveScroll: true })"
            >
                {{ t('identity.users.reactivate') }}
            </AppButton>

            <!-- Promotion to operator: identity.roles.assign only. Renders a
                 deliberate two-step (arm, choose, save) — granting a role is
                 not a casual click. -->
            <div v-if="can_assign_roles" class="mt-5 border-t border-line pt-5">
                <AppButton
                    v-if="!promoting"
                    type="button"
                    variant="secondary"
                    @click="promoting = true"
                >
                    {{ t('identity.administrators.promote') }}
                </AppButton>

                <form v-else class="space-y-3" @submit.prevent="promoteForm.put(`/admin/administrators/${account.id}/roles`)">
                    <p class="text-sm text-ink-muted">{{ t('identity.administrators.promote_hint') }}</p>

                    <div class="grid gap-2 sm:grid-cols-2">
                        <label
                            v-for="key in assignable_roles"
                            :key="key"
                            class="flex cursor-pointer items-center gap-2 rounded-card border border-line px-3 py-2 text-sm hover:bg-surface-sunken"
                        >
                            <input
                                v-model="promoteForm.roles"
                                type="checkbox"
                                :value="key"
                                class="h-4 w-4 rounded border-line text-accent focus:ring-accent"
                            >
                            <span class="text-ink">{{ t(`identity.roles.${key}`) }}</span>
                        </label>
                    </div>

                    <p v-if="promoteForm.errors.roles" class="text-sm text-negative">{{ promoteForm.errors.roles }}</p>

                    <div class="flex gap-2">
                        <AppButton type="submit" :disabled="promoteForm.roles.length === 0" :loading="promoteForm.processing">
                            {{ t('identity.administrators.save_roles') }}
                        </AppButton>
                        <AppButton type="button" variant="secondary" @click="promoting = false">
                            {{ t('app.actions.cancel') }}
                        </AppButton>
                    </div>
                </form>
            </div>

            <!-- Session + recovery actions: each behind its own server-side
                 permission; the buttons only render when the server said so. -->
            <div class="mt-5 flex flex-wrap items-start gap-3 border-t border-line pt-5">
                <div v-if="can_revoke_sessions">
                    <AppButton
                        type="button"
                        variant="secondary"
                        :loading="actionForm.processing"
                        @click="confirmingLogout ? forceLogout() : (confirmingLogout = true)"
                    >
                        {{ confirmingLogout
                            ? t('identity.users.force_logout_confirm')
                            : t('identity.users.force_logout') }}
                    </AppButton>
                </div>

                <div v-if="can_trigger_recovery && account.telegram_linked">
                    <AppButton
                        type="button"
                        variant="secondary"
                        :loading="actionForm.processing"
                        @click="confirmingRecovery ? sendRecovery() : (confirmingRecovery = true)"
                    >
                        {{ confirmingRecovery
                            ? t('identity.users.send_recovery_confirm')
                            : t('identity.users.send_recovery') }}
                    </AppButton>
                    <p v-if="actionForm.errors.recovery" class="mt-1 text-sm text-negative">
                        {{ actionForm.errors.recovery }}
                    </p>
                </div>
            </div>
        </AppCard>
    </AdminLayout>
</template>
