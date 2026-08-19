<script setup lang="ts">
import { ref } from 'vue';
import { Head, router, useForm } from '@inertiajs/vue3';
import AuthLayout from '@/Layouts/AuthLayout.vue';
import AppAlert from '@/Components/ui/AppAlert.vue';
import AppButton from '@/Components/ui/AppButton.vue';
import AppInput from '@/Components/ui/AppInput.vue';
import UserAvatar from '@/Components/ui/UserAvatar.vue';
import { t } from '@/lib/i18n';
import { useLocale } from '@/Composables/useLocale';

const { localized } = useLocale();

/*
 * The private profile (spec §4).
 *
 * The phone field renders MASKED and empty-by-intent: the server sends a
 * recognisable fragment, and the input submits only when the person types a
 * replacement. An untouched field changes nothing — so viewing the page never
 * round-trips the number, and the honest label underneath states what the
 * stored value is: provided, not independently verified.
 */
interface AreaOption { id: number; name_ckb: string; name_ar: string | null; name_en: string | null }

const props = defineProps<{
    profile: {
        name: string;
        display_name: string | null;
        preferred_locale: string;
        phone: string | null;
        profile_area_id: number | null;
        profile_bio: string | null;
        contact_preference: string | null;
    };
    avatar: { photo: string | null; thumb: string | null; initials: string };
    phone_verified: boolean;
    telegram_linked: boolean;
    areas: AreaOption[];
    contact_preferences: string[];
}>();

const form = useForm({
    name: props.profile.name,
    display_name: props.profile.display_name,
    preferred_locale: props.profile.preferred_locale,
    phone: '',
    profile_area_id: props.profile.profile_area_id,
    profile_bio: props.profile.profile_bio,
    contact_preference: props.profile.contact_preference,
});

const photoInput = ref<HTMLInputElement | null>(null);
const photoError = ref<string | null>(null);
const photoBusy = ref(false);

function areaName(area: AreaOption): string {
    const locale = document.documentElement.lang || 'ckb';

    return (locale === 'ar' ? area.name_ar : locale === 'en' ? area.name_en : area.name_ckb) ?? area.name_ckb;
}

function uploadPhoto(): void {
    const file = photoInput.value?.files?.[0];

    if (!file) return;

    photoError.value = null;
    photoBusy.value = true;

    router.post(localized('/account/profile/photo'), { photo: file }, {
        forceFormData: true,
        preserveScroll: true,
        onError: (errors) => { photoError.value = errors.photo ?? null; },
        onFinish: () => {
            photoBusy.value = false;
            if (photoInput.value) photoInput.value.value = '';
        },
    });
}

function removePhoto(): void {
    photoBusy.value = true;
    router.delete(localized('/account/profile/photo'), {
        preserveScroll: true,
        onFinish: () => { photoBusy.value = false; },
    });
}

const localeNames: Record<string, string> = {
    ckb: 'کوردیی ناوەندی',
    ar: 'العربية',
    en: 'English',
};
</script>

