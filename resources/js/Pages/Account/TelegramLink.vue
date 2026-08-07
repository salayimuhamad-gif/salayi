<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';
import { Head, router, usePage } from '@inertiajs/vue3';
import AppCard from '@/Components/ui/AppCard.vue';
import AppAlert from '@/Components/ui/AppAlert.vue';
import AppButton from '@/Components/ui/AppButton.vue';
import { t } from '@/lib/i18n';
import { useLocale } from '@/Composables/useLocale';

/*
 * Linking Telegram to an EXISTING signed-in account (correction C1,
 * corrected again in v4 for BLOCKER 1 and §5).
 *
 * The person here already has an account — email/password, from before the
 * Telegram era — and the mandatory-link gate sent them to this page. One
 * button opens the bot with a Start token bound to THEIR account and THIS
 * browser session; the page then polls its own session until the link
 * lands, and shows a real state for every way the flow can end.
 *
 * v4 BLOCKER 1 — reloading this page no longer destroys the token. The
 * server resumes the live intent, so `deep_link` is the SAME link across a
 * refresh, a restored mobile tab, or a second tab. The consequence for
 * this component is that "Start again" can no longer be a page visit: a
 * visit now resumes. It POSTs to the restart route, which is the only
 * browser action that retires a live token.
 *
 * v4 §5 — a Start no longer finishes the job. The poll can return
 * `awaiting_confirmation` with a safe display identity, and the person has
 * to look at it and press confirm. The component echoes back the opaque
 * `candidate_handle` it was shown, never a Telegram id, so confirming
 * approves the identity ON SCREEN rather than whatever happens to be
 * parked at the moment the click lands.
 *
 * The poll is the same deliberately dumb one the sign-in pages use: it
 * asks "how is MY session's link going", never "how is intent N doing" —
 * a client that could name an intent could watch somebody else's.
 */
type Candidate = {
    name: string | null;
    username: string | null;
    handle: string;
    at: string | null;
};

const props = defineProps<{
    deep_link: string | null;
    expires_in_seconds: number;
    bot_configured: boolean;
    requires_confirmation: boolean;
    candidate: Candidate | null;
    candidate_handle: string | null;
}>();

const page = usePage();

/*
 * v7 account-first: registration lands here with a flashed confirmation, so
 * the first thing a brand-new account sees is that it EXISTS — not a bare
 * linking demand that reads like a rejection. Shared through `flash.status`,
 * which HandleInertiaRequests already exposes.
 */
const accountCreated = computed<string | null>(() => {
    const flash = page.props.flash as { status?: string | null } | undefined;

    return flash?.status ?? null;
});

const { localized } = useLocale();

type LinkState =
    | 'idle'
    | 'waiting'
    | 'awaiting_confirmation'
    | 'confirming'
    | 'completed'
    | 'expired'
    | 'conflict'
    | 'cancelled'
    | 'candidate_changed';

/*
 * A candidate may already be parked when the page RENDERS — the person
 * pressed Start, then closed and reopened the tab. Because the intent
 * survives a reload now, the confirmation has to survive one too.
 */
const state = ref<LinkState>(props.candidate !== null ? 'awaiting_confirmation' : 'idle');
const candidate = ref<Candidate | null>(props.candidate);
const candidateHandle = ref<string | null>(props.candidate_handle);
let timer: ReturnType<typeof setInterval> | undefined;

function candidateLabel(value: Candidate | null): string {
    if (value === null) {
        return t('identity.link.candidate_unknown');
    }

    if (value.username !== null && value.username !== '') {
        return `@${value.username}`;
    }

    return value.name !== null && value.name !== ''
        ? value.name
        : t('identity.link.candidate_unknown');
}

async function poll(): Promise<void> {
    try {
        const response = await fetch(localized('/account/telegram/link/poll'), {
            headers: { Accept: 'application/json' },
        });

        if (!response.ok) {
            return;
        }

        const body = (await response.json()) as {
            state: string;
            redirect?: string;
            candidate?: Candidate | null;
            candidate_handle?: string | null;
        };

        if (body.state === 'completed') {
            stopPolling();
            state.value = 'completed';
            // A moment to read the success line, then onward.
            setTimeout(() => {
                window.location.href = body.redirect ?? localized('/account/profile');
            }, 1200);
        } else if (body.state === 'awaiting_confirmation') {
            /*
             * Keep polling. A second Start from a different Telegram
             * account replaces the candidate, and the person must see the
             * one that is actually parked right now — not the one that was
             * parked when they walked away from the screen.
             */
            candidate.value = body.candidate ?? null;
            candidateHandle.value = body.candidate_handle ?? null;

            if (state.value !== 'confirming') {
                state.value = 'awaiting_confirmation';
            }
        } else if (body.state === 'expired' || body.state === 'conflict' || body.state === 'cancelled') {
            stopPolling();
            state.value = body.state;
        }
    } catch {
        // A transient network failure: the next tick asks again.
    }
}

