<script setup lang="ts">
import { computed, ref } from 'vue';
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
            provider: string; fallback_provider: string;
            timeout: number; monthly_cost_limit_usd: number;
            prompt_cost_per_mtok: number; completion_cost_per_mtok: number;
            openai: { model: string; key_configured: boolean };
            gemini: { model: string; key_configured: boolean };
            openai_compatible: { base_url: string; model: string; key_configured: boolean };
            health: {
                available: boolean;
                providers: { key: string; model: string; configured: boolean; breaker_open: boolean; is_fallback: boolean }[];
                monthly_spent_usd: string; monthly_limit_usd: string;
                budget_exhausted: boolean; cost_rates_configured: boolean;
                primary: string | null; fallback: string | null;
                last_success: { provider?: string; model?: string; latency_ms?: number; at?: string } | null;
                last_failure: { provider?: string; category?: string; at?: string } | null;
                last_test: { status?: string; provider?: string; model?: string | null; latency_ms?: number | null; at?: string } | null;
            };
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
    ai_fallback_provider: props.integrations?.ai.fallback_provider ?? '',
    openai_model: props.integrations?.ai.openai.model ?? '',
    openai_api_key: '',
    clear_openai_api_key: false,
    gemini_model: props.integrations?.ai.gemini.model ?? '',
    gemini_api_key: '',
    clear_gemini_api_key: false,
    openai_compatible_base_url: props.integrations?.ai.openai_compatible.base_url ?? '',
    openai_compatible_model: props.integrations?.ai.openai_compatible.model ?? '',
    openai_compatible_api_key: '',
    clear_openai_compatible_api_key: false,
    ai_timeout: props.integrations?.ai.timeout ?? 30,
    ai_monthly_cost_limit_usd: props.integrations?.ai.monthly_cost_limit_usd ?? 0,
    ai_prompt_cost_per_mtok: props.integrations?.ai.prompt_cost_per_mtok ?? 0,
    ai_completion_cost_per_mtok: props.integrations?.ai.completion_cost_per_mtok ?? 0,
});

/*
 * The safe Test Connection action (repair phase 10): server-side, audited,
 * rate-limited, and reported back only as a category token this application
 * minted — the secret and the provider body never travel.
 */
const testingProvider = ref<string | null>(null);
const testResult = ref<{ provider: string; status: string; model: string | null; latency_ms: number | null } | null>(null);

async function testConnection(provider: string): Promise<void> {
    if (testingProvider.value !== null) return;

    testingProvider.value = provider;
    testResult.value = null;

    try {
        const response = await fetch('/admin/settings/integrations/ai-test', {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                'Content-Type': 'application/json',
                Accept: 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
                'X-XSRF-TOKEN': decodeURIComponent(document.cookie.match(/XSRF-TOKEN=([^;]+)/)?.[1] ?? ''),
            },
            body: JSON.stringify({ provider }),
        });
        const body = await response.json();

        testResult.value = {
            provider,
            status: String(body.status ?? 'unknown'),
            model: body.model ?? null,
            latency_ms: body.latency_ms ?? null,
        };
    } catch {
        testResult.value = { provider, status: 'request_failed', model: null, latency_ms: null };
    } finally {
        testingProvider.value = null;
    }
}

function testStatusLabel(status: string): string {
    const key = `system.ai.test_status.${status}`;
    const label = t(key);

    return label === key ? status : label;
}

