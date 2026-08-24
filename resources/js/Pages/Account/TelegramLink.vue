<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';
import { Head, router, usePage } from '@inertiajs/vue3';
import AppCard from '@/Components/ui/AppCard.vue';
import AppAlert from '@/Components/ui/AppAlert.vue';
import AppButton from '@/Components/ui/AppButton.vue';
import { t } from '@/lib/i18n';
import { useLocale } from '@/Composables/useLocale';

/*
 * The verification screen: one button, one instruction, one outcome.
 *
 * The entire step, from the person's side:
 *
 *     Open Telegram  →  press START  →  this page moves on by itself
 *
 * There is nothing to type, nothing to copy, and nothing to come back and
 * confirm. That last part is the change: this page used to show a "is this
 * your Telegram account?" card after the Start, which the person had to
 * return from Telegram to press. The permanent verification token removes
 * the need for it — see TelegramVerificationService for why that is safe
 * for an account with no Telegram identity yet, and why the separate
 * change-my-Telegram flow still requires it.
 *
 * NOTHING TECHNICAL IS RENDERED. No token, no code, no expiry countdown, no
 * chat id. The token exists only inside the button's href.
 *
 * THE LINK DOES NOT EXPIRE, and the page says so. Closing this tab costs
 * nothing: the server resumes the same link on the next visit, so a refresh,
 * a restored mobile tab, a second window or a sign-in three weeks later all
 * show the link that may already be open in the person's Telegram chat.
 * "Start again" is therefore a deliberate POST, not a page visit — it is the
 * only browser action that retires a live link.
 *
 * The poll is the same deliberately dumb one the sign-in pages use: it asks
 * "is MY account verified yet", never "how is token N doing" — a client that
 * could name a token could watch somebody else's. Because the answer comes
 * from the account row rather than from anything held in this session, it is
 * also correct for a tab that was verified from another device entirely.
 *
 * The candidate-confirmation UI below is retained for one reason: an
 * `account_link` intent that was already in flight when this shipped can
 * still be finished by the browser that started it. `candidate` is null for
 * every account registered since, and that branch never renders.
 */
type Candidate = {
    name: string | null;
    username: string | null;
    handle: string;
    at: string | null;
};

const props = defineProps<{
    deep_link: string | null;
    bot_configured: boolean;
    /*
     * Either a candidate parked by a legacy `account_link` intent, or the
     * ownership-transfer question a colliding Start parked. The decision UI
     * below renders only when it is non-null, so the ordinary journey never
     * sees a second step.
     */
    candidate: Candidate | null;
    candidate_handle: string | null;
    /*
     * True when the parked candidate currently belongs to ANOTHER account,
     * so confirming means MOVING the Telegram identity here — the page asks
     * the transfer question with its warning instead of the plain "is this
     * your Telegram". Advisory for rendering; the server re-establishes the
     * fact under locks and requires the explicit acknowledgement either way.
     */
    candidate_transfer: boolean;
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
    | 'awaiting_transfer'
    | 'confirming'
    | 'completed'
    | 'expired'
    | 'conflict'
    | 'cancelled'
    | 'candidate_changed';

/*
 * A candidate may already be parked when the page RENDERS — the person
 * pressed Start, then closed and reopened the tab. Because the question
 * survives a reload (the transfer one is bound to the account itself), the
 * decision UI has to survive one too.
 */
const state = ref<LinkState>(
    props.candidate !== null
        ? (props.candidate_transfer ? 'awaiting_transfer' : 'awaiting_confirmation')
        : 'idle',
);
const candidate = ref<Candidate | null>(props.candidate);
const candidateHandle = ref<string | null>(props.candidate_handle);
/*
 * Which question is on screen. Set from the render props and from every
 * poll, and read when the person presses the primary button so the request
 * carries the transfer acknowledgement exactly when the transfer question
 * was the one being answered.
 */
const transferMode = ref<boolean>(props.candidate_transfer);
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
        } else if (body.state === 'awaiting_confirmation' || body.state === 'awaiting_transfer') {
            /*
             * Keep polling. A second Start from a different Telegram
             * account replaces the candidate, and the person must see the
             * one that is actually parked right now — not the one that was
             * parked when they walked away from the screen. The QUESTION can
             * change too: a candidate that was unclaimed a moment ago may be
             * claimed now, which turns the plain confirmation into the
             * transfer decision.
             */
            candidate.value = body.candidate ?? null;
            candidateHandle.value = body.candidate_handle ?? null;
            transferMode.value = body.state === 'awaiting_transfer';

            if (state.value !== 'confirming') {
                state.value = body.state;
            }
        } else if (body.state === 'expired' || body.state === 'conflict' || body.state === 'cancelled') {
            stopPolling();
            state.value = body.state;
        }
    } catch {
        // A transient network failure: the next tick asks again.
    }
}

