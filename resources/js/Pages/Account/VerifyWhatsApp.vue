<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';
import { Head, Link, useForm, usePage } from '@inertiajs/vue3';
import AppCard from '@/Components/ui/AppCard.vue';
import AppAlert from '@/Components/ui/AppAlert.vue';
import AppButton from '@/Components/ui/AppButton.vue';
import AppInput from '@/Components/ui/AppInput.vue';
import { t } from '@/lib/i18n';
import { useLocale } from '@/Composables/useLocale';

/*
 * The WhatsApp door: request a six-digit code, type it back.
 *
 * Every fact on this screen is SERVER state, re-derived on each render — the
 * page never invents a "code sent" claim of its own, so a refresh, a second
 * tab or a restored mobile tab all show the truth. Nothing sensitive is
 * rendered: the phone arrives masked, and the code exists only in the
 * person's WhatsApp and in the input they type it into.
 *
 * The resend countdown mirrors the server's cooldown so the button disables
 * exactly as long as the server would refuse anyway; the server remains the
 * authority and simply lands the person back here if a stale tab races it.
 */
const props = defineProps<{
    phone_masked: string | null;
    code_sent: boolean;
    resend_in_seconds: number;
    expires_in_seconds: number;
}>();

const page = usePage();

const flashStatus = computed<string | null>(() => {
    const flash = page.props.flash as { status?: string | null } | undefined;

    return flash?.status ?? null;
});

const { localized } = useLocale();

const sendForm = useForm({});
const confirmForm = useForm({ code: '' });

/* Counts down locally from the server-provided seconds; never below zero. */
const resendIn = ref(props.resend_in_seconds);
let ticker: ReturnType<typeof setInterval> | undefined;

onMounted(() => {
    ticker = setInterval(() => {
        if (resendIn.value > 0) {
            resendIn.value -= 1;
        }
    }, 1000);
});

onBeforeUnmount(() => {
    if (ticker !== undefined) {
        clearInterval(ticker);
    }
});

function sendCode(): void {
    sendForm.post(localized('/account/verify/whatsapp/send'), { preserveScroll: true });
}

function confirmCode(): void {
    confirmForm.post(localized('/account/verify/whatsapp/confirm'), { preserveScroll: true });
}
</script>

<template>
    <Head :title="t('identity.whatsapp.title')" />

    <div class="mx-auto max-w-lg px-4 py-10">
        <AppCard>
            <h1 class="text-xl font-semibold">{{ t('identity.whatsapp.title') }}</h1>

            <p class="mt-3 text-sm leading-relaxed opacity-90" data-testid="whatsapp-lead">
                {{ t('identity.whatsapp.lead', { phone: phone_masked ?? '' }) }}
            </p>

            <AppAlert v-if="flashStatus !== null" variant="success" class="mt-4" data-testid="whatsapp-status">
                {{ flashStatus }}
            </AppAlert>

            <!-- Send failures arrive as the SHARED "whatsapp" error bag entry
                 (the send form itself has no fields), read from the page props
                 exactly as the portfolio pages read theirs. -->
            <AppAlert v-if="page.props.errors?.whatsapp" variant="danger" class="mt-4">
                {{ page.props.errors.whatsapp }}
            </AppAlert>

            <!-- Request (or re-request) a code. Disabled while the server's
                 cooldown would refuse anyway. -->
            <form @submit.prevent="sendCode">
                <AppButton
                    type="submit"
                    block
                    class="mt-4"
                    data-testid="send-whatsapp-code"
                    :disabled="resendIn > 0"
                    :loading="sendForm.processing"
                >
                    {{ code_sent ? t('identity.whatsapp.resend_button') : t('identity.whatsapp.send_button') }}
                </AppButton>
            </form>

            <p v-if="resendIn > 0" class="mt-2 text-sm opacity-80" aria-live="polite" data-testid="resend-wait">
                {{ t('identity.whatsapp.resend_wait') }}
            </p>

            <!-- Typing the code back. Shown once a code is live so the field
                 does not invite digits nobody has yet. -->
            <template v-if="code_sent">
                <form class="mt-6 space-y-4" @submit.prevent="confirmCode">
                    <AppInput
                        v-model="confirmForm.code"
                        type="text"
                        autocomplete="one-time-code"
                        dir="ltr"
                        :label="t('identity.whatsapp.code_label')"
                        :error="confirmForm.errors.code"
                        :hint="t('identity.whatsapp.expires_hint')"
                        data-testid="whatsapp-code-input"
                        required
                    />

                    <AppButton
                        type="submit"
                        block
                        data-testid="confirm-whatsapp-code"
                        :loading="confirmForm.processing"
                    >
                        {{ t('identity.whatsapp.confirm_button') }}
                    </AppButton>
                </form>
            </template>

            <div class="mt-6 flex flex-wrap items-center gap-4">
                <Link
                    :href="localized('/account/telegram/link')"
                    class="text-sm underline opacity-70 hover:opacity-100"
                    data-testid="use-telegram-instead"
                >
                    {{ t('identity.whatsapp.use_telegram') }}
                </Link>

                <Link
                    :href="localized('/account/verify')"
                    class="text-sm underline opacity-70 hover:opacity-100"
                    data-testid="back-to-choice"
                >
                    {{ t('identity.whatsapp.back_to_choice') }}
                </Link>
            </div>
        </AppCard>
    </div>
</template>
