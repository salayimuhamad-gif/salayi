<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';
import AuthLayout from '@/Layouts/AuthLayout.vue';
import AppButton from '@/Components/ui/AppButton.vue';
import AppInput from '@/Components/ui/AppInput.vue';
import { t } from '@/lib/i18n';

/*
 * One identifier field, because this form now serves two populations.
 *
 * Customers register with a phone number and no email address; administrators
 * were seeded with an email and no phone. A field labelled "Email" therefore
 * had no correct value for most of the people looking at it, and their only
 * remaining way in was to open Telegram and press Start again — on every visit.
 *
 * `type="text"`, not `type="email"`: the browser's built-in email validation
 * would reject a perfectly good phone number before the request was ever sent.
 * `autocomplete="username"` is correct for both kinds of value.
 */
defineProps<{ canResetPassword: boolean }>();

const form = useForm({ login: '', password: '', remember: false });

const submit = (): void => form.post('/login', { onFinish: () => form.reset('password') });
</script>

<template>
    <Head :title="t('identity.auth.login')" />

    <AuthLayout :title="t('identity.auth.login')" :subtitle="t('identity.auth.login_subtitle')">
        <form class="space-y-5" @submit.prevent="submit">
            <AppInput
                v-model="form.login"
                type="text"
                :label="t('identity.auth.login_identifier')"
                :hint="t('identity.auth.login_identifier_hint')"
                :error="form.errors.login"
                autocomplete="username"
                required
            />

            <AppInput
                v-model="form.password"
                type="password"
                :label="t('identity.auth.password')"
                :error="form.errors.password"
                autocomplete="current-password"
                required
            />

            <div class="flex items-center justify-between gap-4">
                <label class="flex min-h-11 cursor-pointer items-center gap-2 text-sm text-ink-muted">
                    <input
                        v-model="form.remember"
                        type="checkbox"
                        class="h-4 w-4 rounded border-line text-brand focus:ring-accent"
                    >
                    {{ t('identity.auth.remember_me') }}
                </label>

                <Link
                    v-if="canResetPassword"
                    href="/forgot-password"
                    class="inline-flex min-h-11 items-center text-sm text-brand underline-offset-2
                           hover:underline focus-visible:outline-none focus-visible:ring-2
                           focus-visible:ring-accent"
                >
                    {{ t('identity.auth.forgot_password') }}
                </Link>
            </div>

            <AppButton type="submit" block class="min-h-11" :loading="form.processing">
                {{ t('identity.auth.login') }}
            </AppButton>
        </form>

        <p class="mt-6 text-center text-sm text-ink-muted">
            {{ t('identity.register.create_cta') }}:
            <a
                href="/register"
                class="inline-flex min-h-11 items-center font-medium text-brand underline-offset-2 hover:underline
                       focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-accent"
            >
                {{ t('identity.register.title') }}
            </a>
        </p>
    </AuthLayout>
</template>
