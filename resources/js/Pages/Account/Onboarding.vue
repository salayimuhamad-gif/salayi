<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import AuthLayout from '@/Layouts/AuthLayout.vue';
import AppAlert from '@/Components/ui/AppAlert.vue';
import AppButton from '@/Components/ui/AppButton.vue';
import AppInput from '@/Components/ui/AppInput.vue';
import { t } from '@/lib/i18n';
import { useLocale } from '@/Composables/useLocale';

const { localized } = useLocale();

/*
 * Post-link onboarding (spec §5).
 *
 * The account already exists and the Telegram link is already proven — the
 * route's gate saw to that — so this page is only the human layer: what to
 * call the person, which language to speak, and why they came. Skippable in
 * spirit: every field is prefilled or optional, and the three exits all save.
 *
 * The status list states each claim at its actual strength. "Telegram
 * verified" earned its checkmark through the webhook secret; the phone line
 * says PROVIDED, never verified, because typing a number proves nothing —
 * that honesty is a spec requirement, not a styling choice.
 */
const props = defineProps<{
    display_name: string;
    preferred_locale: string;
    primary_purpose: string | null;
    purposes: string[];
    locales: string[];
    completed: boolean;
    status: {
        telegram_verified: boolean;
        phone_provided: boolean;
        phone_verified: boolean;
        contact_consent: boolean;
    };
}>();

const form = useForm({
    display_name: props.display_name,
    preferred_locale: props.preferred_locale,
    primary_purpose: props.primary_purpose,
    next: 'home' as 'advisor' | 'portfolio' | 'home',
});

function submit(next: 'advisor' | 'portfolio' | 'home'): void {
    form.next = next;
    form.post(localized('/account/onboarding'));
}

const localeNames: Record<string, string> = {
    ckb: 'کوردیی ناوەندی',
    ar: 'العربية',
    en: 'English',
};
</script>

<template>
    <Head :title="t('identity.onboarding.title')" />

    <AuthLayout :title="t('identity.onboarding.title')" :subtitle="t('identity.onboarding.subtitle')">
        <div class="space-y-6">
            <!-- Account status, each claim at its true strength. -->
            <ul class="space-y-2 rounded-card border border-line bg-surface-sunken p-4 text-sm">
                <li class="flex items-center gap-2 text-ink">
                    <span class="text-positive" aria-hidden="true">✓</span>
                    {{ t('identity.onboarding.status_telegram') }}
                </li>
                <li v-if="status.phone_provided" class="flex items-center gap-2 text-ink-muted">
                    <span aria-hidden="true">•</span>
                    {{ status.phone_verified
                        ? t('identity.onboarding.status_phone_verified')
                        : t('identity.onboarding.status_phone_provided') }}
                </li>
                <li class="flex items-center gap-2 text-ink-muted">
                    <span aria-hidden="true">•</span>
                    {{ status.contact_consent
                        ? t('identity.onboarding.status_contact_yes')
                        : t('identity.onboarding.status_contact_no') }}
                </li>
            </ul>

            <AppAlert v-if="completed" variant="success">
                {{ t('identity.onboarding.already_done') }}
            </AppAlert>

            <form class="space-y-5" @submit.prevent="submit('home')">
                <AppInput
                    v-model="form.display_name"
                    :label="t('identity.onboarding.display_name')"
                    name="display_name"
                    autocomplete="nickname"
                    required
                    :hint="t('identity.onboarding.display_name_hint')"
                    :error="form.errors.display_name"
                />

                <div>
                    <label for="onboarding-locale" class="mb-1.5 block text-sm font-medium text-ink">
                        {{ t('identity.register.locale') }}
                    </label>
                    <select
                        id="onboarding-locale"
                        v-model="form.preferred_locale"
                        class="block min-h-11 w-full rounded-card border border-line bg-surface px-3 text-sm text-ink
                               focus:border-accent focus:outline-none focus:ring-2 focus:ring-accent/40"
                    >
                        <option v-for="code in locales" :key="code" :value="code">
                            {{ localeNames[code] ?? code }}
                        </option>
                    </select>
                </div>

                <fieldset>
                    <legend class="mb-2 block text-sm font-medium text-ink">
                        {{ t('identity.onboarding.purpose') }}
                    </legend>
                    <div class="flex flex-wrap gap-2">
                        <button
                            v-for="purpose in purposes"
                            :key="purpose"
                            type="button"
                            class="min-h-11 rounded-card border px-4 text-sm transition-colors
                                   focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-accent"
                            :class="form.primary_purpose === purpose
                                ? 'border-brand bg-brand text-white'
                                : 'border-line bg-surface text-ink-muted hover:bg-surface-sunken'"
                            :aria-pressed="form.primary_purpose === purpose"
                            @click="form.primary_purpose = form.primary_purpose === purpose ? null : purpose"
                        >
                            {{ t(`identity.onboarding.purpose_${purpose}`) }}
                        </button>
                    </div>
                </fieldset>

                <div class="space-y-3 pt-2">
                    <AppButton type="button" block class="min-h-11" :loading="form.processing" @click="submit('advisor')">
                        {{ t('identity.onboarding.go_advisor') }}
                    </AppButton>
                    <AppButton
                        type="button"
                        block
                        variant="secondary"
                        class="min-h-11"
                        :loading="form.processing"
                        @click="submit('portfolio')"
                    >
                        {{ t('identity.onboarding.go_portfolio') }}
                    </AppButton>
                    <button
                        type="submit"
                        class="block min-h-11 w-full text-center text-sm text-ink-muted underline-offset-2
                               hover:underline focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-accent"
                    >
                        {{ t('identity.onboarding.go_home') }}
                    </button>
                </div>
            </form>
        </div>
    </AuthLayout>
</template>
