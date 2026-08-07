<script setup lang="ts">
import { computed, reactive, ref, watch } from 'vue';
import { Head, router, useForm } from '@inertiajs/vue3';
import { t } from '@/lib/i18n';

/*
 * Guided installer (spec 33.1, repair prompt §2.1–§2.3, File two §18).
 *
 * The Step-1 shell this replaces rendered navigation, a progress bar and the
 * read-only requirement reports, and nothing else: there were no fields on any
 * screen, and an `isDeferred` list greyed out every mutating step because the
 * server answered 501. Both of those are gone — the server now implements the
 * whole sequence, so the wizard has to actually collect the answers.
 *
 * Design constraints that shaped this file:
 *
 *   - Every label goes through t(). An installer is the first thing a Kurdish
 *     operator sees, and hard-coded English here would make the Sorani-first
 *     promise false at the very first screen (repair prompt §4).
 *
 *   - Server validation errors are rendered per field. The installer is the
 *     one interface where the operator cannot read a log, so "which box is
 *     wrong" has to be visible next to the box.
 *
 *   - Secrets use type="password" and autocomplete="new-password" so a browser
 *     does not offer to remember a database password on a shared machine.
 *
 *   - Connection tests are explicit buttons, never automatic on blur: each one
 *     opens a socket to a third party, and a test that fires while somebody is
 *     still typing a token produces failures that mean nothing.
 */
interface Check {
    key: string;
    label: string;
    required: boolean;
    passed: boolean;
    actual: string;
    expected: string;
}

const props = defineProps<{
    step: string;
    mode: 'install' | 'upgrade';
    steps: string[];
    completed: string[];
    progress: number;
    answers: Record<string, AnswerValue>;
    payload: { passed?: boolean; checks?: Check[]; advisory?: { checks: Check[] } };
    locales?: Array<{ code: string; name: string }>;
    errors?: Record<string, string>;
}>();

const stepNumber = computed(() => props.steps.indexOf(props.step) + 1);
const checks = computed<Check[]>(() => props.payload.checks ?? []);
const advisory = computed<Check[]>(() => props.payload.advisory?.checks ?? []);

const isReadOnlyReport = computed(() =>
    ['requirements', 'extensions', 'permissions'].includes(props.step),
);

/*
 * Steps executed by InstallRunner rather than submitted as a form. These are
 * NOT deferred — that word described the old 501 behaviour and is deliberately
 * not reused. They simply have no fields: the operator presses Run and the
 * server does the work.
 */
const isServerAction = computed(() =>
    ['migrate', 'seed', 'storage_link', 'assets', 'cache', 'health_check', 'complete', 'lock'].includes(
        props.step,
    ),
);

const isStatic = computed(() => ['welcome', 'license'].includes(props.step));

/** Field definitions per step, mirroring StepValidator::rules(). */
type FieldType = 'text' | 'number' | 'password' | 'email' | 'url' | 'select' | 'checkbox' | 'multiselect';

/*
 * Inertia's useForm constrains its generic to concrete serialisable values,
 * so the answer bag is typed to exactly what the fields produce rather than
 * `unknown`.
 */
type AnswerValue = string | number | boolean | string[];
type Answers = Record<string, AnswerValue>;

interface Field {
    name: string;
    type: FieldType;
    options?: Array<{ value: string; label: string }>;
    optional?: boolean;
    showIf?: (values: Answers) => boolean;
}

const localeOptions = computed(() =>
    (props.locales ?? [
        { code: 'ckb', name: 'کوردی' },
        { code: 'ar', name: 'العربية' },
        { code: 'en', name: 'English' },
    ]).map((l) => ({ value: l.code, label: l.name })),
);

