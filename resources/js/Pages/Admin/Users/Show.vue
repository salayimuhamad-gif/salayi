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
 * date, phone user-provided with the full honest label — and the number
 * itself arrives only through the reveal ceremony, into component state,
 * under no-store headers. Suspension asks for words: a suspended person
 * deserves a recorded reason, and the audit stream gets the same one.
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
    can_reveal: boolean;
    can_revoke_sessions: boolean;
    can_trigger_recovery: boolean;
    reveal_reasons: Array<{ value: string; requires_note: boolean }>;
}>();

const suspendForm = useForm({ reason: '' });

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

const revealReason = ref('');
const revealNote = ref('');
const revealBusy = ref(false);
const revealedPhone = ref<string | null>(null);
const revealError = ref<string | null>(null);

const revealNeedsNote = (): boolean =>
    props.reveal_reasons.find((r) => r.value === revealReason.value)?.requires_note ?? false;

async function revealPhone(): Promise<void> {
    if (revealReason.value === '' || revealBusy.value) return;

    revealBusy.value = true;
    revealError.value = null;

    try {
        const response = await fetch(`/admin/users/${props.account.id}/phone`, {
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
            revealedPhone.value = body.phone;
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
                    <p class="text-ink-muted">
                        {{ account.phone_present
                            ? t('leads.detail.phone_user_provided')
                            : t('leads.detail.phone_absent') }}
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

            <!-- The reveal ceremony, verbatim from the leads surface. -->
            <div v-if="can_reveal && account.phone_present" class="mt-4 border-t border-line pt-4">
                <p v-if="revealedPhone" class="text-sm">
                    <span class="block text-xs text-ink-faint">{{ t('leads.detail.reveal_label') }}</span>
                    <span class="numeral mt-1 block text-lg font-semibold text-ink" dir="ltr">{{ revealedPhone }}</span>
                </p>

                <template v-else>
                    <p class="text-sm font-medium text-ink">{{ t('leads.detail.reveal') }}</p>
                    <div class="mt-2 flex flex-wrap items-end gap-3">
                        <div>
                            <label for="user-reveal-reason" class="mb-1 block text-xs text-ink-muted">
                                {{ t('leads.detail.reveal_reason') }}
                            </label>
                            <select
                                id="user-reveal-reason"
                                v-model="revealReason"
                                class="block min-h-11 rounded-card border border-line bg-surface px-3 text-sm text-ink
                                       focus:border-accent focus:outline-none focus:ring-2 focus:ring-accent/40"
                            >
                                <option value="" disabled>—</option>
                                <option v-for="reason in reveal_reasons" :key="reason.value" :value="reason.value">
                                    {{ t(`leads.reveal_reasons.${reason.value}`) }}
                                </option>
                            </select>
                        </div>
                        <div v-if="revealNeedsNote()" class="min-w-48 flex-1">
                            <label for="user-reveal-note" class="mb-1 block text-xs text-ink-muted">
                                {{ t('leads.detail.reveal_note') }}
                            </label>
                            <input
                                id="user-reveal-note"
                                v-model="revealNote"
                                type="text"
                                maxlength="500"
                                class="block min-h-11 w-full rounded-card border border-line bg-surface px-3 text-sm
                                       text-ink focus:border-accent focus:outline-none focus:ring-2 focus:ring-accent/40"
                            >
                        </div>
                        <AppButton
                            type="button"
                            size="sm"
                            :loading="revealBusy"
                            :disabled="revealReason === '' || (revealNeedsNote() && revealNote.trim() === '')"
                            @click="revealPhone"
                        >
                            {{ t('leads.detail.reveal_confirm') }}
                        </AppButton>
                    </div>
                    <p v-if="revealError" class="mt-2 text-sm text-negative" role="alert">{{ revealError }}</p>
                </template>
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
