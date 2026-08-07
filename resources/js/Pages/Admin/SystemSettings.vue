<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import AppAlert from '@/Components/ui/AppAlert.vue';
import AppButton from '@/Components/ui/AppButton.vue';
import AppCard from '@/Components/ui/AppCard.vue';
import AppInput from '@/Components/ui/AppInput.vue';
import AppSelect from '@/Components/ui/AppSelect.vue';
import { t } from '@/lib/i18n';

/*
 * RECONSTRUCTED SOURCE. Production ships a compiled chunk for this page
 * (assets/SystemSettings-MHfix-MH2.js) whose .vue source was never committed
 * anywhere — it was built off-tree and only the output was deployed. This
 * file is that page rewritten against the two contracts that DO exist in the
 * tree: SystemSettingsController's Inertia props and validation rules, and
 * the lang/system.php keys the deployed chunk renders. Without it, the next
 * build would silently LOSE the admin settings screen production has today.
 *
 * The secret-field pattern, because it decides what the browser ever sees:
 * a stored secret is reported only as CONFIGURED / NOT CONFIGURED. The input
 * is empty by intent — empty means "keep what is stored", a typed value
 * means "replace it", and the explicit clear switch means "remove it". The
 * value itself never makes the round trip.
 */
interface Option { value: string; label: string }

const props = defineProps<{
    general: {
        app_name: string;
        app_url: string;
        timezone: string;
        default_locale: string;
        enabled_locales: string[];
        queue_connection: string;
    };
    supported_locales: Option[];
    timezones: string[];
    environment_writable: boolean;
    can_update_general: boolean;
    can_manage_integrations: boolean;
    integrations: {
        mail: {
            host: string; port: number; username: string; scheme: string;
            from_address: string; from_name: string; password_configured: boolean;
        };
        maps: { provider: string; maplibre_style_url: string; google_key_configured: boolean };
        telegram: { bot_username: string; bot_token_configured: boolean; webhook_secret_configured: boolean };
        ai: {
            provider: string; base_url: string; model: string; fallback_model: string;
            timeout: number; monthly_cost_limit_usd: number; api_key_configured: boolean;
        };
    } | null;
}>();

const general = useForm({
    app_name: props.general.app_name,
    app_url: props.general.app_url,
    timezone: props.general.timezone,
    default_locale: props.general.default_locale,
    enabled_locales: [...props.general.enabled_locales],
    queue_connection: props.general.queue_connection,
});

const integrations = useForm({
    mail_host: props.integrations?.mail.host ?? '',
    mail_port: props.integrations?.mail.port ?? 587,
    mail_username: props.integrations?.mail.username ?? '',
    mail_password: '',
    clear_mail_password: false,
    mail_scheme: props.integrations?.mail.scheme || 'tls',
    mail_from_address: props.integrations?.mail.from_address ?? '',
    mail_from_name: props.integrations?.mail.from_name ?? '',

    map_provider: props.integrations?.maps.provider || 'maplibre',
    maplibre_style_url: props.integrations?.maps.maplibre_style_url ?? '',
    google_maps_api_key: '',
    clear_google_maps_api_key: false,

    telegram_bot_username: props.integrations?.telegram.bot_username ?? '',
    telegram_bot_token: '',
    clear_telegram_bot_token: false,
    telegram_webhook_secret: '',
    clear_telegram_webhook_secret: false,

    ai_provider: props.integrations?.ai.provider || 'null',
    ai_base_url: props.integrations?.ai.base_url ?? '',
    ai_api_key: '',
    clear_ai_api_key: false,
    ai_model: props.integrations?.ai.model ?? '',
    ai_fallback_model: props.integrations?.ai.fallback_model ?? '',
    ai_timeout: props.integrations?.ai.timeout ?? 30,
    ai_monthly_cost_limit_usd: props.integrations?.ai.monthly_cost_limit_usd ?? 0,
});

function toggleLocale(code: string): void {
    general.enabled_locales = general.enabled_locales.includes(code)
        ? general.enabled_locales.filter((c) => c !== code)
        : [...general.enabled_locales, code];
}