/** Which provider blocks are relevant to the current selection. */
const selectedAiProviders = computed<string[]>(() =>
    [integrations.ai_provider, integrations.ai_fallback_provider].filter(
        (name) => name !== '' && name !== 'null',
    ),
);

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
    { value: 'openai', label: 'OpenAI' },
    { value: 'gemini', label: 'Google Gemini' },
    { value: 'openai_compatible', label: 'OpenAI-compatible' },
];
const aiFallbackOptions = [
    { value: '', label: '—' },
    { value: 'openai', label: 'OpenAI' },
    { value: 'gemini', label: 'Google Gemini' },
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
                <form class="space-y-5 p-5" @submit.prevent="general.put('/admin/settings/general')">
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
                <form class="space-y-8 p-5" @submit.prevent="integrations.put('/admin/settings/integrations')">
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

                        <!-- Health: the diagnostics readout that makes "AI is
                             not working" answerable without SSH. Tokens and
                             counters only — never a secret, never a body. -->
                        <div v-if="props.integrations?.ai.health" class="rounded-card border border-line bg-surface-sunken p-3 text-xs text-ink-muted">
                            <p class="font-medium text-ink">
                                {{ t('system.ai.health.title') }}:
                                <span :class="props.integrations.ai.health.available ? 'text-positive' : 'text-caution'">
                                    {{ props.integrations.ai.health.available ? t('system.ai.health.available') : t('system.ai.health.unavailable') }}
                                </span>
                            </p>
                            <p class="mt-1">
                                {{ t('system.ai.health.active_chain') }}:
                                <span dir="ltr">{{ props.integrations.ai.health.primary ?? '—' }}</span>
                                <template v-if="props.integrations.ai.health.fallback">
                                    → <span dir="ltr">{{ props.integrations.ai.health.fallback }}</span>
                                </template>
                            </p>
                            <p class="mt-1">
                                {{ t('system.ai.health.monthly_spend') }}:
                                <span dir="ltr">{{ props.integrations.ai.health.monthly_spent_usd }} / {{ props.integrations.ai.health.monthly_limit_usd }} USD</span>
                                <span v-if="props.integrations.ai.health.budget_exhausted" class="text-negative"> — {{ t('system.ai.health.budget_exhausted') }}</span>
                            </p>
                            <p v-if="!props.integrations.ai.health.cost_rates_configured && props.integrations.ai.monthly_cost_limit_usd > 0" class="mt-1 text-caution">
                                {{ t('system.ai.health.rates_unset') }}
                            </p>
                            <p v-for="entry in props.integrations.ai.health.providers" :key="entry.key" class="mt-1">
                                <span dir="ltr">{{ entry.key }}</span> — {{ entry.configured ? t('system.integrations.configured') : t('system.integrations.not_configured') }}<span v-if="entry.breaker_open" class="text-negative">؛ {{ t('system.ai.health.breaker_open') }}</span>
                            </p>
                            <p v-if="props.integrations.ai.health.last_failure" class="mt-1">
                                {{ t('system.ai.health.last_failure') }}:
                                <span dir="ltr">{{ props.integrations.ai.health.last_failure.provider }} · {{ testStatusLabel(String(props.integrations.ai.health.last_failure.category ?? '')) }} · {{ props.integrations.ai.health.last_failure.at }}</span>
                            </p>
                            <p v-if="props.integrations.ai.health.last_test" class="mt-1">
                                {{ t('system.ai.health.last_test') }}:
                                <span dir="ltr">{{ props.integrations.ai.health.last_test.provider }} · {{ testStatusLabel(String(props.integrations.ai.health.last_test.status ?? '')) }} · {{ props.integrations.ai.health.last_test.at }}</span>
                            </p>
                        </div>

                        <div class="grid gap-4 sm:grid-cols-2">
                            <AppSelect v-model="integrations.ai_provider" :label="t('system.ai.provider')" :options="aiProviderOptions" required :error="integrations.errors.ai_provider" />
                            <AppSelect v-model="integrations.ai_fallback_provider" :label="t('system.ai.fallback_provider')" :options="aiFallbackOptions" :error="integrations.errors.ai_fallback_provider" />
                        </div>

                        <!-- openai -->
                        <div v-if="selectedAiProviders.includes('openai')" class="space-y-2 rounded-card border border-line p-3">
                            <p class="text-xs font-semibold text-ink" dir="ltr">OpenAI</p>
                            <AppInput v-model="integrations.openai_model" dir="ltr" :label="t('system.ai.model')" :error="integrations.errors.openai_model" />
                            <p class="text-xs text-ink-muted">
                                {{ t('system.ai.api_key') }}:
                                <span :class="props.integrations!.ai.openai.key_configured ? 'text-positive' : 'text-ink-faint'">
                                    {{ props.integrations!.ai.openai.key_configured ? t('system.integrations.configured') : t('system.integrations.not_configured') }}
                                </span>
                            </p>
                            <AppInput v-model="integrations.openai_api_key" type="password" dir="ltr" autocomplete="off" :label="t('system.integrations.new_secret')" :error="integrations.errors.openai_api_key" />
                            <label class="flex min-h-11 cursor-pointer items-center gap-2 text-sm text-ink-muted">
                                <input v-model="integrations.clear_openai_api_key" type="checkbox" class="h-4 w-4 rounded border-line text-brand focus:ring-accent">
                                {{ t('system.integrations.clear_secret') }}
                            </label>
                            <AppButton type="button" variant="secondary" :disabled="testingProvider !== null" @click="testConnection('openai')">
                                {{ t('system.ai.test_connection') }}
                            </AppButton>
                        </div>

                        <!-- gemini -->
                        <div v-if="selectedAiProviders.includes('gemini')" class="space-y-2 rounded-card border border-line p-3">
                            <p class="text-xs font-semibold text-ink" dir="ltr">Google Gemini</p>
                            <AppInput v-model="integrations.gemini_model" dir="ltr" :label="t('system.ai.model')" :error="integrations.errors.gemini_model" />
                            <p class="text-xs text-ink-muted">
                                {{ t('system.ai.api_key') }}:
                                <span :class="props.integrations!.ai.gemini.key_configured ? 'text-positive' : 'text-ink-faint'">
                                    {{ props.integrations!.ai.gemini.key_configured ? t('system.integrations.configured') : t('system.integrations.not_configured') }}
                                </span>
                            </p>
                            <AppInput v-model="integrations.gemini_api_key" type="password" dir="ltr" autocomplete="off" :label="t('system.integrations.new_secret')" :error="integrations.errors.gemini_api_key" />
                            <label class="flex min-h-11 cursor-pointer items-center gap-2 text-sm text-ink-muted">
                                <input v-model="integrations.clear_gemini_api_key" type="checkbox" class="h-4 w-4 rounded border-line text-brand focus:ring-accent">
                                {{ t('system.integrations.clear_secret') }}
                            </label>
                            <AppButton type="button" variant="secondary" :disabled="testingProvider !== null" @click="testConnection('gemini')">
                                {{ t('system.ai.test_connection') }}
                            </AppButton>
                        </div>

                        <!-- openai-compatible -->
                        <div v-if="selectedAiProviders.includes('openai_compatible')" class="space-y-2 rounded-card border border-line p-3">
                            <p class="text-xs font-semibold text-ink" dir="ltr">OpenAI-compatible</p>
                            <AppInput v-model="integrations.openai_compatible_base_url" type="url" dir="ltr" :label="t('system.ai.base_url')" :error="integrations.errors.openai_compatible_base_url" />
                            <AppInput v-model="integrations.openai_compatible_model" dir="ltr" :label="t('system.ai.model')" :error="integrations.errors.openai_compatible_model" />
                            <p class="text-xs text-ink-muted">
                                {{ t('system.ai.api_key') }}:
                                <span :class="props.integrations!.ai.openai_compatible.key_configured ? 'text-positive' : 'text-ink-faint'">
                                    {{ props.integrations!.ai.openai_compatible.key_configured ? t('system.integrations.configured') : t('system.integrations.not_configured') }}
                                </span>
                            </p>
                            <AppInput v-model="integrations.openai_compatible_api_key" type="password" dir="ltr" autocomplete="off" :label="t('system.integrations.new_secret')" :error="integrations.errors.openai_compatible_api_key" />
                            <label class="flex min-h-11 cursor-pointer items-center gap-2 text-sm text-ink-muted">
                                <input v-model="integrations.clear_openai_compatible_api_key" type="checkbox" class="h-4 w-4 rounded border-line text-brand focus:ring-accent">
                                {{ t('system.integrations.clear_secret') }}
                            </label>
                            <AppButton type="button" variant="secondary" :disabled="testingProvider !== null" @click="testConnection('openai_compatible')">
                                {{ t('system.ai.test_connection') }}
                            </AppButton>
                        </div>

                        <p v-if="testResult" class="text-xs" :class="testResult.status === 'connected' ? 'text-positive' : 'text-caution'" role="status">
                            <span dir="ltr">{{ testResult.provider }}</span> — {{ testStatusLabel(testResult.status) }}
                            <span v-if="testResult.latency_ms !== null" dir="ltr">({{ testResult.latency_ms }}ms)</span>
                        </p>

                        <div class="grid gap-4 sm:grid-cols-2">
                            <AppInput v-model="integrations.ai_timeout" type="number" dir="ltr" :label="t('system.ai.timeout')" required :error="integrations.errors.ai_timeout" />
                            <AppInput v-model="integrations.ai_monthly_cost_limit_usd" type="number" dir="ltr" :label="t('system.ai.monthly_limit')" required :error="integrations.errors.ai_monthly_cost_limit_usd" />
                            <AppInput v-model="integrations.ai_prompt_cost_per_mtok" type="number" dir="ltr" :label="t('system.ai.prompt_rate')" required :error="integrations.errors.ai_prompt_cost_per_mtok" />
                            <AppInput v-model="integrations.ai_completion_cost_per_mtok" type="number" dir="ltr" :label="t('system.ai.completion_rate')" required :error="integrations.errors.ai_completion_cost_per_mtok" />
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
