<script setup lang="ts">
import { computed, ref } from 'vue';
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
type Option = { id: number; name: string };
type AreaOption = Option & { city_id: number };

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
    // Screen 3's optional details. Every one of them may be null forever.
    email: string | null;
    gender: string | null;
    genders: string[];
    date_of_birth: string | null;
    profile_area_id: number | null;
    profile_city_id: number | null;
    cities: Option[];
    areas: AreaOption[];
}>();

const form = useForm({
    display_name: props.display_name,
    preferred_locale: props.preferred_locale,
    primary_purpose: props.primary_purpose,
    email: props.email ?? '',
    gender: props.gender,
    date_of_birth: props.date_of_birth ?? '',
    profile_area_id: props.profile_area_id,
    next: 'home' as 'advisor' | 'portfolio' | 'home',
});

/*
 * The city is a picker, not a stored field.
 *
 * Only `profile_area_id` is ever sent, holding whichever choice is finest —
 * the neighbourhood when one was picked, otherwise the city itself. The server
 * derives the city back from the area's place in the hierarchy, so the two can
 * never drift apart the way two columns would.
 */
const cityId = ref<number | null>(props.profile_city_id);

/*
 * Only the areas inside the chosen city. Empty is a completely normal state:
 * a city whose neighbourhoods an administrator has not published yet simply
 * offers none, and the picker hides itself rather than showing an invented
 * list. Publishing them later makes it appear with no change here.
 */
const areasInCity = computed<AreaOption[]>(() =>
    cityId.value === null ? [] : props.areas.filter((area) => area.city_id === cityId.value),
);

function onCityChange(): void {
    /*
     * The stored value follows the city: picking a different city must not
     * leave a neighbourhood from the previous one attached. Defaulting to the
     * city itself means choosing only a city still records something real.
     */
    form.profile_area_id = cityId.value;
}

function submit(next: 'advisor' | 'portfolio' | 'home'): void {
    form.next = next;
    form.post(localized('/account/onboarding'));
}

const localeNames: Record<string, string> = {
    ckb: 'کوردی',
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

                <!--
                    Screen 3's optional block, fenced off and labelled as
                    optional so the difference between "we need this" and "this
                    is nice to have" is visible rather than implied. Nothing in
                    here is required, nothing in here gates a feature, and every
                    exit below saves whatever has been filled in.
                -->
                <fieldset class="space-y-5 rounded-card border border-line p-4">
                    <legend class="px-1 text-sm font-medium text-ink">
                        {{ t('identity.onboarding.optional_title') }}
                    </legend>

                    <p class="text-sm text-ink-muted">{{ t('identity.onboarding.optional_hint') }}</p>

                    <div>
                        <label for="onboarding-city" class="mb-1.5 block text-sm font-medium text-ink">
                            {{ t('identity.onboarding.city') }}
                        </label>
                        <select
                            id="onboarding-city"
                            v-model="cityId"
                            data-testid="onboarding-city"
                            class="block min-h-11 w-full rounded-card border border-line bg-surface px-3 text-sm text-ink
                                   focus:border-accent focus:outline-none focus:ring-2 focus:ring-accent/40"
                            @change="onCityChange"
                        >
                            <option :value="null">{{ t('identity.onboarding.city_none') }}</option>
                            <option v-for="city in cities" :key="city.id" :value="city.id">{{ city.name }}</option>
                        </select>
                        <p v-if="cities.length === 0" class="mt-1.5 text-sm text-ink-muted">
                            {{ t('identity.onboarding.location_unavailable') }}
                        </p>
                    </div>

                    <!--
                        Shown only when the chosen city actually has published
                        areas. An empty dropdown would read as a fault; absence
                        reads as "not applicable yet", which is the truth.
                    -->
                    <div v-if="areasInCity.length > 0">
                        <label for="onboarding-area" class="mb-1.5 block text-sm font-medium text-ink">
                            {{ t('identity.onboarding.area') }}
                        </label>
                        <select
                            id="onboarding-area"
                            v-model="form.profile_area_id"
                            data-testid="onboarding-area"
                            class="block min-h-11 w-full rounded-card border border-line bg-surface px-3 text-sm text-ink
                                   focus:border-accent focus:outline-none focus:ring-2 focus:ring-accent/40"
                        >
                            <option :value="cityId">{{ t('identity.onboarding.area_none') }}</option>
                            <option v-for="area in areasInCity" :key="area.id" :value="area.id">{{ area.name }}</option>
                        </select>
                        <p v-if="form.errors.profile_area_id" class="mt-1.5 text-sm text-negative">
                            {{ form.errors.profile_area_id }}
                        </p>
                    </div>

                    <AppInput
                        v-model="form.email"
                        type="email"
                        :label="t('identity.onboarding.email')"
                        name="email"
                        autocomplete="email"
                        :hint="t('identity.onboarding.email_hint')"
                        :error="form.errors.email"
                    />

                    <div>
                        <label for="onboarding-gender" class="mb-1.5 block text-sm font-medium text-ink">
                            {{ t('identity.onboarding.gender') }}
                        </label>
                        <select
                            id="onboarding-gender"
                            v-model="form.gender"
                            data-testid="onboarding-gender"
                            class="block min-h-11 w-full rounded-card border border-line bg-surface px-3 text-sm text-ink
                                   focus:border-accent focus:outline-none focus:ring-2 focus:ring-accent/40"
                        >
                            <option :value="null">{{ t('identity.onboarding.gender_unset') }}</option>
                            <option v-for="value in genders" :key="value" :value="value">
                                {{ t(`identity.onboarding.gender_${value}`) }}
                            </option>
                        </select>
                    </div>

                    <!--
                        A date of birth rather than an age: an age is a number
                        that silently becomes wrong every year, while the date
                        stays true and anything needing an age can derive one.
                    -->
                    <AppInput
                        v-model="form.date_of_birth"
                        type="date"
                        :label="t('identity.onboarding.date_of_birth')"
                        name="date_of_birth"
                        autocomplete="bday"
                        dir="ltr"
                        :hint="t('identity.onboarding.date_of_birth_hint')"
                        :error="form.errors.date_of_birth"
                    />
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