const timezoneOptions = props.timezones.map((zone) => ({ value: zone, label: zone }));
const queueOptions = [
    { value: 'database', label: 'database' },
    { value: 'sync', label: 'sync' },
];
const schemeOptions = ['tls', 'ssl', 'smtp'].map((value) => ({ value, label: value.toUpperCase() }));
const mapProviderOptions = [
    { value: 'maplibre', label: 'MapLibre' },
    { value: 'google', label: 'Google Maps' },
];
const aiProviderOptions = [
    { value: 'null', label: '—' },
    { value: 'openai_compatible', label: 'OpenAI-compatible' },
];

const generalDisabled = !props.environment_writable || !props.can_update_general;
const integrationsDisabled = !props.environment_writable || !props.can_manage_integrations;
</script>

<template>
    <AdminLayout :title="t('system.title')">
        <div class="mx-auto max-w-3xl space-y-8">
            <header>
                <h1 class="text-xl font-semibold text-ink">{{ t('system.title') }}</h1>
                <p class="mt-1 text-sm text-ink-muted">{{ t('system.intro') }}</p>
            </header>

            <AppAlert v-if="!environment_writable" variant="warning">
                {{ t('system.environment_not_writable') }}
            </AppAlert>

            <!-- =========================== general =========================== -->
            <AppCard>
                <form class="space-y-5 p-5" @submit.prevent="general.put('/admin/system/settings/general')">
                    <div>
                        <h2 class="text-base font-semibold text-ink">{{ t('system.general.title') }}</h2>
                        <p class="mt-1 text-sm text-ink-muted">{{ t('system.general.description') }}</p>
                    </div>

                    <AppInput
                        v-model="general.app_name"
                        :label="t('system.general.app_name')"
                        required
                        :error="general.errors.app_name"
                    />
                    <AppInput
                        v-model="general.app_url"
                        type="url"
                        dir="ltr"
                        :label="t('system.general.app_url')"
                        required
                        :error="general.errors.app_url"
                    />
                    <AppSelect
                        v-model="general.timezone"
                        :label="t('system.general.timezone')"
                        :options="timezoneOptions"
                        required
                        :error="general.errors.timezone"
                    />
                    <AppSelect
                        v-model="general.default_locale"
                        :label="t('system.general.default_locale')"
                        :options="supported_locales"
                        required
                        :error="general.errors.default_locale"
                    />

                    <fieldset>
                        <legend class="mb-2 block text-sm font-medium text-ink">
                            {{ t('system.general.enabled_locales') }}
                        </legend>
                        <div class="flex flex-wrap gap-4">
                            <label
                                v-for="option in supported_locales"
                                :key="option.value"
                                class="flex min-h-11 cursor-pointer items-center gap-2 text-sm text-ink"
                            >
                                <input
                                    type="checkbox"
                                    :checked="general.enabled_locales.includes(option.value)"
                                    class="h-4 w-4 rounded border-line text-brand focus:ring-accent"
                                    @change="toggleLocale(option.value)"
                                >
                                {{ option.label }}
                            </label>
                        </div>
                        <p v-if="general.errors.enabled_locales" class="mt-1 text-sm text-negative">
                            {{ general.errors.enabled_locales }}
                        </p>
                    </fieldset>

                    <AppSelect
                        v-model="general.queue_connection"
                        :label="t('system.general.queue_connection')"
                        :options="queueOptions"
                        required
                        :error="general.errors.queue_connection"
                    />

                    <AppButton type="submit" :loading="general.processing" :disabled="generalDisabled">
                        {{ t('system.general.save') }}
                    </AppButton>
                </form>
            </AppCard>

            <!-- ======================== integrations ========================= -->
            <AppCard v-if="integrations">
                <form class="space-y-8 p-5" @submit.prevent="integrations.put('/admin/system/settings/integrations')">
                    <div>
                        <h2 class="text-base font-semibold text-ink">{{ t('system.integrations.title') }}</h2>
                        <p class="mt-1 text-sm text-ink-muted">{{ t('system.integrations.description') }}</p>
                        <p class="mt-1 text-xs text-ink-faint">{{ t('system.integrations.secret_hint') }}</p>
                    </div>

                    <!-- mail -->
                    <section class="space-y-4">
                        <div>
                            <h3 class="text-sm font-semibold text-ink">{{ t('system.mail.title') }}</h3>
                            <p class="mt-0.5 text-xs text-ink-muted">{{ t('system.mail.description') }}</p>
                        </div>
                        <div class="grid gap-4 sm:grid-cols-2">
                            <AppInput v-model="integrations.mail_host" dir="ltr" :label="t('system.mail.host')" required :error="integrations.errors.mail_host" />
                            <AppInput v-model="integrations.mail_port" type="number" dir="ltr" :label="t('system.mail.port')" required :error="integrations.errors.mail_port" />
                            <AppInput v-model="integrations.mail_username" dir="ltr" :label="t('system.mail.username')" :error="integrations.errors.mail_username" />
                            <AppSelect v-model="integrations.mail_scheme" :label="t('system.mail.scheme')" :options="schemeOptions" required :error="integrations.errors.mail_scheme" />
                            <AppInput v-model="integrations.mail_from_address" type="email" dir="ltr" :label="t('system.mail.from_address')" required :error="integrations.errors.mail_from_address" />
                            <AppInput v-model="integrations.mail_from_name" :label="t('system.mail.from_name')" required :error="integrations.errors.mail_from_name" />
                        </div>
                        <div class="space-y-2">
                            <p class="text-xs text-ink-muted">
                                {{ t('system.mail.password') }}:
                                <span :class="integrations && props.integrations!.mail.password_configured ? 'text-positive' : 'text-ink-faint'">
                                    {{ props.integrations!.mail.password_configured
                                        ? t('system.integrations.configured')
                                        : t('system.integrations.not_configured') }}
                                </span>
                            </p>
                            <AppInput v-model="integrations.mail_password" type="password" dir="ltr" autocomplete="new-password" :label="t('system.integrations.new_secret')" :error="integrations.errors.mail_password" />
                            <label class="flex min-h-11 cursor-pointer items-center gap-2 text-sm text-ink-muted">
                                <input v-model="integrations.clear_mail_password" type="checkbox" class="h-4 w-4 rounded border-line text-brand focus:ring-accent">
                                {{ t('system.integrations.clear_secret') }}
                            </label>
                        </div>
                    </section>

                    <!-- maps -->
                    <section class="space-y-4">
                        <div>
                            <h3 class="text-sm font-semibold text-ink">{{ t('system.maps.title') }}</h3>
                            <p class="mt-0.5 text-xs text-ink-muted">{{ t('system.maps.description') }}</p>
                        </div>
                        <AppSelect v-model="integrations.map_provider" :label="t('system.maps.provider')" :options="mapProviderOptions" required :error="integrations.errors.map_provider" />
                        <AppInput v-model="integrations.maplibre_style_url" type="url" dir="ltr" :label="t('system.maps.style_url')" :error="integrations.errors.maplibre_style_url" />
                        <div class="space-y-2">
                            <p class="text-xs text-ink-muted">
                                {{ t('system.maps.google_key') }}:
                                <span :class="props.integrations!.maps.google_key_configured ? 'text-positive' : 'text-ink-faint'">
                                    {{ props.integrations!.maps.google_key_configured
                                        ? t('system.integrations.configured')
                                        : t('system.integrations.not_configured') }}
                                </span>
                            </p>
                            <AppInput v-model="integrations.google_maps_api_key" type="password" dir="ltr" autocomplete="off" :label="t('system.integrations.new_secret')" :error="integrations.errors.google_maps_api_key" />
                            <label class="flex min-h-11 cursor-pointer items-center gap-2 text-sm text-ink-muted">
                                <input v-model="integrations.clear_google_maps_api_key" type="checkbox" class="h-4 w-4 rounded border-line text-brand focus:ring-accent">
                                {{ t('system.integrations.clear_secret') }}
                            </label>
                        </div>
                    </section>

                    <!-- telegram -->
                    <section class="space-y-4">
                        <div>
                            <h3 class="text-sm font-semibold text-ink">{{ t('system.telegram.title') }}</h3>
                            <p class="mt-0.5 text-xs text-ink-muted">{{ t('system.telegram.description') }}</p>
                        </div>
                        <AppInput v-model="integrations.telegram_bot_username" dir="ltr" :label="t('system.telegram.username')" :error="integrations.errors.telegram_bot_username" />
                        <div class="space-y-2">
                            <p class="text-xs text-ink-muted">
                                {{ t('system.telegram.token') }}:
                                <span :class="props.integrations!.telegram.bot_token_configured ? 'text-positive' : 'text-ink-faint'">
                                    {{ props.integrations!.telegram.bot_token_configured
                                        ? t('system.integrations.configured')
                                        : t('system.integrations.not_configured') }}
                                </span>
                            </p>
                            <AppInput v-model="integrations.telegram_bot_token" type="password" dir="ltr" autocomplete="off" :label="t('system.integrations.new_secret')" :error="integrations.errors.telegram_bot_token" />
                            <label class="flex min-h-11 cursor-pointer items-center gap-2 text-sm text-ink-muted">
                                <input v-model="integrations.clear_telegram_bot_token" type="checkbox" class="h-4 w-4 rounded border-line text-brand focus:ring-accent">
                                {{ t('system.integrations.clear_secret') }}
                            </label>
                        </div>
                        <div class="space-y-2">
                            <p class="text-xs text-ink-muted">
                                {{ t('system.telegram.webhook_secret') }}:
                                <span :class="props.integrations!.telegram.webhook_secret_configured ? 'text-positive' : 'text-ink-faint'">
                                    {{ props.integrations!.telegram.webhook_secret_configured
                                        ? t('system.integrations.configured')
                                        : t('system.integrations.not_configured') }}
                                </span>
                            </p>
                            <AppInput v-model="integrations.telegram_webhook_secret" type="password" dir="ltr" autocomplete="off" :label="t('system.integrations.new_secret')" :error="integrations.errors.telegram_webhook_secret" />
                            <label class="flex min-h-11 cursor-pointer items-center gap-2 text-sm text-ink-muted">
                                <input v-model="integrations.clear_telegram_webhook_secret" type="checkbox" class="h-4 w-4 rounded border-line text-brand focus:ring-accent">
                                {{ t('system.integrations.clear_secret') }}
                            </label>
                        </div>
                    </section>

                    <!-- ai -->
                    <section class="space-y-4">
                        <div>
                            <h3 class="text-sm font-semibold text-ink">{{ t('system.ai.title') }}</h3>
                            <p class="mt-0.5 text-xs text-ink-muted">{{ t('system.ai.description') }}</p>
                        </div>
                        <AppSelect v-model="integrations.ai_provider" :label="t('system.ai.provider')" :options="aiProviderOptions" required :error="integrations.errors.ai_provider" />
                        <AppInput v-model="integrations.ai_base_url" type="url" dir="ltr" :label="t('system.ai.base_url')" :error="integrations.errors.ai_base_url" />
                        <div class="grid gap-4 sm:grid-cols-2">
                            <AppInput v-model="integrations.ai_model" dir="ltr" :label="t('system.ai.model')" :error="integrations.errors.ai_model" />
                            <AppInput v-model="integrations.ai_fallback_model" dir="ltr" :label="t('system.ai.fallback_model')" :error="integrations.errors.ai_fallback_model" />
                            <AppInput v-model="integrations.ai_timeout" type="number" dir="ltr" :label="t('system.ai.timeout')" required :error="integrations.errors.ai_timeout" />
                            <AppInput v-model="integrations.ai_monthly_cost_limit_usd" type="number" dir="ltr" :label="t('system.ai.monthly_limit')" required :error="integrations.errors.ai_monthly_cost_limit_usd" />
                        </div>
                        <div class="space-y-2">
                            <p class="text-xs text-ink-muted">
                                {{ t('system.ai.api_key') }}:
                                <span :class="props.integrations!.ai.api_key_configured ? 'text-positive' : 'text-ink-faint'">
                                    {{ props.integrations!.ai.api_key_configured
                                        ? t('system.integrations.configured')
                                        : t('system.integrations.not_configured') }}
                                </span>
                            </p>
                            <AppInput v-model="integrations.ai_api_key" type="password" dir="ltr" autocomplete="off" :label="t('system.integrations.new_secret')" :error="integrations.errors.ai_api_key" />
                            <label class="flex min-h-11 cursor-pointer items-center gap-2 text-sm text-ink-muted">
                                <input v-model="integrations.clear_ai_api_key" type="checkbox" class="h-4 w-4 rounded border-line text-brand focus:ring-accent">
                                {{ t('system.integrations.clear_secret') }}
                            </label>
                        </div>
                    </section>

                    <AppButton type="submit" :loading="integrations.processing" :disabled="integrationsDisabled">
                        {{ t('system.integrations.save') }}
                    </AppButton>
                </form>
            </AppCard>
        </div>
    </AdminLayout>
</template>
