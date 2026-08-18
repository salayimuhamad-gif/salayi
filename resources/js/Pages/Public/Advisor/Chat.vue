<script setup lang="ts">
import { computed, ref } from 'vue';
import { Head, router, useForm } from '@inertiajs/vue3';
import PublicLayout from '@/Layouts/PublicLayout.vue';
import AppIcon from '@/Components/Icons/AppIcon.vue';
import AiAvatar from '@/Components/Public/AiAvatar.vue';
import AdvisorMessage from '@/Components/Advisor/AdvisorMessage.vue';
import AdvisorSummaryPanel from '@/Components/Advisor/AdvisorSummaryPanel.vue';
import { t } from '@/lib/i18n';
import { useLocale } from '@/Composables/useLocale';

/*
 * Bare public paths are the *default-locale* (Sorani) URLs under
 * `prefix_except_default`. Emitting them from an Arabic or English page threw
 * the visitor back into Sorani, so each one is wrapped by `localized()`.
 */
const { localized } = useLocale();

/*
 * The Sorani residential advisor (File two §9).
 *
 * One question is on screen at a time, because §9.1 requires it and because an
 * eleven-field form is the thing this interview exists to replace.
 *
 * Two decisions in this file are about honesty rather than layout:
 *
 *   - A WITHHELD answer is rendered as withheld, with its reason. §9 requires
 *     the advisor to say plainly when it cannot answer; a turn that silently
 *     disappears makes a principled refusal look like a bug.
 *
 *   - The summary shows unanswered slots explicitly. Hiding them would let a
 *     person believe the advisor knew something it never asked about, and they
 *     would then read the recommendations as better informed than they are.
 */
/*
 * THE REDESIGN (§9) TOUCHED THE LAYOUT AND NOTHING BELOW IT.
 *
 * Every request in this file is byte-identical to the one it replaces: the same
 * four endpoints, the same payloads, the same `preserveScroll`, the same
 * `form.reset('message')` on success. The submit button is still disabled while
 * `form.processing`. `form.errors.message` still renders where the composer is.
 * No message is sent automatically and no greeting is fabricated — if the
 * transcript is empty it renders empty, because the backend is the only thing
 * entitled to put a turn in it.
 *
 * What changed is the shell: a two-column workspace on desktop with the
 * transcript leading and the summary beside it, collapsing to a single column
 * with the summary beneath on mobile, per §9.2.
 *
 * The availability line is driven by the real `ai_available` prop in both
 * directions — it says "available" only when the server says so, and otherwise
 * shows the existing unavailable message rather than hiding the state.
 */
interface SummaryRow {
    key: string;
    value: string | null;
    /** The canonical criteria value behind the localized label (Phase 18). */
    raw_value?: string | null;
    answered: boolean;
    required: boolean;
    editable: boolean;
}

interface Message {
    role: string;
    content: string;
    content_class: string;
    is_withheld: boolean;
    withheld_reason: string | null;
    model: string | null;
    evidence_count: number;
}

const props = defineProps<{
    conversation_id: string;
    next_slot: string | null;
    complete: boolean;
    stage?: string;
    progress: number;
    summary: SummaryRow[];
    messages: Message[];
    user_avatar?: { photo: string | null; thumb: string | null; initials: string } | null;
    request_status?: string | null;
    ai_available: boolean;
}>();

/*
 * Send-to-team (spec §7.3). One consent checkbox, one POST, and the state
 * flips to SUBMITTED — which also arrives from the server as `request_status`
 * so a refresh shows the truth instead of re-offering the button. The person
 * stays on this page throughout.
 */
const submitConsent = ref(false);
const submitBusy = ref(false);
const submitted = ref(props.request_status !== null && props.request_status !== undefined);
const submitError = ref<string | null>(null);

async function sendRequest(): Promise<void> {
    if (!submitConsent.value || submitBusy.value) return;

    submitBusy.value = true;
    submitError.value = null;

    try {
        const response = await fetch(localized('/advisor/request'), {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                Accept: 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
                'X-XSRF-TOKEN': decodeURIComponent(
                    document.cookie.match(/XSRF-TOKEN=([^;]+)/)?.[1] ?? '',
                ),
            },
            body: JSON.stringify({ consent: true }),
        });

        const body = (await response.json()) as { ok?: boolean; reason?: string };

        if (response.ok && body.ok === true) {
            submitted.value = true;
        } else {
            submitError.value = body.reason === 'incomplete'
                ? t('advisor.submit.incomplete')
                : t('advisor.chat.send_failed');
        }
    } catch {
        submitError.value = t('advisor.chat.send_failed');
    } finally {
        submitBusy.value = false;
    }
}

