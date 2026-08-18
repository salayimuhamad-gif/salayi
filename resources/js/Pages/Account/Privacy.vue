<script setup lang="ts">
import { Head, router, usePage } from '@inertiajs/vue3';
import AuthLayout from '@/Layouts/AuthLayout.vue';
import AppCard from '@/Components/ui/AppCard.vue';
import AppAlert from '@/Components/ui/AppAlert.vue';
import AppButton from '@/Components/ui/AppButton.vue';
import { t } from '@/lib/i18n';
import { useLocale } from '@/Composables/useLocale';

/*
 * Contact-consent self service (correction v4, BLOCKER 6).
 *
 * The page a person can point at and say "I told you to stop". Before this
 * existed, consent could be given at registration or at advisor submission
 * and never taken back through the product — the only route was to ask a
 * human to edit a database.
 *
 * Two things this page is careful to say out loud, because the absence of
 * either is what makes privacy screens frightening to use:
 *
 *   1. Withdrawing contact consent does NOT delete the account and does
 *      NOT unlink Telegram. People do not withdraw consent when they
 *      suspect it might log them out forever.
 *   2. The record of past decisions is kept. That is not a threat — it is
 *      the evidence that consent was properly obtained, and it is what
 *      protects the person as much as the company.
 */
type ConsentState = {
    type: string;
    granted: boolean;
    decided_at: string | null;
    history_count: number;
};

defineProps<{
    consents: ConsentState[];
    policy_version: string;
}>();

const { localized } = useLocale();
const page = usePage();

function withdraw(): void {
    router.post(localized('/account/privacy/withdraw'), {}, { preserveScroll: true });
}

function grant(): void {
    router.post(localized('/account/privacy/grant'), {}, { preserveScroll: true });
}

function formatDate(value: string | null): string {
    if (value === null) {
        return '—';
    }

    return new Date(value).toLocaleDateString();
}
</script>

<template>
    <Head :title="t('identity.privacy.title')" />

    <AuthLayout :title="t('identity.privacy.title')">
        <div class="mx-auto max-w-2xl px-4 py-10">
            <AppCard>
                <h1 class="text-xl font-semibold">{{ t('identity.privacy.title') }}</h1>
                <p class="mt-3 text-sm leading-relaxed opacity-90">
                    {{ t('identity.privacy.lead') }}
                </p>

                <AppAlert
                    v-if="page.props.flash?.status === 'consent-withdrawn'"
                    tone="success"
                    class="mt-4"
                    data-testid="consent-withdrawn-flash"
                >
                    {{ t('identity.privacy.withdrawn_confirmation') }}
                </AppAlert>

                <AppAlert
                    v-if="page.props.flash?.status === 'consent-granted'"
                    tone="success"
                    class="mt-4"
                    data-testid="consent-granted-flash"
                >
                    {{ t('identity.privacy.granted_confirmation') }}
                </AppAlert>

                <section
                    v-for="consent in consents"
                    :key="consent.type"
                    class="mt-6 rounded border p-4"
                    data-testid="consent-row"
                >
                    <h2 class="text-base font-semibold">
                        {{ t('identity.privacy.company_contact_title') }}
                    </h2>
                    <p class="mt-1 text-sm opacity-80">
                        {{ t('identity.privacy.company_contact_help') }}
                    </p>

                    <p class="mt-3 text-sm">
                        <span class="font-medium">{{ t('identity.privacy.current_state') }}:</span>
                        <span data-testid="consent-state">
                            {{
                                consent.granted
                                    ? t('identity.privacy.state_granted')
                                    : t('identity.privacy.state_withdrawn')
                            }}
                        </span>
                        <span class="opacity-70"> · {{ formatDate(consent.decided_at) }}</span>
                    </p>

                    <AppButton
                        v-if="consent.granted"
                        class="mt-4"
                        variant="secondary"
                        data-testid="withdraw-consent"
                        @click="withdraw"
                    >
                        {{ t('identity.privacy.withdraw_button') }}
                    </AppButton>

                    <AppButton
                        v-else
                        class="mt-4"
                        data-testid="grant-consent"
                        @click="grant"
                    >
                        {{ t('identity.privacy.grant_button') }}
                    </AppButton>
                </section>

                <p class="mt-6 text-sm opacity-80">
                    {{ t('identity.privacy.not_account_deletion') }}
                </p>
                <p class="mt-2 text-xs opacity-70">
                    {{ t('identity.privacy.history_kept') }}
                </p>
            </AppCard>
        </div>
    </AuthLayout>
</template>
