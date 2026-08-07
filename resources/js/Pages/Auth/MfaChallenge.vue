<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import AuthLayout from '@/Layouts/AuthLayout.vue';
import AppButton from '@/Components/ui/AppButton.vue';
import AppInput from '@/Components/ui/AppInput.vue';
import { t } from '@/lib/i18n';

const form = useForm({ code: '' });
</script>

<template>
    <Head :title="t('identity.mfa.title')" />

    <AuthLayout :title="t('identity.mfa.title')" :subtitle="t('identity.mfa.challenge_subtitle')">
        <form class="space-y-5" @submit.prevent="form.post('/admin/mfa/challenge')">
            <AppInput
                v-model="form.code"
                :label="t('identity.mfa.enter_code')"
                :error="form.errors.code"
                autocomplete="one-time-code"
                dir="ltr"
                required
            />

            <AppButton type="submit" block :loading="form.processing">
                {{ t('app.actions.confirm') }}
            </AppButton>
        </form>
    </AuthLayout>
</template>