function openBot(): void {
    if (props.deep_link === null) {
        return;
    }

    state.value = 'waiting';
    window.open(props.deep_link, '_blank', 'noopener');
}

function xsrfToken(): string {
    const match = /(?:^|;\s*)XSRF-TOKEN=([^;]+)/.exec(document.cookie);

    return match ? decodeURIComponent(match[1]) : '';
}

function jsonHeaders(): Record<string, string> {
    return {
        'Content-Type': 'application/json',
        'X-Requested-With': 'XMLHttpRequest',
        'X-XSRF-TOKEN': xsrfToken(),
        Accept: 'application/json',
    };
}

/*
 * v7 account-first: give up on this registration entirely.
 *
 * Distinct from `cancel`, which only retires the current Telegram token and
 * leaves the account in place. An account created by the form holds its phone
 * number under a unique index, so somebody who mistyped it — or who simply
 * cannot finish — needs a way to release it and start again rather than being
 * told the number is taken by an account they cannot reach. The server
 * refuses once the account is linked or owns anything.
 */
function abandonRegistration(): void {
    router.post(localized('/account/registration/abandon'), {}, { preserveScroll: false });
}

function cancel(): void {
    stopPolling();

    /*
     * The cancel endpoint answers JSON — the same contract the poll uses —
     * so it is called the same way the poll is. Routing it through Inertia
     * raised the "plain JSON response" modal; the browser matrix caught it.
     */
    void fetch(localized('/account/telegram/link/cancel'), {
        method: 'POST',
        credentials: 'same-origin',
        headers: jsonHeaders(),
    }).finally(() => {
        state.value = 'cancelled';
    });
}

/**
 * v4 §5: approve the identity currently on screen.
 *
 * The handle travels with the request. If a different Start overwrote the
 * candidate between render and click, the server refuses with 409 and the
 * person is shown the new one instead of having silently linked a stranger.
 */
async function confirmCandidate(): Promise<void> {
    if (candidateHandle.value === null) {
        return;
    }

    state.value = 'confirming';

    try {
        const response = await fetch(localized('/account/telegram/link/confirm'), {
            method: 'POST',
            credentials: 'same-origin',
            headers: jsonHeaders(),
            body: JSON.stringify({ candidate_handle: candidateHandle.value }),
        });

        const body = (await response.json()) as { state: string; redirect?: string };

        if (response.ok && body.state === 'completed') {
            stopPolling();
            state.value = 'completed';
            setTimeout(() => {
                window.location.href = body.redirect ?? localized('/account/profile');
            }, 1200);

            return;
        }

        if (body.state === 'candidate_changed') {
            state.value = 'candidate_changed';
            void poll();

            return;
        }

        stopPolling();
        state.value = body.state === 'conflict' ? 'conflict' : 'expired';
    } catch {
        // Network trouble mid-confirm: fall back to the poll loop, which
        // will re-report whatever the server actually thinks.
        state.value = 'awaiting_confirmation';
    }
}

/** v4 §5: "that is not me." Burns the token as well as the candidate. */
function rejectCandidate(): void {
    stopPolling();

    void fetch(localized('/account/telegram/link/reject'), {
        method: 'POST',
        credentials: 'same-origin',
        headers: jsonHeaders(),
    }).finally(() => {
        candidate.value = null;
        candidateHandle.value = null;
        state.value = 'cancelled';
    });
}

/**
 * v4 BLOCKER 1: an explicit POST, not a page visit.
 *
 * Visiting the page resumes the live intent now — that is the fix. Getting
 * a genuinely new token has to be something the person asks for, and this
 * is the only place that asks.
 */
function restart(): void {
    stopPolling();
    router.post(localized('/account/telegram/link/restart'), {}, { preserveScroll: false });
}

function stopPolling(): void {
    if (timer !== undefined) {
        clearInterval(timer);
        timer = undefined;
    }
}

onMounted(() => {
    timer = setInterval(() => void poll(), 2500);
    // The intent has a clock; when it runs out with nothing redeemed, say
    // so instead of polling a corpse.
    setTimeout(() => {
        if (state.value === 'idle' || state.value === 'waiting') {
            void poll();
        }
    }, Math.max(1, props.expires_in_seconds) * 1000);
});

onBeforeUnmount(stopPolling);
</script>