const form = useForm({ message: '' });
const editing = ref<string | null>(null);
const editValue = ref('');

const question = computed(() =>
    props.next_slot === null ? null : t(`advisor.chat.slots.${props.next_slot}`),
);

function send(): void {
    if (form.message.trim() === '') return;

    form.post(localized('/advisor/reply'), {
        preserveScroll: true,
        onSuccess: () => form.reset('message'),
    });
}

function startEdit(row: SummaryRow): void {
    editing.value = row.key;
    // The CANONICAL value, not the localized label (Phase 18): editing what
    // the criteria actually hold means a save-without-change is a true
    // no-op, and a formatted caption never masquerades as the stored value.
    editValue.value = row.raw_value ?? row.value ?? '';
}

function saveEdit(): void {
    if (editing.value === null) return;

    router.post(localized('/advisor/amend'), { slot: editing.value, value: editValue.value }, {
        preserveScroll: true,
        onSuccess: () => { editing.value = null; },
    });
}

/*
 * Leaving edit mode without sending anything. New, and deliberately local: it
 * touches no endpoint, and its absence meant a visitor who opened the wrong row
 * had to overwrite a correct answer with itself to get out.
 */
function cancelEdit(): void {
    editing.value = null;
    editValue.value = '';
}

function recommend(): void {
    router.post(localized('/advisor/recommend'), {}, { preserveScroll: true });
}

function reset(): void {
    router.post(localized('/advisor/reset'), {}, { preserveScroll: true });
}
</script>

