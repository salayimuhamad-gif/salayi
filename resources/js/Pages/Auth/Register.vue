<script setup lang="ts">
import { onBeforeUnmount, onMounted, ref } from 'vue';
import { Head, router, useForm } from '@inertiajs/vue3';
import AuthLayout from '@/Layouts/AuthLayout.vue';
import AppAlert from '@/Components/ui/AppAlert.vue';
import AppButton from '@/Components/ui/AppButton.vue';
import AppInput from '@/Components/ui/AppInput.vue';
import { t } from '@/lib/i18n';

/*
 * Registration by Telegram Start (spec §3).
 *
 * One page, two states, because a refresh must RESUME rather than restart:
 * the server sends `pending` when this session already holds a live
 * registration intent, and the page shows the waiting screen for it instead
 * of a fresh form. The person who submitted, switched to Telegram, and came
 * back by hand lands exactly where they left off.
 *
 * While pending, the page polls its own session — the same deliberately dumb
 * poll the Telegram login uses. The answer is only ever about the session
 * asking, and a completed answer arrives with a redirect target because the
 * server has already rotated the session and signed the person in.
 */
const props = defineProps<{
    bot_configured: boolean;
    pending: { deep_link: string | null; expires_in_seconds: number; name: string | null } | null;
    locales: string[];
    password_min_length: number;
}>();

/*
 * Screen 1 of three, and deliberately short: name, phone, password. Everything
 * else — city, area, email, photo, and the rest — is asked once the account
 * exists, on the profile-completion screen. A registration form that collects
 * a whole profile is a registration form people abandon.
 *
 * The password is what makes Telegram a one-time step. Without one, the only
 * way back into the account is another Start, which is the behaviour this
 * change exists to remove.
 */
const form = useForm({
    name: '',
    phone: '',
    password: '',
    password_confirmation: '',
    locale: (document.documentElement.lang || 'ckb') as string,
    accept_terms: false,
    consent_contact: false,
});

/*
 * H5: a NAMED state for every ending. The server's poll now says which of
 * pending/completed/expired/conflict/cancelled this registration is in, so
 * the page never leaves a person staring at a spinner that can no longer
 * succeed — and a conflict comes with a recovery door (the returning
 * Telegram sign-in for an account that already exists), never a merge.
 */
type PendingState = 'pending' | 'expired' | 'conflict' | 'cancelled';

const state = ref<PendingState>('pending');
const recoveryUrl = ref<string | null>(null);
let timer: ReturnType<typeof setInterval> | undefined;
let expiry: ReturnType<typeof setTimeout> | undefined;


async function poll(): Promise<void> {
    try {
        const response = await fetch('/register/poll', { headers: { Accept: 'application/json' } });

        if (!response.ok) return;

        const body = (await response.json()) as {
            state?: string;
            completed?: boolean;
            redirect?: string;
            recovery?: string;
        };

        if (body.completed === true) {
            stopClocks();
            // Full visit, not a client-side push: the session was rotated on
            // the server, so everything must be re-fetched under the new one.
            router.visit(body.redirect ?? '/');
            return;
        }

        if (body.state === 'conflict' || body.state === 'cancelled' || body.state === 'expired') {
            stopClocks();
            state.value = body.state;
            recoveryUrl.value = body.recovery ?? null;
        }
    } catch {
        // Transient network trouble; the next tick will ask again.
    }
}

function xsrfToken(): string {
    const match = /(?:^|;\s*)XSRF-TOKEN=([^;]+)/.exec(document.cookie);

    return match ? decodeURIComponent(match[1]) : '';
}

function cancelPending(): void {
    stopClocks();

    // JSON endpoint, JSON caller — see TelegramLink.cancel for the story.
    void fetch('/register/cancel', {
        method: 'POST',
        credentials: 'same-origin',
        headers: {
            'X-Requested-With': 'XMLHttpRequest',
            'X-XSRF-TOKEN': xsrfToken(),
            Accept: 'application/json',
        },
    }).finally(() => {
        state.value = 'cancelled';
    });
}

function restart(): void {
    /*
     * Cancel first, THEN revisit. An expired intent sits in a five-minute
     * grace window so a reload can still say "expired"; restarting inside
     * that window must not re-render the corpse. Cancelling marks it
     * terminal, and the fresh visit renders a fresh form.
     */
    void fetch('/register/cancel', {
        method: 'POST',
        credentials: 'same-origin',
        headers: {
            'X-Requested-With': 'XMLHttpRequest',
            'X-XSRF-TOKEN': xsrfToken(),
            Accept: 'application/json',
        },
    }).finally(() => {
        router.visit(window.location.pathname, { replace: true });
    });
}