/**
 * The link itself navigates; this only updates what the page says while the
 * person is away. Deliberately does not preventDefault, does not open a window,
 * and does nothing that could stop the tap from reaching Telegram.
 */
function markWaiting(): void {
    state.value = 'waiting';
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
 * An account created by the form holds its phone number under a unique index,
 * so somebody who mistyped it — or who simply cannot finish — needs a way to
 * release it and start again rather than being told the number is taken by an
 * account they cannot reach. The server revokes the verification link, then
 * refuses the release itself if the account is linked or owns anything.
 *
 * "Cancel linking" used to sit beside this and is gone. It retired the current
 * ten-minute token and left the account in place, which was a meaningful thing
 * to do when the token was about to expire anyway. With a link that never
 * expires there is nothing to cancel: leaving is free, and coming back is the
 * default. The two remaining actions are the ones that still mean something —
 * replace the link, or give up the registration. (The endpoint itself is
 * untouched, so an older tab still posting to it is answered as before.)
 */
function abandonRegistration(): void {
    router.post(localized('/account/registration/abandon'), {}, { preserveScroll: false });
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

    /*
     * Remember WHICH question is being answered before flipping to the
     * in-flight state: the transfer acknowledgement must describe the screen
     * the person pressed the button on, and a failed attempt must return
     * them to that same question.
     */
    const answeringTransfer = transferMode.value;

    state.value = 'confirming';

    try {
        const response = await fetch(localized('/account/telegram/link/confirm'), {
            method: 'POST',
            credentials: 'same-origin',
            headers: jsonHeaders(),
            body: JSON.stringify({
                candidate_handle: candidateHandle.value,
                /*
                 * The explicit acknowledgement, sent ONLY when the transfer
                 * question was on screen. The server refuses a transfer
                 * without it, and a plain confirmation never sends it — so a
                 * stale page can neither move a claim by accident nor be
                 * tricked into it.
                 */
                ...(answeringTransfer ? { accept_transfer: true } : {}),
            }),
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

        if (body.state === 'candidate_changed' || body.state === 'transfer_required') {
            state.value = 'candidate_changed';
            void poll();

            return;
        }

        stopPolling();
        state.value = body.state === 'conflict' ? 'conflict' : 'expired';
    } catch {
        // Network trouble mid-confirm: fall back to the poll loop, which
        // will re-report whatever the server actually thinks.
        state.value = answeringTransfer ? 'awaiting_transfer' : 'awaiting_confirmation';
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

/*
 * §15: automatic detection is the primary behaviour, and the manual button
 * below is only a fallback for somebody who does not want to wait for the next
 * tick.
 *
 * There is no expiry timer any more. The old page set one from
 * `expires_in_seconds` so it could stop polling a corpse when the ten minutes
 * ran out; the verification token has no clock, so there is no corpse and
 * nothing to count down. Leaving the tab open simply keeps asking.
 */
const checking = ref(false);

async function checkNow(): Promise<void> {
    checking.value = true;

    try {
        await poll();
    } finally {
        checking.value = false;
    }
}

onMounted(() => {
    timer = setInterval(() => void poll(), 2500);
});

onBeforeUnmount(stopPolling);
</script>

<template>
    <Head :title="t('identity.link.title')" />

    <div class="mx-auto max-w-lg px-4 py-10">
        <AppCard>
            <AppAlert v-if="accountCreated !== null" variant="success" class="mb-4" data-testid="account-created">
                {{ accountCreated }}
            </AppAlert>

            <h1 class="text-xl font-semibold">{{ t('identity.link.title') }}</h1>

            <template v-if="state === 'completed'">
                <AppAlert variant="success" class="mt-4">
                    {{ t('identity.link.success') }}
                </AppAlert>
            </template>

            <!--
                The ownership-transfer decision. The candidate Telegram account
                is already linked to an older MULK account, and the person is
                asked — in this authenticated browser, never in the chat —
                whether to move it here. Only the candidate's own Telegram
                display identity is shown; nothing about the older account
                appears, because this page must never describe one person's
                account to another.
            -->
            <template v-else-if="state === 'awaiting_transfer' || ((state === 'confirming' || state === 'candidate_changed') && transferMode)">
                <h2 class="mt-4 text-base font-semibold" data-testid="transfer-title">
                    {{ t('identity.link.transfer_title') }}
                </h2>

                <AppAlert v-if="state === 'candidate_changed'" variant="warning" class="mt-3" data-testid="transfer-stale">
                    {{ t('identity.link.transfer_stale') }}
                </AppAlert>

                <p class="mt-2 text-sm leading-relaxed opacity-90">
                    {{ t('identity.link.transfer_lead') }}
                </p>

                <p
                    class="mt-4 rounded border px-3 py-2 text-sm font-medium"
                    data-testid="candidate-identity"
                    dir="ltr"
                >
                    {{ candidateLabel(candidate) }}
                </p>

                <AppAlert variant="warning" class="mt-4" data-testid="transfer-warning">
                    {{ t('identity.link.transfer_warning') }}
                </AppAlert>

                <AppButton
                    class="mt-4 w-full"
                    data-testid="confirm-transfer"
                    :disabled="state === 'confirming' || candidateHandle === null"
                    @click="confirmCandidate"
                >
                    {{ t('identity.link.transfer_button') }}
                </AppButton>

                <p v-if="state === 'confirming'" class="mt-3 text-sm opacity-80" aria-live="polite">
                    {{ t('identity.link.confirm_waiting') }}
                </p>

                <button
                    type="button"
                    class="mt-4 text-sm underline opacity-70 hover:opacity-100"
                    data-testid="reject-transfer"
                    @click="rejectCandidate"
                >
                    {{ t('identity.link.transfer_cancel') }}
                </button>
            </template>

            <template v-else-if="state === 'awaiting_confirmation' || state === 'confirming' || state === 'candidate_changed'">
                <h2 class="mt-4 text-base font-semibold" data-testid="confirm-title">
                    {{ t('identity.link.confirm_title') }}
                </h2>

                <AppAlert v-if="state === 'candidate_changed'" variant="warning" class="mt-3">
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
                <AppAlert variant="warning" class="mt-4">
                    {{ t('identity.link.expired') }}
                </AppAlert>
                <AppButton class="mt-4" data-testid="restart-link" @click="restart">
                    {{ t('identity.link.restart') }}
                </AppButton>
            </template>

            <template v-else-if="state === 'conflict'">
                <AppAlert variant="danger" class="mt-4">
                    {{ t('identity.link.conflict') }}
                </AppAlert>
                <p class="mt-2 text-sm opacity-80">{{ t('identity.link.conflict_help') }}</p>
                <AppButton class="mt-4" variant="secondary" data-testid="restart-link" @click="restart">
                    {{ t('identity.link.restart') }}
                </AppButton>
            </template>

            <template v-else-if="state === 'cancelled'">
                <AppAlert variant="info" class="mt-4">
                    {{ t('identity.link.cancelled') }}
                </AppAlert>
                <AppButton class="mt-4" data-testid="restart-link" @click="restart">
                    {{ t('identity.link.restart') }}
                </AppButton>
            </template>

            <!--
                §14: the whole verification screen. One instruction, one
                button, one sentence saying it can wait.

                Nothing technical appears here — no token, no code, no chat id,
                no countdown. The token exists only inside the href of the
                button, and there is nothing for anybody to copy or type.
            -->
            <template v-else>
                <p class="mt-3 text-sm leading-relaxed opacity-90">
                    {{ t('identity.link.lead') }}
                </p>

                <AppAlert v-if="!bot_configured" variant="warning" class="mt-4">
                    {{ t('identity.telegram.unavailable') }}
                </AppAlert>

                <template v-else>
                    <!--
                        A real anchor, not a scripted window.open().

                        This is the one control the entire flow depends on, and
                        it is pressed on a phone. Mobile browsers routinely
                        block a popup opened from script and open an href
                        without complaint, so the tap that leaves for Telegram
                        must be an ordinary link. The click handler only flips
                        the local "waiting" copy; if scripting were disabled
                        entirely the link would still work.
                    -->
                    <a
                        v-if="deep_link"
                        :href="deep_link"
                        target="_blank"
                        rel="noopener"
                        data-testid="open-telegram"
                        class="mt-5 flex min-h-11 w-full items-center justify-center rounded-card bg-brand px-4
                               text-sm font-medium text-white transition-colors hover:opacity-90
                               focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-accent"
                        @click="markWaiting"
                    >
                        {{ t('identity.link.open_button') }}
                    </a>

                    <p class="mt-4 text-sm leading-relaxed opacity-90" data-testid="press-start-hint">
                        {{ t('identity.link.press_start') }}
                    </p>

                    <p v-if="state === 'waiting'" class="mt-3 text-sm opacity-80" aria-live="polite">
                        {{ t('identity.link.waiting') }}
                    </p>

                    <!--
                        The promise the permanent token makes, said out loud.
                        Somebody who cannot finish now needs to know that
                        closing this page costs them nothing.
                    -->
                    <AppAlert variant="info" class="mt-5" data-testid="link-never-expires">
                        {{ t('identity.link.later') }}
                    </AppAlert>

                    <!--
                        §15's fallback. Automatic detection is the primary
                        behaviour — the poll above runs every 2.5s — and this
                        exists for the person who came back from Telegram and
                        would rather not wait for the next tick.
                    -->
                    <button
                        type="button"
                        class="mt-4 w-full text-sm underline opacity-70 hover:opacity-100"
                        data-testid="check-verification"
                        :disabled="checking"
                        @click="checkNow"
                    >
                        {{ t('identity.link.check_now') }}
                    </button>

                    <div class="mt-6 flex flex-wrap items-center gap-4">
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