<template>
    <Head :title="t('identity.link.title')" />

    <div class="mx-auto max-w-lg px-4 py-10">
        <AppCard>
            <AppAlert v-if="accountCreated !== null" tone="success" class="mb-4" data-testid="account-created">
                {{ accountCreated }}
            </AppAlert>

            <h1 class="text-xl font-semibold">{{ t('identity.link.title') }}</h1>

            <template v-if="state === 'completed'">
                <AppAlert tone="success" class="mt-4">
                    {{ t('identity.link.success') }}
                </AppAlert>
            </template>

            <template v-else-if="state === 'awaiting_confirmation' || state === 'confirming' || state === 'candidate_changed'">
                <h2 class="mt-4 text-base font-semibold" data-testid="confirm-title">
                    {{ t('identity.link.confirm_title') }}
                </h2>

                <AppAlert v-if="state === 'candidate_changed'" tone="warning" class="mt-3">
                    {{ t('identity.link.candidate_changed') }}
                </AppAlert>

                <p class="mt-2 text-sm leading-relaxed opacity-90">
                    {{ t('identity.link.confirm_lead') }}
                </p>

                <p
                    class="mt-4 rounded border px-3 py-2 text-sm font-medium"
                    data-testid="candidate-identity"
                    dir="ltr"
                >
                    {{ candidateLabel(candidate) }}
                </p>

                <AppButton
                    class="mt-4 w-full"
                    data-testid="confirm-candidate"
                    :disabled="state === 'confirming' || candidateHandle === null"
                    @click="confirmCandidate"
                >
                    {{ t('identity.link.confirm_button') }}
                </AppButton>

                <p v-if="state === 'confirming'" class="mt-3 text-sm opacity-80" aria-live="polite">
                    {{ t('identity.link.confirm_waiting') }}
                </p>

                <button
                    type="button"
                    class="mt-4 text-sm underline opacity-70 hover:opacity-100"
                    data-testid="reject-candidate"
                    @click="rejectCandidate"
                >
                    {{ t('identity.link.reject_button') }}
                </button>
            </template>

            <template v-else-if="state === 'expired'">
                <AppAlert tone="warning" class="mt-4">
                    {{ t('identity.link.expired') }}
                </AppAlert>
                <AppButton class="mt-4" data-testid="restart-link" @click="restart">
                    {{ t('identity.link.restart') }}
                </AppButton>
            </template>

            <template v-else-if="state === 'conflict'">
                <AppAlert tone="danger" class="mt-4">
                    {{ t('identity.link.conflict') }}
                </AppAlert>
                <p class="mt-2 text-sm opacity-80">{{ t('identity.link.conflict_help') }}</p>
                <AppButton class="mt-4" variant="secondary" data-testid="restart-link" @click="restart">
                    {{ t('identity.link.restart') }}
                </AppButton>
            </template>

            <template v-else-if="state === 'cancelled'">
                <AppAlert tone="info" class="mt-4">
                    {{ t('identity.link.cancelled') }}
                </AppAlert>
                <AppButton class="mt-4" data-testid="restart-link" @click="restart">
                    {{ t('identity.link.restart') }}
                </AppButton>
            </template>

            <template v-else>
                <p class="mt-3 text-sm leading-relaxed opacity-90">
                    {{ t('identity.link.lead') }}
                </p>

                <AppAlert v-if="!bot_configured" tone="warning" class="mt-4">
                    {{ t('identity.telegram.unavailable') }}
                </AppAlert>

                <template v-else>
                    <AppButton class="mt-5 w-full" data-testid="open-telegram" @click="openBot">
                        {{ t('identity.link.open_button') }}
                    </AppButton>

                    <p v-if="state === 'waiting'" class="mt-4 text-sm opacity-80" aria-live="polite">
                        {{ t('identity.link.waiting') }}
                    </p>

                    <div class="mt-6 flex flex-wrap items-center gap-4">
                        <button
                            type="button"
                            class="text-sm underline opacity-70 hover:opacity-100"
                            data-testid="cancel-link"
                            @click="cancel"
                        >
                            {{ t('identity.link.cancel') }}
                        </button>

                        <button
                            type="button"
                            class="text-sm underline opacity-70 hover:opacity-100"
                            data-testid="restart-link"
                            :title="t('identity.link.restart_hint')"
                            @click="restart"
                        >
                            {{ t('identity.link.restart') }}
                        </button>

                        <button
                            type="button"
                            class="text-sm underline opacity-70 hover:opacity-100"
                            data-testid="abandon-registration"
                            @click="abandonRegistration"
                        >
                            {{ t('identity.link.abandon') }}
                        </button>
                    </div>
                </template>
            </template>
        </AppCard>
    </div>
</template>
