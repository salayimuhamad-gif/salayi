<script setup lang="ts">
import { computed, ref } from 'vue';
import { Head, router, useForm } from '@inertiajs/vue3';
import PublicLayout from '@/Layouts/PublicLayout.vue';
import AppAlert from '@/Components/ui/AppAlert.vue';
import AppButton from '@/Components/ui/AppButton.vue';
import AppIcon from '@/Components/Icons/AppIcon.vue';
import AppInput from '@/Components/ui/AppInput.vue';
import UserAvatar from '@/Components/ui/UserAvatar.vue';
import { t } from '@/lib/i18n';
import { useLocale } from '@/Composables/useLocale';

const { localized } = useLocale();

/*
 * The private profile (spec §4), regrouped for Wave 5.
 *
 * Everything on this page already existed in the account — the columns, the
 * vocabularies, the validation, the photo service, the phone-change policy.
 * Wave 5 only organises it: identity, contact, residence, the optional
 * details onboarding introduced, and the verification claims — each stated
 * independently, at its own strength, exactly as the backend records them.
 *
 * The phone field renders MASKED and empty-by-intent: the server sends a
 * recognisable fragment, and the input submits only when the person types a
 * replacement. An untouched field changes nothing — so viewing the page never
 * round-trips the number, and the honest label underneath states what the
 * stored value is: provided, not independently verified.
 *
 * Residence remains ONE stored fact — `profile_area_id` — with the city
 * DERIVED from the area's place in the hierarchy. The city select here is a
 * picker, not a second column: choosing only a city stores the city's own
 * area row, exactly as onboarding does.
 */
interface CityOption { id: number; name: string }
interface AreaOption { id: number; city_id: number; name: string }

const props = defineProps<{
    profile: {
        name: string;
        display_name: string | null;
        preferred_locale: string;
        phone: string | null;
        email: string | null;
        gender: string | null;
        date_of_birth: string | null;
        profile_area_id: number | null;
        profile_bio: string | null;
        contact_preference: string | null;
    };
    avatar: { photo: string | null; thumb: string | null; initials: string };
    verification: {
        telegram_linked: boolean;
        whatsapp_linked: boolean;
        phone_provided: boolean;
        phone_verified: boolean;
    };
    genders: string[];
    profile_city_id: number | null;
    cities: CityOption[];
    areas: AreaOption[];
    contact_preferences: string[];
}>();

const form = useForm({
    name: props.profile.name,
    display_name: props.profile.display_name,
    preferred_locale: props.profile.preferred_locale,
    phone: '',
    email: props.profile.email,
    gender: props.profile.gender,
    date_of_birth: props.profile.date_of_birth,
    profile_area_id: props.profile.profile_area_id,
    profile_bio: props.profile.profile_bio,
    contact_preference: props.profile.contact_preference,
});

/* ------------------------------ photo ------------------------------ */

const photoInput = ref<HTMLInputElement | null>(null);
const photoError = ref<string | null>(null);
const photoBusy = ref(false);

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

/* ---------------------------- residence ---------------------------- */

const cityId = ref<number | null>(props.profile_city_id);

/*
 * Only the areas inside the chosen city. Empty is a completely normal state:
 * a city whose neighbourhoods are not published yet simply offers none, and
 * the city itself remains a real, storable answer.
 */
const cityAreas = computed<AreaOption[]>(() =>
    cityId.value === null ? [] : props.areas.filter((area) => area.city_id === cityId.value),
);

function pickCity(): void {
    // The stored value follows the city: picking a different city must not
    // leave a neighbourhood from the old one behind, and picking only the
    // city still records something real — the city's own row.
    form.profile_area_id = cityId.value;
}

/* ------------------------------ save ------------------------------- */

function save(): void {
    if (form.processing) return;

    form.put(localized('/account/profile'), { preserveScroll: true });
}

const localeNames: Record<string, string> = {
    ckb: 'کوردی',
    ar: 'العربية',
    en: 'English',
};

const sectionClass = 'rounded-xl border border-line bg-surface p-5 sm:p-6';
const selectClass = 'block min-h-11 w-full rounded-card border border-line bg-surface px-3 text-sm text-ink '
    + 'focus:border-accent focus:outline-none focus:ring-2 focus:ring-accent/40';

/*
 * One row per claim, each at its own strength (§15.3 discipline): Telegram
 * and WhatsApp are separate proofs, the phone is separate again. `linked`
 * drives the icon AND the wording — never colour alone.
 */
