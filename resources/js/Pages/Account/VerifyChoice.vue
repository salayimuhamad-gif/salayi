<script setup lang="ts">
import { computed } from 'vue';
import { Head, Link, usePage } from '@inertiajs/vue3';
import AppCard from '@/Components/ui/AppCard.vue';
import AppAlert from '@/Components/ui/AppAlert.vue';
import { t } from '@/lib/i18n';
import { useLocale } from '@/Composables/useLocale';

/*
 * The verification choice: one account, two doors.
 *
 * Registration lands here. Both doors verify the SAME account and one
 * success is enough — the page only states the choice and links out; every
 * decision that matters is made server-side by the flow the person picks.
 *
 * WhatsApp renders only when the server says it is genuinely available
 * (Bird configured AND the account has a phone). When it is not, the page
 * is exactly the old journey with one extra sentence: Telegram, offered
 * alone.
 */
// The template reads the props by name; no script-side handle is needed.
defineProps<{
    telegram_available: boolean;
    whatsapp_available: boolean;
    phone_masked: string | null;
}>();

const page = usePage();

// v7 account-first: registration lands here with a flashed confirmation, so
// the first thing a brand-new account sees is that it EXISTS.
const flashStatus = computed<string | null>(() => {
    const flash = page.props.flash as { status?: string | null } | undefined;

    return flash?.status ?? null;
});

const { localized } = useLocale();
</script>

<template>
    <Head :title="t('identity.verify.title')" />

    <div class="mx-auto max-w-lg px-4 py-10">
        <AppCard>
            <AppAlert v-if="flashStatus !== null" variant="success" class="mb-4" data-testid="account-created">
                {{ flashStatus }}
            </AppAlert>

            <h1 class="text-xl font-semibold">{{ t('identity.verify.title') }}</h1>

            <p class="mt-3 text-sm leading-relaxed opacity-90">
                {{ t('identity.verify.lead') }}
            </p>

            <!-- Door one: WhatsApp code. Rendered first when available so the
                 new option is discoverable, absent entirely when it is not. -->
            <div
                v-if="whatsapp_available"
                class="mt-5 rounded-card border p-4"
                data-testid="verify-whatsapp-option"
            >
                <h2 class="text-base font-semibold">{{ t('identity.verify.whatsapp_title') }}</h2>
                <p class="mt-1 text-sm leading-relaxed opacity-90">
                    {{ t('identity.verify.whatsapp_lead') }}
                </p>
                <Link
                    :href="localized('/account/verify/whatsapp')"
                    data-testid="choose-whatsapp"
                    class="mt-3 flex min-h-11 w-full items-center justify-center rounded-card bg-brand px-4
                           text-sm font-medium text-white transition-colors hover:opacity-90
                           focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-accent"
                >
                    {{ t('identity.verify.whatsapp_button') }}
                </Link>
            </div>

            <AppAlert v-else variant="info" class="mt-5" data-testid="whatsapp-unavailable">
                {{ t('identity.verify.whatsapp_unavailable') }}
            </AppAlert>

            <!-- Door two: the existing Telegram journey, untouched. -->
            <div class="mt-4 rounded-card border p-4" data-testid="verify-telegram-option">
                <h2 class="text-base font-semibold">{{ t('identity.verify.telegram_title') }}</h2>
                <p class="mt-1 text-sm leading-relaxed opacity-90">
                    {{ t('identity.verify.telegram_lead') }}
                </p>

                <AppAlert v-if="!telegram_available" variant="warning" class="mt-3">
                    {{ t('identity.telegram.unavailable') }}
                </AppAlert>

                <Link
                    v-else
                    :href="localized('/account/telegram/link')"
                    data-testid="choose-telegram"
                    class="mt-3 flex min-h-11 w-full items-center justify-center rounded-card border px-4
                           text-sm font-medium transition-colors hover:opacity-90
                           focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-accent"
                >
                    {{ t('identity.verify.telegram_button') }}
                </Link>
            </div>

            <p class="mt-5 text-sm leading-relaxed opacity-80" data-testid="verify-later">
                {{ t('identity.verify.later') }}
            </p>
        </AppCard>
    </div>
</template>
