<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import AuthLayout from '@/Layouts/AuthLayout.vue';
import AppButton from '@/Components/ui/AppButton.vue';
import AppInput from '@/Components/ui/AppInput.vue';
import { t } from '@/lib/i18n';

const props = defineProps<{ token: string; email: string }>();

const form = useForm({
    token: props.token,
    email: props.email,
    password: '',
    password_confirmation: '',
});
</script>

<template>
    <Head :title="t('identity.auth.reset_password')" />

    <AuthLayout :title="t('identity.auth.reset_password')">
        <form class="space-y-5" @submit.prevent="form.post('/reset-password')">
            <AppInput
                v-model="form.email"
                type="email"
                :label="t('identity.auth.email')"
                :error="form.errors.email"
                autocomplete="username"
                required
            />

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
                {{ t('identity.auth.reset_password') }}
            </AppButton>
        </form>
    </AuthLayout>
</template>