const claims = computed(() => [
    {
        key: 'telegram',
        label: t('identity.onboarding.status_telegram'),
        linked: props.verification.telegram_linked,
    },
    {
        key: 'whatsapp',
        label: t('identity.profile.status_whatsapp_linked'),
        linked: props.verification.whatsapp_linked,
    },
    {
        key: 'phone',
        label: props.verification.phone_verified
            ? t('identity.onboarding.status_phone_verified')
            : t('identity.onboarding.status_phone_provided'),
        linked: props.verification.phone_verified,
    },
]);
</script>

<template>
    <Head :title="t('identity.profile.title')" />

    <PublicLayout>
        <div class="mx-auto w-full max-w-3xl px-4 py-8 sm:py-10">
            <header class="mb-6">
                <h1 class="font-display text-2xl font-bold text-ink">{{ t('identity.profile.title') }}</h1>
                <p class="mt-1 text-sm text-ink-muted">{{ t('identity.profile.subtitle') }}</p>
            </header>

            <form class="space-y-5" @submit.prevent="save">
                <!-- ========================= identity ========================= -->
                <section :class="sectionClass" data-testid="profile-section-identity">
                    <h2 class="mh-microlabel mb-4">{{ t('identity.profile.section_identity') }}</h2>

                    <div class="mb-5 flex items-center gap-5">
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
                                <!-- md is the component's own 44px tier; a
                                     min-h-11 class appended to sm loses to
                                     the size's min-h-9 in the stylesheet. -->
                                <AppButton
                                    type="button"
                                    size="md"
                                    variant="secondary"
                                    :loading="photoBusy"
                                    @click="photoInput?.click()"
                                >
                                    {{ t('identity.profile.photo_upload') }}
                                </AppButton>
                                <AppButton
                                    v-if="avatar.photo"
                                    type="button"
                                    size="md"
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
                    </div>

                    <div class="grid gap-4 sm:grid-cols-2">
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
                            data-testid="profile-display-name"
                        />

                        <div class="sm:col-span-2">
                            <label for="profile-locale" class="mb-1.5 block text-sm font-medium text-ink">
                                {{ t('identity.register.locale') }}
                            </label>
                            <select id="profile-locale" v-model="form.preferred_locale" :class="selectClass">
                                <option v-for="(label, code) in localeNames" :key="code" :value="code">{{ label }}</option>
                            </select>
                        </div>
                    </div>
                </section>

                <!-- ========================== contact ========================= -->
                <section :class="sectionClass" data-testid="profile-section-contact">
                    <h2 class="mh-microlabel mb-4">{{ t('identity.profile.section_contact') }}</h2>

                    <div class="grid gap-4 sm:grid-cols-2">
                        <AppInput
                            v-model="form.email"
                            type="email"
                            :label="t('identity.onboarding.email')"
                            autocomplete="email"
                            :hint="t('identity.onboarding.email_hint')"
                            :error="form.errors.email"
                            data-testid="profile-email"
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
                                {{ verification.phone_verified
                                    ? t('identity.onboarding.status_phone_verified')
                                    : t('identity.onboarding.status_phone_provided') }}
                            </p>
                        </div>
                    </div>

                    <fieldset class="mt-5">
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

                        <!-- A preference is not a permission; the page that governs
                             permission is one link away. -->
                        <p class="mt-3 text-sm opacity-80">
                            {{ t('identity.profile.contact_preference_not_consent') }}
                            <a
                                :href="localized('/account/privacy')"
                                class="underline"
                                data-testid="privacy-link"
                            >{{ t('identity.privacy.title') }}</a>
                        </p>
                    </fieldset>
                </section>

                <!-- ========================= residence ======================== -->
                <section :class="sectionClass" data-testid="profile-section-residence">
                    <h2 class="mh-microlabel mb-4">{{ t('identity.profile.section_residence') }}</h2>

                    <p v-if="cities.length === 0" class="text-sm text-ink-muted">
                        {{ t('identity.onboarding.location_unavailable') }}
                    </p>

                    <div v-else class="grid gap-4 sm:grid-cols-2">
                        <div>
                            <label for="profile-city" class="mb-1.5 block text-sm font-medium text-ink">
                                {{ t('identity.onboarding.city') }}
                            </label>
                            <select
                                id="profile-city"
                                v-model="cityId"
                                :class="selectClass"
                                data-testid="profile-city"
                                @change="pickCity"
                            >
                                <option :value="null">{{ t('identity.onboarding.city_none') }}</option>
                                <option v-for="city in cities" :key="city.id" :value="city.id">{{ city.name }}</option>
                            </select>
                        </div>

                        <div v-if="cityAreas.length > 0">
                            <label for="profile-area" class="mb-1.5 block text-sm font-medium text-ink">
                                {{ t('identity.onboarding.area') }}
                            </label>
                            <select
                                id="profile-area"
                                v-model="form.profile_area_id"
                                :class="selectClass"
                                data-testid="profile-area"
                            >
                                <!-- The city itself stays a real answer while its
                                     neighbourhoods remain optional detail. -->
                                <option :value="cityId">{{ t('identity.onboarding.area_none') }}</option>
                                <option v-for="area in cityAreas" :key="area.id" :value="area.id">{{ area.name }}</option>
                            </select>
                        </div>
                    </div>

                    <p v-if="form.errors.profile_area_id" class="mt-2 text-sm text-negative">
                        {{ form.errors.profile_area_id }}
                    </p>
                </section>

                <!-- ===================== optional details ===================== -->
                <section :class="sectionClass" data-testid="profile-section-personal">
                    <h2 class="mh-microlabel mb-1.5">{{ t('identity.onboarding.optional_title') }}</h2>
                    <p class="mb-4 text-xs text-ink-faint">{{ t('identity.onboarding.optional_hint') }}</p>

                    <div class="grid gap-4 sm:grid-cols-2">
                        <fieldset>
                            <legend class="mb-2 block text-sm font-medium text-ink">
                                {{ t('identity.onboarding.gender') }}
                            </legend>
                            <div class="flex flex-wrap gap-2">
                                <button
                                    v-for="option in genders"
                                    :key="option"
                                    type="button"
                                    class="min-h-11 rounded-card border px-4 text-sm transition-colors
                                           focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-accent"
                                    :class="form.gender === option
                                        ? 'border-brand bg-brand text-white'
                                        : 'border-line bg-surface text-ink-muted hover:bg-surface-sunken'"
                                    :aria-pressed="form.gender === option"
                                    :data-testid="`profile-gender-${option}`"
                                    @click="form.gender = form.gender === option ? null : option"
                                >
                                    {{ t(`identity.onboarding.gender_${option}`) }}
                                </button>
                            </div>
                            <p v-if="form.errors.gender" class="mt-1 text-sm text-negative">{{ form.errors.gender }}</p>
                        </fieldset>

                        <AppInput
                            v-model="form.date_of_birth"
                            type="date"
                            :label="t('identity.onboarding.date_of_birth')"
                            :hint="t('identity.onboarding.date_of_birth_hint')"
                            :error="form.errors.date_of_birth"
                            data-testid="profile-dob"
                        />
                    </div>

                    <div class="mt-4">
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
                </section>

                <!-- ======================= verification ======================= -->
                <section :class="sectionClass" data-testid="profile-section-verification">
                    <h2 class="mh-microlabel mb-1.5">{{ t('identity.profile.section_verification') }}</h2>
                    <p class="mb-4 text-xs text-ink-faint">{{ t('identity.profile.verification_intro') }}</p>

                    <ul class="space-y-2.5">
                        <li
                            v-for="claim in claims"
                            :key="claim.key"
                            class="flex items-center gap-2.5 text-sm"
                            :data-testid="`verification-${claim.key}`"
                        >
                            <AppIcon
                                :name="claim.linked ? 'check' : 'x-circle'"
                                class="h-4 w-4 shrink-0"
                                :class="claim.linked ? 'text-positive' : 'text-ink-faint'"
                            />
                            <span :class="claim.linked ? 'text-ink' : 'text-ink-muted'">{{ claim.label }}</span>
                            <span
                                v-if="!claim.linked && claim.key !== 'phone'"
                                class="text-xs text-ink-faint"
                            >{{ t('identity.profile.status_not_linked') }}</span>
                        </li>
                    </ul>
                </section>

                <AppAlert v-if="form.recentlySuccessful" variant="success" data-testid="profile-saved">
                    {{ t('identity.profile.saved') }}
                </AppAlert>

                <AppButton
                    type="submit"
                    block
                    class="min-h-11"
                    :loading="form.processing"
                    data-testid="profile-save"
                >
                    {{ t('identity.profile.save') }}
                </AppButton>
            </form>
        </div>
    </PublicLayout>
</template>
