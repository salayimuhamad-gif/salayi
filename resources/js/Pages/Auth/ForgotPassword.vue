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

            <Link href="/login" class="block text-center text-sm text-ink-muted hover:text-ink">
                {{ t('app.actions.back') }}
            </Link>
        </form>
    </AuthLayout>
</template>