const FIELDS: Record<string, Field[]> = {
    database: [
        { name: 'db_host', type: 'text' },
        { name: 'db_port', type: 'number' },
        { name: 'db_database', type: 'text' },
        { name: 'db_username', type: 'text' },
        { name: 'db_password', type: 'password', optional: true },
    ],
    app_url: [
        { name: 'app_url', type: 'url' },
        { name: 'app_name', type: 'text' },
        { name: 'timezone', type: 'text' },
    ],
    mail: [
        { name: 'mail_host', type: 'text' },
        { name: 'mail_port', type: 'number' },
        { name: 'mail_username', type: 'text', optional: true },
        { name: 'mail_password', type: 'password', optional: true },
        {
            name: 'mail_scheme',
            type: 'select',
            options: [
                { value: 'tls', label: 'TLS' },
                { value: 'ssl', label: 'SSL' },
                { value: 'smtp', label: 'SMTP' },
            ],
        },
        { name: 'mail_from_address', type: 'email' },
        { name: 'mail_from_name', type: 'text' },
    ],
    queue: [
        {
            name: 'queue_connection',
            type: 'select',
            options: [
                { value: 'database', label: 'database' },
                { value: 'sync', label: 'sync' },
            ],
        },
        { name: 'scheduler_confirmed', type: 'checkbox' },
    ],
    map_provider: [
        {
            name: 'map_provider',
            type: 'select',
            options: [
                { value: 'maplibre', label: 'MapLibre' },
                { value: 'google', label: 'Google Maps' },
            ],
        },
        { name: 'maplibre_style_url', type: 'url', optional: true },
        {
            name: 'google_maps_api_key',
            type: 'password',
            // Required only for Google: MapLibre must stay usable with no key,
            // which is why it is the default.
            showIf: (v) => v.map_provider === 'google',
        },
    ],
    telegram: [
        { name: 'telegram_enabled', type: 'checkbox' },
        { name: 'telegram_bot_token', type: 'password', showIf: (v) => v.telegram_enabled === true },
        { name: 'telegram_bot_username', type: 'text', optional: true, showIf: (v) => v.telegram_enabled === true },
        { name: 'telegram_webhook_secret', type: 'password', optional: true, showIf: (v) => v.telegram_enabled === true },
    ],
    ai_provider: [
        {
            name: 'ai_provider',
            type: 'select',
            options: [
                { value: 'null', label: '—' },
                { value: 'openai_compatible', label: 'OpenAI-compatible' },
            ],
        },
        { name: 'ai_base_url', type: 'url', showIf: (v) => v.ai_provider === 'openai_compatible' },
        { name: 'ai_api_key', type: 'password', showIf: (v) => v.ai_provider === 'openai_compatible' },
        { name: 'ai_model', type: 'text', showIf: (v) => v.ai_provider === 'openai_compatible' },
        { name: 'ai_fallback_model', type: 'text', optional: true, showIf: (v) => v.ai_provider === 'openai_compatible' },
    ],
    default_language: [{ name: 'default_locale', type: 'select', options: [] }],
    enabled_languages: [{ name: 'enabled_locales', type: 'multiselect', options: [] }],
    branding: [
        { name: 'site_name', type: 'text' },
        { name: 'support_email', type: 'email', optional: true },
        { name: 'support_phone', type: 'text', optional: true },
    ],
    super_admin: [
        { name: 'admin_name', type: 'text' },
        { name: 'admin_email', type: 'email' },
        { name: 'admin_password', type: 'password' },
        { name: 'admin_password_confirmation', type: 'password' },
        { name: 'admin_locale', type: 'select', options: [] },
    ],
};

const DEFAULTS: Record<string, Answers> = {
    database: { db_host: '127.0.0.1', db_port: 3306, db_database: '', db_username: '', db_password: '' },
    app_url: { app_url: '', app_name: 'Mulkihawler', timezone: 'Asia/Baghdad' },
    mail: { mail_host: '', mail_port: 587, mail_username: '', mail_password: '', mail_scheme: 'tls', mail_from_address: '', mail_from_name: 'Mulkihawler' },
    queue: { queue_connection: 'database', scheduler_confirmed: false },
    map_provider: { map_provider: 'maplibre', maplibre_style_url: '', google_maps_api_key: '' },
    telegram: { telegram_enabled: false, telegram_bot_token: '', telegram_bot_username: '', telegram_webhook_secret: '' },
    ai_provider: { ai_provider: 'null', ai_base_url: '', ai_api_key: '', ai_model: '', ai_fallback_model: '' },
    default_language: { default_locale: 'ckb' },
    enabled_languages: { enabled_locales: ['ckb'] },
    branding: { site_name: 'Mulkihawler', support_email: '', support_phone: '' },
    super_admin: { admin_name: '', admin_email: '', admin_password: '', admin_password_confirmation: '', admin_locale: 'ckb' },
};

const fields = computed<Field[]>(() =>
    (FIELDS[props.step] ?? []).map((f) =>
        f.type === 'select' && (f.options?.length ?? 0) === 0
            ? { ...f, options: localeOptions.value }
            : f.type === 'multiselect'
                ? { ...f, options: localeOptions.value }
                : f,
    ),
);

// Saved answers win over defaults, so a resumed install shows what was typed.
const form = useForm<Answers>({
    ...(DEFAULTS[props.step] ?? {}),
    ...((props.answers ?? {}) as Answers),
});

