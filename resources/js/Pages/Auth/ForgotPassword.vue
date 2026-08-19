<script setup lang="ts">
import { computed } from 'vue';
import { Head, Link, useForm, usePage } from '@inertiajs/vue3';
import AuthLayout from '@/Layouts/AuthLayout.vue';
import AppButton from '@/Components/ui/AppButton.vue';
import AppInput from '@/Components/ui/AppInput.vue';
import AppAlert from '@/Components/ui/AppAlert.vue';
import { t } from '@/lib/i18n';
import type { SharedPageProps } from '@/Types/inertia';

const page = usePage<SharedPageProps & { status?: string }>();
const form = useForm({ email: '' });
const status = computed(() => (page.props.status as string | undefined) ?? null);

/*
 * The Telegram alternative. Customers register with a phone and may never
 * add an email, so for most accounts this section is the only self-service
 * recovery there is. Its notice is exactly as non-committal as the email
 * one, and for the same reason.
 */
const telegramForm = useForm({ phone: '' });
</script>

<template>
    <Head :title="t('identity.auth.forgot_password')" />

    <AuthLayout :title="t('identity.auth.forgot_password')" :subtitle="t('identity.auth.forgot_subtitle')">
        <!--
          The success message is identical whether or not the address exists.
          Confirming an address is registered would turn this form into an
          account enumeration oracle, and these accounts are named
          administrators of a named company.
        -->
        <AppAlert v-if="status" variant="success" class="mb-5">
            {{ status }}
        </AppAlert>

        <form class="space-y-5" @submit.prevent="form.post('/forgot-password')">
            <AppInput
                v-model="form.email"
                type="email"
                :label="t('identity.auth.email')"
                :error="form.errors.email"
                autocomplete="username"
                required
            />

            <AppButton type="submit" block :loading="form.processing">
                {{ t('identity.auth.send_reset_link') }}
            </AppButton>
        </form>

        <div class="my-6 border-t border-line" aria-hidden="true" />

        <section :aria-label="t('identity.recovery.telegram_title')">
            <h2 class="text-sm font-semibold text-ink">{{ t('identity.recovery.telegram_title') }}</h2>
            <p class="mt-1 text-sm text-ink-muted">{{ t('identity.recovery.telegram_intro') }}</p>

            <form class="mt-4 space-y-5" @submit.prevent="telegramForm.post('/forgot-password/telegram')">
                <AppInput
                    v-model="telegramForm.phone"
                    type="tel"
                    dir="ltr"
                    :label="t('identity.recovery.phone_label')"
                    :error="telegramForm.errors.phone"
                    autocomplete="tel"
                    required
                />

                <AppButton type="submit" variant="secondary" block :loading="telegramForm.processing">
                    {{ t('identity.recovery.request_button') }}
                </AppButton>
            </form>
        </section>

        <Link href="/login" class="mt-6 block text-center text-sm text-ink-muted hover:text-ink">
            {{ t('app.actions.back') }}
        </Link>
    </AuthLayout>
</template>
