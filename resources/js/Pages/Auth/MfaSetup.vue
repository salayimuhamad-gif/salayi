<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import AuthLayout from '@/Layouts/AuthLayout.vue';
import AppButton from '@/Components/ui/AppButton.vue';
import AppInput from '@/Components/ui/AppInput.vue';
import AppAlert from '@/Components/ui/AppAlert.vue';
import { t } from '@/lib/i18n';

defineProps<{ secret: string; otpauthUri: string }>();

const form = useForm({ code: '' });
</script>

<template>
    <Head :title="t('identity.mfa.setup_title')" />

    <AuthLayout :title="t('identity.mfa.setup_title')" :subtitle="t('identity.mfa.required_notice')">
        <AppAlert variant="warning" class="mb-6">
            {{ t('identity.mfa.mandatory_explanation') }}
        </AppAlert>

        <ol class="mb-6 space-y-3 text-sm text-ink-muted">
            <li class="flex gap-3">
                <span class="numeral shrink-0 font-semibold text-brand">1</span>
                <span>{{ t('identity.mfa.step_install_app') }}</span>
            </li>
            <li class="flex gap-3">
                <span class="numeral shrink-0 font-semibold text-brand">2</span>
                <span>{{ t('identity.mfa.step_add_key') }}</span>
            </li>
            <li class="flex gap-3">
                <span class="numeral shrink-0 font-semibold text-brand">3</span>
                <span>{{ t('identity.mfa.step_enter_code') }}</span>
            </li>
        </ol>

        <!--
          The secret is shown as text rather than only a QR code. A QR image
          needs a client-side library and, more practically, an administrator
          setting this up on the same phone that holds the authenticator app
          cannot scan their own screen.
        -->
        <div class="mb-6 rounded-card border border-line bg-surface-sunken p-4">
            <p class="mh-label mb-2">{{ t('identity.mfa.secret_key') }}</p>
            <code class="numeral block break-all font-mono text-sm text-ink">{{ secret }}</code>
        </div>

        <form class="space-y-5" @submit.prevent="form.post('/admin/mfa/setup')">
            <AppInput
                v-model="form.code"
                :label="t('identity.mfa.enter_code')"
                :error="form.errors.code"
                autocomplete="one-time-code"
                dir="ltr"
                required
            />

            <AppButton type="submit" block :loading="form.processing">
                {{ t('identity.mfa.confirm_enrolment') }}
            </AppButton>
        </form>
    </AuthLayout>
</template>