const visibleFields = computed(() => fields.value.filter((f) => !f.showIf || f.showIf(form.data())));

// ---- connection tests -------------------------------------------------

const testState = reactive<{ status: 'idle' | 'running' | 'ok' | 'failed'; reason: string }>({
    status: 'idle',
    reason: '',
});

const testEndpoint = computed<string | null>(() => {
    const map: Record<string, string> = {
        database: '/install/test/database',
        mail: '/install/test/mail',
        telegram: '/install/test/telegram',
        ai_provider: '/install/test/ai',
    };

    return map[props.step] ?? null;
});

// Re-editing a field invalidates a previous result: a green tick next to
// changed credentials is a lie.
watch(() => form.data(), () => {
    if (testState.status !== 'idle') {
        testState.status = 'idle';
        testState.reason = '';
    }
}, { deep: true });

async function runTest(): Promise<void> {
    const endpoint = testEndpoint.value;

    if (endpoint === null) return;

    testState.status = 'running';
    testState.reason = '';

    try {
        const response = await fetch(endpoint, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                Accept: 'application/json',
                'X-CSRF-TOKEN': csrfToken(),
            },
            body: JSON.stringify(form.data()),
        });

        const body = (await response.json()) as { ok?: boolean; reason?: string };

        testState.status = body.ok === true ? 'ok' : 'failed';
        testState.reason = body.reason ?? '';
    } catch {
        testState.status = 'failed';
        testState.reason = t('install.status.failed');
    }
}

function csrfToken(): string {
    if (typeof document === 'undefined') return '';

    return document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')?.content ?? '';
}

// ---- navigation -------------------------------------------------------

const submitting = ref(false);

function submit(): void {
    submitting.value = true;
    form.post(`/install/step/${props.step}`, {
        preserveScroll: true,
        onFinish: () => { submitting.value = false; },
    });
}

function back(): void {
    const index = props.steps.indexOf(props.step);

    if (index > 0) {
        router.get(`/install/step/${props.steps[index - 1]}`);
    }
}

const continueLabel = computed(() => {
    if (props.step === 'lock') return t('install.actions.lock');
    if (props.step === 'complete') return t('install.actions.finish');
    if (isServerAction.value) return t('install.actions.run');

    return t('install.actions.continue');
});

const fieldError = (name: string): string | undefined => props.errors?.[name];
</script>