<template>
    <Head :title="t('advisor.chat.title')" />

    <PublicLayout>
        <!-- The live-chat overlay's containment gate: the plain-JS widget is
             loaded on every page but may decorate a header only where this
             marker exists. Unconditional on purpose — the user-avatar island
             below renders only for signed-in visitors, and anonymous advisor
             sessions keep the live ring and badge too. -->
        <div data-advisor-chat class="grid gap-6 lg:grid-cols-[minmax(0,1fr)_20rem] lg:items-start">
            <!-- §9.2: the conversation comes first in source order, so it is
                 also first on a phone with no reordering. -->
            <div class="min-w-0 space-y-5">
                <header class="mh-lux-panel mh-lux-field mh-lux-gilded flex items-center gap-4 p-5">
                    <AiAvatar class="h-14 w-14 shrink-0" />

                    <div class="min-w-0 flex-1">
                        <h1 class="font-display text-xl font-bold text-ink">{{ t('advisor.chat.title') }}</h1>

                        <!-- The real state, both ways. A dot alone would be
                             meaning carried by colour; the word carries it. -->
                        <p
                            class="mt-1 flex items-center gap-2 text-xs"
                            :class="ai_available ? 'text-positive' : 'text-caution'"
                        >
                            <span
                                class="inline-block h-1.5 w-1.5 shrink-0 rounded-full bg-current"
                                aria-hidden="true"
                            />
                            {{ ai_available ? t('advisor.chat.available') : t('advisor.chat.unavailable') }}
                        </p>
                    </div>
                </header>

                <!-- Transcript. Empty stays empty: no fabricated greeting. -->
                <!-- The live-chat overlay is plain JS layered over this page;
                     it reads its data from this island rather than reaching
                     into Vue internals. JSON in a script tag is inert. -->
                <!-- The v7 overlay reads this bridge's textContent; template
                     interpolation sets exactly that, and unlike v-text it is
                     lint-clean on a dynamic component tag. -->
                <component
                    :is="'script'"
                    v-if="user_avatar"
                    type="application/json"
                    data-advisor-user-avatar
                >
                    {{ JSON.stringify(user_avatar) }}
                </component>

                <ul v-if="messages.length > 0" class="space-y-5">
                    <AdvisorMessage
                        v-for="(message, index) in messages"
                        :key="index"
                        :message="message"
                        :user-avatar="user_avatar"
                    />
                </ul>

                <!-- One question during intake, and an OPEN composer after it:
                     a complete checklist starts the follow-up conversation,
                     it does not end the chat. The section renders in both
                     stages — hiding it on `complete` was the frontend half of
                     the "9 questions then silence" defect. -->
                <section class="mh-lux-panel p-5">
                    <label :for="`advisor-answer-${conversation_id}`" class="mb-3 block text-sm font-medium text-ink">
                        {{ question ?? t('advisor.chat.followup_hint') }}
                    </label>

                    <textarea
                        :id="`advisor-answer-${conversation_id}`"
                        v-model="form.message"
                        rows="3"
                        :placeholder="t('advisor.chat.ask')"
                        class="w-full rounded-card border border-line bg-surface-sunken px-3 py-2.5 text-sm text-ink
                               placeholder:text-ink-faint focus:border-accent focus:outline-none focus:ring-2
                               focus:ring-accent"
                    />

                    <p v-if="form.errors.message" class="mt-1.5 text-xs text-negative" role="alert">
                        {{ form.errors.message }}
                    </p>

                    <div class="mt-3">
                        <button
                            type="button"
                            class="mh-lux-btn mh-lux-btn-primary focus-visible:outline-none focus-visible:ring-2
                                   focus-visible:ring-accent focus-visible:ring-offset-2 focus-visible:ring-offset-surface"
                            :disabled="form.processing"
                            :aria-busy="form.processing"
                            @click="send"
                        >
                            {{ t('advisor.chat.send') }}
                            <AppIcon name="send" class="h-4 w-4" mirror />
                        </button>
                    </div>
                </section>

                <!--
                    Interview finished. The recommendation ACTION and the
                    send-to-team block — ALONGSIDE the composer above, not
                    instead of it. Nothing resembling a result preview: §9.1
                    forbids a preview before the recommend route has run.
                -->
                <section v-if="question === null" class="mh-lux-panel p-5">
                    <p class="text-sm text-ink-muted">{{ t('advisor.chat.complete_hint') }}</p>

                    <button
                        type="button"
                        class="mh-lux-btn mh-lux-btn-primary mt-3 focus-visible:outline-none focus-visible:ring-2
                               focus-visible:ring-accent focus-visible:ring-offset-2 focus-visible:ring-offset-surface"
                        @click="recommend"
                    >
                        {{ t('advisor.chat.recommend') }}
                        <AppIcon name="arrow-end" class="h-4 w-4" mirror />
                    </button>

                    <!-- Send-to-team (spec §7.3): consent text first, one
                         action, confirmation in place. -->
                    <div class="mt-5 border-t border-line pt-4" data-advisor-submit-panel>
                        <p v-if="submitted" class="text-sm text-positive" role="status">
                            {{ t('advisor.submit.sent') }}
                            <a
                                :href="localized('/account/requests')"
                                class="font-medium underline underline-offset-2"
                            >{{ t('advisor.submit.view_requests') }}</a>
                        </p>

                        <template v-else>
                            <p class="text-sm font-medium text-ink">{{ t('advisor.submit.title') }}</p>
                            <label class="mt-2 flex min-h-11 cursor-pointer items-start gap-3 text-sm text-ink-muted">
                                <input
                                    v-model="submitConsent"
                                    type="checkbox"
                                    class="mt-1 h-4 w-4 rounded border-line text-brand focus:ring-accent"
                                >
                                <span>{{ t('advisor.submit.consent') }}</span>
                            </label>
                            <button
                                type="button"
                                class="mh-lux-btn mh-lux-btn-primary mt-3 focus-visible:outline-none
                                       focus-visible:ring-2 focus-visible:ring-accent focus-visible:ring-offset-2
                                       focus-visible:ring-offset-surface"
                                :disabled="!submitConsent || submitBusy"
                                :aria-busy="submitBusy"
                                @click="sendRequest"
                            >
                                {{ t('advisor.submit.button') }}
                            </button>
                            <p v-if="submitError" class="mt-2 text-sm text-negative" role="alert">
                                {{ submitError }}
                            </p>
                        </template>
                    </div>
                </section>
            </div>

            <!-- §9.2: beneath the conversation on mobile, beside it from lg. -->
            <div class="space-y-4 lg:sticky lg:top-20">
                <AdvisorSummaryPanel
                    v-model:draft="editValue"
                    :summary="summary"
                    :editing="editing"
                    :progress="progress"
                    @start="startEdit"
                    @save="saveEdit"
                    @cancel="cancelEdit"
                />

                <button type="button" class="mh-lux-btn mh-lux-btn-secondary w-full" @click="reset">
                    {{ t('advisor.chat.reset') }}
                </button>
            </div>
        </div>
    </PublicLayout>
</template>
