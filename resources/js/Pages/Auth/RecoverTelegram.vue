<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import AuthLayout from '@/Layouts/AuthLayout.vue';
import AppButton from '@/Components/ui/AppButton.vue';
import AppInput from '@/Components/ui/AppInput.vue';
import { t } from '@/lib/i18n';

/*
 * The form behind the Telegram recovery link. The server only renders this
 * page for a challenge that is currently redeemable; every dead link lands
 * on the request form with a neutral notice instead. One submission, then
 * the link is spent whatever happens next.
 */
const props = defineProps<{ token: string }>();

const form = useForm({
    token: props.token,
    password: '',
    password_confirmation: '',
});
</script>

<template>
    <Head :title="t('identity.recovery.reset_title')" />

    <AuthLayout :title="t('identity.recovery.reset_title')" :subtitle="t('identity.recovery.reset_intro')">
        <form class="space-y-5" @submit.prevent="form.post('/recover')">
            <AppInput
                v-model="form.password"
                type="password"
                :label="t('identity.auth.new_password')"
                :error="form.errors.password"
                :hint="t('identity.auth.password_requirements')"
                autocomplete="new-password"
                required
            />

            <AppInput
                v-model="form.password_confirmation"
                type="password"
                :label="t('identity.auth.confirm_password')"
                autocomplete="new-password"
                required
            />

            <AppButton type="submit" block :loading="form.processing">
                {{ t('identity.recovery.reset_title') }}
            </AppButton>
        </form>
    </AuthLayout>
</template>