<template>
    <Head :title="`${t('install.title')} — ${t(`install.steps.${step}`)}`" />

    <div class="mx-auto max-w-3xl px-4 py-10">
        <header class="mb-8">
            <p class="mh-label">
                {{ mode === 'upgrade' ? t('install.subtitle') : t('install.title') }} ·
                <span class="numeral">{{ stepNumber }}/{{ steps.length }}</span>
            </p>
            <h1 class="mt-1 font-display text-2xl font-bold text-ink">
                {{ t(`install.steps.${step}`) }}
            </h1>

            <div
                class="mt-4 h-1.5 overflow-hidden rounded-full bg-surface-sunken"
                role="progressbar"
                :aria-valuenow="progress"
                aria-valuemin="0"
                aria-valuemax="100"
            >
                <div
                    class="h-full rounded-full bg-accent transition-[width] duration-500 ease-calm"
                    :style="{ width: `${progress}%` }"
                />
            </div>
        </header>

        <section class="mh-panel">
            <!-- Read-only server reports -->
            <div v-if="isReadOnlyReport" class="space-y-1">
                <div
                    v-for="check in checks"
                    :key="check.key"
                    class="flex items-baseline justify-between gap-4 border-b border-line py-2 text-sm last:border-0"
                >
                    <span class="text-ink">{{ check.label }}</span>
                    <span
                        class="numeral text-xs"
                        :class="check.passed ? 'text-positive' : check.required ? 'text-negative' : 'text-caution'"
                    >
                        {{ check.actual }}
                        <span v-if="!check.passed" class="text-ink-faint">· {{ check.expected }}</span>
                    </span>
                </div>

                <div v-if="advisory.length" class="pt-4">
                    <p class="mh-label">{{ t('install.status.recommended') }}</p>
                    <div
                        v-for="check in advisory"
                        :key="check.key"
                        class="flex items-baseline justify-between gap-4 py-1.5 text-sm"
                    >
                        <span class="text-ink-muted">{{ check.label }}</span>
                        <span class="numeral text-xs" :class="check.passed ? 'text-positive' : 'text-caution'">
                            {{ check.actual }}
                        </span>
                    </div>
                </div>
            </div>

            <!-- Server-executed steps: no fields, just an explicit action -->
            <p v-else-if="isServerAction" class="text-sm leading-relaxed text-ink-muted">
                {{ t(`install.steps.${step}`) }}
            </p>

            <p v-else-if="isStatic" class="text-sm leading-relaxed text-ink-muted">
                {{ t('install.subtitle') }}
            </p>

            <!-- Collecting steps -->
            <div v-else class="space-y-4">
                <div v-for="field in visibleFields" :key="field.name">
                    <label :for="field.name" class="mh-label mb-1 block">
                        {{ t(`install.fields.${field.name}`) }}
                        <span v-if="field.optional" class="text-ink-faint">
                            · {{ t('install.actions.optional') }}
                        </span>
                    </label>

                    <select
                        v-if="field.type === 'select'"
                        :id="field.name"
                        v-model="form[field.name] as string"
                        class="w-full rounded-card border border-line bg-surface-raised px-3 py-2 text-sm text-ink
                               focus:border-brand focus:outline-none focus:ring-2 focus:ring-accent"
                    >
                        <option v-for="option in field.options" :key="option.value" :value="option.value">
                            {{ option.label }}
                        </option>
                    </select>

                    <div v-else-if="field.type === 'multiselect'" class="flex flex-wrap gap-3">
                        <label
                            v-for="option in field.options"
                            :key="option.value"
                            class="flex items-center gap-2 rounded-card border border-line px-3 py-2 text-sm"
                        >
                            <input
                                v-model="form[field.name] as string[]"
                                type="checkbox"
                                :value="option.value"
                                class="h-4 w-4 rounded border-line text-brand focus:ring-2 focus:ring-accent"
                            >
                            <span>{{ option.label }}</span>
                        </label>
                    </div>

                    <label v-else-if="field.type === 'checkbox'" class="flex items-center gap-2 text-sm text-ink">
                        <input
                            :id="field.name"
                            v-model="form[field.name] as boolean"
                            type="checkbox"
                            class="h-4 w-4 rounded border-line text-brand focus:ring-2 focus:ring-accent"
                        >
                    </label>

                    <input
                        v-else
                        :id="field.name"
                        v-model="form[field.name] as string"
                        :type="field.type"
                        :dir="field.type === 'password' || field.type === 'url' || field.type === 'email' ? 'ltr' : undefined"
                        :autocomplete="field.type === 'password' ? 'new-password' : 'off'"
                        class="w-full rounded-card border border-line bg-surface-raised px-3 py-2 text-sm text-ink
                               focus:border-brand focus:outline-none focus:ring-2 focus:ring-accent"
                    >

                    <p v-if="fieldError(field.name)" class="mt-1 text-xs text-negative">
                        {{ fieldError(field.name) }}
                    </p>
                </div>

                <!-- Explicit connection test, never automatic -->
                <div v-if="testEndpoint" class="flex flex-wrap items-center gap-3 pt-1">
                    <button
                        type="button"
                        class="rounded-card border border-line px-3 py-2 text-sm text-ink transition-colors
                               hover:bg-surface-sunken focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-accent
                               disabled:opacity-40"
                        :disabled="testState.status === 'running'"
                        @click="runTest"
                    >
                        {{ testState.status === 'running' ? t('install.actions.testing') : t('install.actions.test') }}
                    </button>

                    <span
                        v-if="testState.status === 'ok'"
                        class="text-sm text-positive"
                        role="status"
                    >{{ t('install.status.passed') }}</span>

                    <span
                        v-else-if="testState.status === 'failed'"
                        class="text-sm text-negative"
                        role="alert"
                    >{{ testState.reason || t('install.status.failed') }}</span>
                </div>
            </div>

            <div class="mt-7 flex items-center justify-between gap-3">
                <button
                    v-if="stepNumber > 1"
                    type="button"
                    class="rounded-card px-3 py-2 text-sm text-ink-muted transition-colors hover:text-ink
                           focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-accent"
                    @click="back"
                >
                    {{ t('install.actions.back') }}
                </button>
                <span v-else />

                <button
                    type="button"
                    class="rounded-card bg-brand px-4 py-2 text-sm font-medium text-white transition-opacity
                           focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-accent
                           disabled:opacity-40"
                    :disabled="submitting || form.processing"
                    @click="submit"
                >
                    {{ continueLabel }}
                </button>
            </div>
        </section>
    </div>
</template>