<template>
    <Head :title="t('identity.profile.title')" />

    <AuthLayout :title="t('identity.profile.title')" :subtitle="t('identity.profile.subtitle')">
        <div class="space-y-8">
            <div>
                <a
                    :href="localized('/')"
                    class="inline-flex min-h-11 items-center rounded-card border border-line bg-surface px-4 py-2 text-sm font-medium text-ink transition-colors hover:bg-surface-sunken focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-accent"
                    data-testid="profile-return-site"
                >
                    {{ t('identity.telegram.return_button') }}
                </a>
            </div>
            <!-- ============================ photo ============================ -->
            <section class="flex items-center gap-5">
                <UserAvatar :photo="avatar.photo" :initials="avatar.initials" :name="profile.name" size="lg" />

                <div class="min-w-0 flex-1 space-y-2">
                    <input
                        ref="photoInput"
                        type="file"
                        accept="image/jpeg,image/png,image/webp"
                        class="sr-only"
                        @change="uploadPhoto"
                    >
                    <div class="flex flex-wrap gap-2">
                        <AppButton
                            type="button"
                            size="sm"
                            variant="secondary"
                            :loading="photoBusy"
                            @click="photoInput?.click()"
                        >
                            {{ t('identity.profile.photo_upload') }}
                        </AppButton>
                        <AppButton
                            v-if="avatar.photo"
                            type="button"
                            size="sm"
                            variant="ghost"
                            :loading="photoBusy"
                            @click="removePhoto"
                        >
                            {{ t('identity.profile.photo_remove') }}
                        </AppButton>
                    </div>
                    <p class="text-xs text-ink-faint">{{ t('identity.profile.photo_hint') }}</p>
                    <p v-if="photoError" class="text-sm text-negative" role="alert">{{ photoError }}</p>
                </div>
            </section>

            <!-- =========================== details =========================== -->
            <form class="space-y-5" @submit.prevent="form.put(localized('/account/profile'))">
                <AppInput
                    v-model="form.name"
                    :label="t('identity.register.name')"
                    autocomplete="name"
                    required
                    :error="form.errors.name"
                />

                <AppInput
                    v-model="form.display_name"
                    :label="t('identity.onboarding.display_name')"
                    autocomplete="nickname"
                    :hint="t('identity.onboarding.display_name_hint')"
                    :error="form.errors.display_name"
                />

                <div>
                    <AppInput
                        v-model="form.phone"
                        type="tel"
                        :label="t('identity.register.phone')"
                        autocomplete="tel"
                        dir="ltr"
                        :hint="(profile.phone ? profile.phone + ' — ' : '') + t('identity.profile.phone_change_hint')"
                        :error="form.errors.phone"
                    />
                    <!-- The claim at its true strength, on the owner's own page too. -->
                    <p class="mt-1.5 text-xs text-ink-faint">
                        {{ phone_verified
                            ? t('identity.onboarding.status_phone_verified')
                            : t('identity.onboarding.status_phone_provided') }}
                    </p>
                </div>

                <div>
                    <label for="profile-locale" class="mb-1.5 block text-sm font-medium text-ink">
                        {{ t('identity.register.locale') }}
                    </label>
                    <select
                        id="profile-locale"
                        v-model="form.preferred_locale"
                        class="block min-h-11 w-full rounded-card border border-line bg-surface px-3 text-sm text-ink
                               focus:border-accent focus:outline-none focus:ring-2 focus:ring-accent/40"
                    >
                        <option v-for="(label, code) in localeNames" :key="code" :value="code">{{ label }}</option>
                    </select>
                </div>

                <div>
                    <label for="profile-area" class="mb-1.5 block text-sm font-medium text-ink">
                        {{ t('identity.profile.area') }}
                    </label>
                    <select
                        id="profile-area"
                        v-model="form.profile_area_id"
                        class="block min-h-11 w-full rounded-card border border-line bg-surface px-3 text-sm text-ink
                               focus:border-accent focus:outline-none focus:ring-2 focus:ring-accent/40"
                    >
                        <option :value="null">{{ t('identity.profile.area_none') }}</option>
                        <option v-for="area in areas" :key="area.id" :value="area.id">{{ areaName(area) }}</option>
                    </select>
                </div>

                <div>
                    <label for="profile-bio" class="mb-1.5 block text-sm font-medium text-ink">
                        {{ t('identity.profile.bio') }}
                    </label>
                    <textarea
                        id="profile-bio"
                        v-model="form.profile_bio"
                        rows="3"
                        maxlength="500"
                        class="block w-full rounded-card border border-line bg-surface px-3 py-2.5 text-sm text-ink
                               focus:border-accent focus:outline-none focus:ring-2 focus:ring-accent/40"
                    />
                    <p v-if="form.errors.profile_bio" class="mt-1 text-sm text-negative">{{ form.errors.profile_bio }}</p>
                </div>

                <fieldset>
                    <legend class="mb-2 block text-sm font-medium text-ink">
                        {{ t('identity.profile.contact_preference') }}
                    </legend>
                    <div class="flex flex-wrap gap-2">
                        <button
                            v-for="option in contact_preferences"
                            :key="option"
                            type="button"
                            class="min-h-11 rounded-card border px-4 text-sm transition-colors
                                   focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-accent"
                            :class="form.contact_preference === option
                                ? 'border-brand bg-brand text-white'
                                : 'border-line bg-surface text-ink-muted hover:bg-surface-sunken'"
                            :aria-pressed="form.contact_preference === option"
                            @click="form.contact_preference = form.contact_preference === option ? null : option"
                        >
                            {{ t(`identity.profile.contact_${option}`) }}
                        </button>
                    </div>

                    <!--
                        v4 BLOCKER 6. A preference is not a permission, and
                        the two were being read as one thing. Saying so
                        here — next to the control people mistook for a
                        consent switch — and linking to the page that
                        actually governs permission is the correction the
                        review asked for.
                    -->
                    <p class="mt-3 text-sm opacity-80">
                        {{ t('identity.profile.contact_preference_not_consent') }}
                        <a
                            :href="localized('/account/privacy')"
                            class="underline"
                            data-testid="privacy-link"
                        >{{ t('identity.privacy.title') }}</a>
                    </p>
                </fieldset>

                <AppAlert v-if="form.recentlySuccessful" variant="success">
                    {{ t('identity.profile.saved') }}
                </AppAlert>

                <AppButton type="submit" block class="min-h-11" :loading="form.processing">
                    {{ t('identity.profile.save') }}
                </AppButton>
            </form>
        </div>
    </AuthLayout>
</template>