function stopClocks(): void {
    clearInterval(timer);
    clearTimeout(expiry);
}

onMounted(() => {
    if (props.pending !== null) {
        timer = setInterval(() => void poll(), 2500);

        expiry = setTimeout(() => {
            // One last ask before declaring expiry locally — the server may
            // know a better ending (completed, conflict) than the clock does.
            void poll().finally(() => {
                if (state.value === 'pending') {
                    clearInterval(timer);
                    state.value = 'expired';
                }
            });
        }, Math.max(1, props.pending.expires_in_seconds) * 1000);
    }
});

onBeforeUnmount(stopClocks);

const localeNames: Record<string, string> = {
    ckb: 'کوردیی ناوەندی',
    ar: 'العربية',
    en: 'English',
};
</script>

<template>
    <Head :title="t('identity.register.title')" />

    <AuthLayout :title="t('identity.register.title')" :subtitle="t('identity.register.subtitle')">
        <!-- ============================== pending ============================== -->
        <div v-if="pending" class="space-y-5">
            <ol class="space-y-3 text-sm text-ink-muted">
                <li class="flex items-start gap-3">
                    <span class="mt-0.5 flex h-6 w-6 shrink-0 items-center justify-center rounded-full bg-brand-soft text-xs font-semibold text-brand">1</span>
                    {{ t('identity.register.pending_open') }}
                </li>
                <li class="flex items-start gap-3">
                    <span class="mt-0.5 flex h-6 w-6 shrink-0 items-center justify-center rounded-full bg-brand-soft text-xs font-semibold text-brand">2</span>
                    {{ t('identity.register.pending_press') }}
                </li>
                <li class="flex items-start gap-3">
                    <span class="mt-0.5 flex h-6 w-6 shrink-0 items-center justify-center rounded-full bg-brand-soft text-xs font-semibold text-brand">3</span>
                    {{ t('identity.register.pending_return') }}
                </li>
            </ol>

            <!-- The honest-claim line the spec requires on this screen: Start
                 confirms the Telegram account, not the typed number. -->
            <AppAlert variant="info">{{ t('identity.register.pending_note') }}</AppAlert>

            <a
                v-if="pending.deep_link && state === 'pending'"
                :href="pending.deep_link"
                target="_blank"
                rel="noopener"
                class="flex min-h-11 w-full items-center justify-center rounded-card bg-brand px-4 text-sm
                       font-medium text-white transition-colors hover:opacity-90
                       focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-accent"
            >
                {{ t('identity.register.pending_open') }}
            </a>

            <p v-if="state === 'pending'" class="flex items-center justify-center gap-2 text-sm text-ink-muted" role="status">
                <span class="inline-block h-2 w-2 animate-pulse rounded-full bg-accent" aria-hidden="true" />
                {{ t('identity.register.pending_waiting') }}
            </p>

            <button
                v-if="state === 'pending'"
                type="button"
                class="mx-auto block text-sm text-ink-muted underline hover:text-ink"
                data-testid="cancel-registration"
                @click="cancelPending"
            >
                {{ t('identity.register.pending_cancel') }}
            </button>

            <template v-if="state === 'expired'">
                <AppAlert variant="warning">{{ t('identity.register.pending_expired') }}</AppAlert>
                <AppButton class="w-full" data-testid="restart-registration" @click="restart">
                    {{ t('identity.register.pending_restart') }}
                </AppButton>
            </template>

            <template v-if="state === 'conflict'">
                <AppAlert variant="danger">{{ t('identity.register.pending_conflict') }}</AppAlert>
                <p class="text-sm text-ink-muted">{{ t('identity.register.pending_conflict_help') }}</p>
                <a
                    v-if="recoveryUrl"
                    :href="recoveryUrl"
                    data-testid="conflict-recovery"
                    class="flex min-h-11 w-full items-center justify-center rounded-card border border-brand px-4
                           text-sm font-medium text-brand transition-colors hover:bg-brand hover:text-white"
                >
                    {{ t('identity.register.pending_conflict_signin') }}
                </a>
                <AppButton class="w-full" variant="secondary" @click="restart">
                    {{ t('identity.register.pending_restart') }}
                </AppButton>
            </template>

            <template v-if="state === 'cancelled'">
                <AppAlert variant="info">{{ t('identity.register.pending_cancelled') }}</AppAlert>
                <AppButton class="w-full" data-testid="restart-registration" @click="restart">
                    {{ t('identity.register.pending_restart') }}
                </AppButton>
            </template>
        </div>

        <!-- =============================== form ================================ -->
        <form v-else class="space-y-5" @submit.prevent="form.post('/register')">
            <AppAlert v-if="!bot_configured" variant="warning">
                {{ t('identity.telegram.unavailable') }}
            </AppAlert>

            <AppInput
                v-model="form.name"
                :label="t('identity.register.name')"
                autocomplete="name"
                required
                :error="form.errors.name"
            />

            <AppInput
                v-model="form.phone"
                type="tel"
                :label="t('identity.register.phone')"
                autocomplete="tel"
                dir="ltr"
                required
                :hint="t('identity.register.phone_hint')"
                :error="form.errors.phone"
            />

            <AppInput
                v-model="form.password"
                type="password"
                :label="t('identity.auth.password')"
                autocomplete="new-password"
                required
                :hint="t('identity.register.password_hint', { count: password_min_length })"
                :error="form.errors.password"
            />

            <AppInput
                v-model="form.password_confirmation"
                type="password"
                :label="t('identity.auth.confirm_password')"
                autocomplete="new-password"
                required
                :error="form.errors.password_confirmation"
            />

            <div>
                <label for="register-locale" class="mb-1.5 block text-sm font-medium text-ink">
                    {{ t('identity.register.locale') }}
                </label>
                <select
                    id="register-locale"
                    v-model="form.locale"
                    class="block min-h-11 w-full rounded-card border border-line bg-surface px-3 text-sm text-ink
                           focus:border-accent focus:outline-none focus:ring-2 focus:ring-accent/40"
                >
                    <option v-for="code in locales" :key="code" :value="code">{{ localeNames[code] ?? code }}</option>
                </select>
            </div>

            <label class="flex min-h-11 cursor-pointer items-start gap-3 text-sm text-ink-muted">
                <input
                    v-model="form.accept_terms"
                    type="checkbox"
                    required
                    class="mt-1 h-4 w-4 rounded border-line text-brand focus:ring-accent"
                >
                <span>{{ t('identity.register.accept_terms') }}</span>
            </label>
            <p v-if="form.errors.accept_terms" class="text-sm text-negative">{{ form.errors.accept_terms }}</p>

            <!-- A separate decision, visually and legally: contact permission
                 is optional and unchecked by default. -->
            <label class="flex min-h-11 cursor-pointer items-start gap-3 text-sm text-ink-muted">
                <input
                    v-model="form.consent_contact"
                    type="checkbox"
                    class="mt-1 h-4 w-4 rounded border-line text-brand focus:ring-accent"
                >
                <span>{{ t('identity.register.consent_contact') }}</span>
            </label>

            <AppButton type="submit" block class="min-h-11" :loading="form.processing" :disabled="!bot_configured">
                {{ t('identity.register.continue_telegram') }}
            </AppButton>

            <!--
                The refusal, with somewhere to go.

                The message itself stays deliberately vague about WHY — saying
                "this number is already registered" to an anonymous visitor
                would let anyone test numbers against the database. What was
                actually broken is that it dead-ended: a person who genuinely
                owns the number had no next step at all.

                These two links are that next step. Signing in reaches an
                account whether or not its verification was ever finished — an
                unverified one lands straight back on the verification page with
                its permanent link waiting. Neither link claims an account
                exists; they are what any visitor is offered.
            -->
            <div v-if="form.errors.phone" class="space-y-2 rounded-card border border-line p-4" data-testid="register-recovery">
                <p class="text-sm font-medium text-ink">{{ t('identity.register.conflict_next_steps') }}</p>
                <a
                    href="/login"
                    data-testid="register-recovery-signin"
                    class="flex min-h-11 w-full items-center justify-center rounded-card border border-brand px-4
                           text-sm font-medium text-brand transition-colors hover:bg-brand hover:text-white
                           focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-accent"
                >
                    {{ t('identity.register.conflict_continue') }}
                </a>
            </div>

            <p class="text-center text-sm text-ink-muted">
                {{ t('identity.register.have_account') }}
                <a
                    href="/login"
                    class="inline-flex min-h-11 items-center font-medium text-brand underline-offset-2 hover:underline
                           focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-accent"
                >
                    {{ t('identity.register.sign_in') }}
                </a>
            </p>
        </form>
    </AuthLayout>
</template>
