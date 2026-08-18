<script setup lang="ts">
import { ref } from 'vue';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import AppCard from '@/Components/ui/AppCard.vue';
import AppButton from '@/Components/ui/AppButton.vue';
import AppAlert from '@/Components/ui/AppAlert.vue';
import { t, formatNumber } from '@/lib/i18n';

/*
 * One lead (File one §9 Leads 2).
 *
 * Notes and outcomes are shown in full because they are the record a manager
 * reviews after a deal is lost. A task closed without an outcome would tell
 * them nothing, which is why the outcome selector has no empty option.
 */
interface Note {
    id: number;
    body: string | null;
    author: string | null;
    stage_at_time: string | null;
    created_at: string | null;
}

interface Task {
    id: number;
    title: string;
    kind: string;
    due_on: string | null;
    status: string;
    outcome: string | null;
    assignee: string | null;
    is_overdue: boolean;
}

interface TranscriptTurn { role: string; content: string; locale: string; at: string | null }

interface SuggestedProject {
    slug: string | null;
    name: string | null;
    area: string | null;
    price_label: string | null;
    fit_label: string | null;
    budget_state?: string;
}

const props = defineProps<{
    lead: {
        id: number;
        stage: string;
        objective: string | null;
        admin_summary?: string;
        lead_user?: {
            name: string;
            thumb: string | null;
            initials: string;
            telegram_linked: boolean;
            phone_present: boolean;
            phone_status: string;
        } | null;
        score: { total: number; reasons: Array<{ label?: string; code?: string; points?: number }> } | null;
    };
    notes: Note[];
    tasks: Task[];
    stages: string[];
    outcomes: string[];
    transcript?: TranscriptTurn[];
    suggested_projects?: SuggestedProject[];
    suggested_projects_budget_basis?: 'compared' | 'skipped_unknown' | 'skipped_legacy' | 'none';
    contact_consent?: { granted: boolean; granted_at: string | null };
    reveal_reasons?: Array<{ value: string; requires_note: boolean }>;
}>();

/*
 * The reveal (spec §9): reason first, then one POST, and the number lives in
 * component state only — the payload arrives with no-store headers and is
 * never written anywhere the page could re-serve it.
 */
const revealReason = ref<string>('');
const revealNote = ref('');
const revealBusy = ref(false);
const revealedPhone = ref<string | null>(null);
const revealError = ref<string | null>(null);

const revealNeedsNote = (): boolean =>
    props.reveal_reasons?.find((r) => r.value === revealReason.value)?.requires_note ?? false;

async function revealPhone(): Promise<void> {
    if (revealReason.value === '' || revealBusy.value) return;

    revealBusy.value = true;
    revealError.value = null;

    try {
        const response = await fetch(`/admin/leads/${props.lead.id}/phone`, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                Accept: 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
                'X-XSRF-TOKEN': decodeURIComponent(document.cookie.match(/XSRF-TOKEN=([^;]+)/)?.[1] ?? ''),
            },
            body: JSON.stringify({ reason: revealReason.value, note: revealNote.value || null }),
        });
        const body = (await response.json()) as { ok?: boolean; phone?: string; reason?: string };

        if (response.ok && body.ok === true && body.phone) {
            revealedPhone.value = body.phone;
        } else {
            revealError.value = t('leads.detail.reveal_refused', { reason: body.reason ?? 'denied' });
        }
    } catch {
        revealError.value = t('leads.detail.reveal_refused', { reason: 'network' });
    } finally {
        revealBusy.value = false;
    }
}

const noteForm = useForm({ body: '' });
const taskForm = useForm({ title: '', kind: 'follow_up', due_on: '', assigned_to_user_id: null as number | null });
const completing = ref<number | null>(null);
const chosenOutcome = ref<string>(props.outcomes[0] ?? 'reached');

function addNote(): void {
    noteForm.post(`/admin/leads/${props.lead.id}/notes`, {
        preserveScroll: true,
        onSuccess: () => noteForm.reset('body'),
    });
}

function addTask(): void {
    taskForm.post(`/admin/leads/${props.lead.id}/tasks`, {
        preserveScroll: true,
        onSuccess: () => taskForm.reset(),
    });
}

function completeTask(taskId: number): void {
    router.post(
        `/admin/leads/${props.lead.id}/tasks/${taskId}/complete`,
        { outcome: chosenOutcome.value },
        { preserveScroll: true, onSuccess: () => { completing.value = null; } },
    );
}

function moveStage(stage: string): void {
    router.post(`/admin/leads/${props.lead.id}/stage`, { stage }, { preserveScroll: true });
}
</script>

<template>
    <Head :title="lead.objective ?? t('leads.workspace')" />

    <AdminLayout>
        <template #title>{{ lead.objective ?? `#${lead.id}` }}</template>

        <Link
            href="/admin/leads"
            class="mb-5 inline-block text-sm text-ink-muted transition-colors hover:text-ink
                   focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-accent"
        >
            {{ t('app.actions.back') }}
        </Link>

        <AppAlert variant="info" class="mb-5">{{ t('leads.contact_hidden') }}</AppAlert>

        <!-- The account behind the lead + the §7.2 summary. Every phone claim
             here has exactly one strength: user-provided. -->
        <AppCard v-if="lead.lead_user" :title="t('leads.detail.account')" class="mb-5">
            <div class="flex items-start gap-4 p-1">
                <img
                    v-if="lead.lead_user.thumb"
                    :src="lead.lead_user.thumb"
                    :alt="lead.lead_user.name"
                    class="h-12 w-12 shrink-0 rounded-full border border-line object-cover"
                >
                <span
                    v-else
                    class="flex h-12 w-12 shrink-0 select-none items-center justify-center rounded-full
                           border border-line bg-surface-sunken text-sm font-semibold text-ink-muted"
                    aria-hidden="true"
                >{{ lead.lead_user.initials }}</span>

                <div class="min-w-0 space-y-1">
                    <p class="text-sm font-medium text-ink">{{ lead.lead_user.name }}</p>
                    <p class="text-xs" :class="lead.lead_user.telegram_linked ? 'text-positive' : 'text-caution'">
                        {{ lead.lead_user.telegram_linked
                            ? t('leads.detail.telegram_verified')
                            : t('leads.detail.telegram_missing') }}
                    </p>
                    <p class="text-xs text-ink-muted">
                        {{ lead.lead_user.phone_present
                            ? t('leads.detail.phone_user_provided')
                            : t('leads.detail.phone_absent') }}
                    </p>
                    <p class="text-xs" :class="contact_consent?.granted ? 'text-positive' : 'text-ink-faint'">
                        {{ contact_consent?.granted
                            ? `${t('leads.detail.consent_granted')} · ${contact_consent?.granted_at ?? ''}`
                            : t('leads.detail.consent_missing') }}
                    </p>
                </div>
            </div>

            <p v-if="lead.admin_summary" class="mt-3 border-t border-line pt-3 text-sm leading-relaxed text-ink">
                {{ lead.admin_summary }}
            </p>

            <!-- The reveal (spec §9): reason required, note required for
                 "other", the honest label above the number when it arrives. -->
            <div v-if="lead.lead_user.phone_present && reveal_reasons?.length" class="mt-4 border-t border-line pt-4">
                <p v-if="revealedPhone" class="text-sm">
                    <span class="block text-xs text-ink-faint">{{ t('leads.detail.reveal_label') }}</span>
                    <span class="numeral mt-1 block text-lg font-semibold text-ink" dir="ltr">{{ revealedPhone }}</span>
                </p>

                <template v-else>
                    <p class="text-sm font-medium text-ink">{{ t('leads.detail.reveal') }}</p>
                    <div class="mt-2 flex flex-wrap items-end gap-3">
                        <div>
                            <label for="reveal-reason" class="mb-1 block text-xs text-ink-muted">
                                {{ t('leads.detail.reveal_reason') }}
                            </label>
                            <select
                                id="reveal-reason"
                                v-model="revealReason"
                                class="block min-h-11 rounded-card border border-line bg-surface px-3 text-sm text-ink
                                       focus:border-accent focus:outline-none focus:ring-2 focus:ring-accent/40"
                            >
                                <option value="" disabled>—</option>
                                <option v-for="reason in reveal_reasons" :key="reason.value" :value="reason.value">
                                    {{ t(`leads.reveal_reasons.${reason.value}`) }}
                                </option>
                            </select>
                        </div>
                        <div v-if="revealNeedsNote()" class="min-w-48 flex-1">
                            <label for="reveal-note" class="mb-1 block text-xs text-ink-muted">
                                {{ t('leads.detail.reveal_note') }}
                            </label>
                            <input
                                id="reveal-note"
                                v-model="revealNote"
                                type="text"
                                maxlength="500"
                                class="block min-h-11 w-full rounded-card border border-line bg-surface px-3 text-sm
                                       text-ink focus:border-accent focus:outline-none focus:ring-2 focus:ring-accent/40"
                            >
                        </div>
                        <AppButton
                            type="button"
                            size="sm"
                            :loading="revealBusy"
                            :disabled="revealReason === '' || (revealNeedsNote() && revealNote.trim() === '')"
                            @click="revealPhone"
                        >
                            {{ t('leads.detail.reveal_confirm') }}
                        </AppButton>
                    </div>
                    <p v-if="revealError" class="mt-2 text-sm text-negative" role="alert">{{ revealError }}</p>
                </template>
            </div>
        </AppCard>

        <!-- Suggested projects from the STRUCTURED fields (spec §10). -->
        <AppCard v-if="suggested_projects?.length" :title="t('leads.detail.suggested')" class="mb-5">
            <!--
                v5, BLOCKER 10: when a budget exists but its currency is
                unknown or a pre-fix legacy assumption, the ranking below
                ran WITHOUT it — and says so, instead of wearing a
                budget-aware face over a budget-blind comparison.
            -->
            <p
                v-if="suggested_projects_budget_basis === 'skipped_unknown' || suggested_projects_budget_basis === 'skipped_legacy'"
                class="mb-3 rounded-md bg-warn-soft px-3 py-2 text-xs text-ink"
                role="note"
            >
                {{ suggested_projects_budget_basis === 'skipped_legacy'
                    ? t('leads.detail.budget_match_skipped_legacy')
                    : t('leads.detail.budget_match_skipped_unknown') }}
            </p>
            <ul class="divide-y divide-line">
                <li v-for="project in suggested_projects" :key="project.slug ?? project.name ?? ''" class="py-2.5">
                    <a
                        v-if="project.slug"
                        :href="`/projects/${project.slug}`"
                        target="_blank"
                        rel="noopener"
                        class="text-sm font-medium text-brand underline-offset-2 hover:underline"
                    >{{ project.name }}</a>
                    <span v-else class="text-sm font-medium text-ink">{{ project.name }}</span>
                    <p class="mt-0.5 text-xs text-ink-muted">
                        {{ [project.area, project.price_label, project.fit_label].filter(Boolean).join(' · ') }}
                    </p>
                </li>
            </ul>
        </AppCard>

        <!-- The sanitized conversation (spec §10): words only, no machinery. -->
        <AppCard v-if="transcript?.length" :title="t('leads.detail.transcript')" class="mb-5">
            <ul class="max-h-96 space-y-3 overflow-y-auto pe-1">
                <li v-for="(turn, index) in transcript" :key="index" class="text-sm">
                    <span class="mh-lux-eyebrow block">
                        {{ turn.role === 'user' ? t('advisor.chat.you') : t('advisor.chat.assistant') }}
                        <span class="text-ink-faint">· {{ turn.at }}</span>
                    </span>
                    <p
                        class="mt-1 whitespace-pre-line leading-relaxed text-ink"
                        :dir="turn.locale === 'en' ? 'ltr' : 'rtl'"
                    >
                        {{ turn.content }}
                    </p>
                </li>
            </ul>
        </AppCard>

        <AppCard v-if="lead.score" :title="t('leads.score_reasons')" class="mb-5">
            <p class="numeral font-display text-2xl font-semibold text-ink" dir="ltr">
                {{ formatNumber(lead.score.total) }}
            </p>
            <ul class="mt-2 space-y-1">
                <li v-for="(reason, index) in lead.score.reasons" :key="index" class="text-xs text-ink-muted">
                    {{ reason.label ?? reason.code }}
                    <span v-if="reason.points" class="numeral" dir="ltr">+{{ reason.points }}</span>
                </li>
            </ul>
        </AppCard>

        <AppCard :title="t('app.actions.filter')" class="mb-5">
            <div class="flex flex-wrap gap-2">
                <button
                    v-for="s in stages"
                    :key="s"
                    type="button"
                    class="rounded-card border px-3 py-1.5 text-xs transition-colors
                           focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-accent"
                    :class="s === lead.stage
                        ? 'border-brand bg-brand text-white'
                        : 'border-line text-ink-muted hover:bg-surface-sunken'"
                    :aria-pressed="s === lead.stage"
                    @click="moveStage(s)"
                >
                    {{ s }}
                </button>
            </div>
        </AppCard>

        <AppCard :title="t('leads.add_task')" class="mb-5">
            <div class="grid gap-3 sm:grid-cols-3">
                <input
                    v-model="taskForm.title" type="text" :placeholder="t('leads.add_task')"
                    class="rounded-card border border-line bg-surface-raised px-3 py-2 text-sm text-ink
                           focus:border-brand focus:outline-none focus:ring-2 focus:ring-accent"
                >
                <input
                    v-model="taskForm.due_on" type="date" dir="ltr"
                    class="rounded-card border border-line bg-surface-raised px-3 py-2 text-sm text-ink
                           focus:border-brand focus:outline-none focus:ring-2 focus:ring-accent"
                >
                <AppButton variant="primary" :disabled="taskForm.processing" @click="addTask">
                    {{ t('app.actions.add') }}
                </AppButton>
            </div>

            <ul class="mt-4 divide-y divide-line">
                <li v-for="task in tasks" :key="task.id" class="flex flex-wrap items-center gap-3 py-2.5">
                    <span class="min-w-0 flex-1 text-sm text-ink">{{ task.title }}</span>

                    <span v-if="task.is_overdue" class="text-xs text-negative">{{ t('leads.overdue') }}</span>
                    <span v-if="task.due_on" class="numeral text-xs text-ink-faint" dir="ltr">{{ task.due_on }}</span>

                    <span
                        v-if="task.outcome"
                        class="rounded-card bg-surface-sunken px-2 py-0.5 text-xs text-ink-muted"
                    >{{ t(`leads.outcomes.${task.outcome}`) }}</span>

                    <template v-else-if="completing === task.id">
                        <select
                            v-model="chosenOutcome"
                            class="rounded-card border border-line bg-surface-raised px-2 py-1 text-xs text-ink
                                   focus:border-brand focus:outline-none focus:ring-2 focus:ring-accent"
                        >
                            <option v-for="o in outcomes" :key="o" :value="o">
                                {{ t(`leads.outcomes.${o}`) }}
                            </option>
                        </select>
                        <AppButton variant="primary" size="sm" @click="completeTask(task.id)">
                            {{ t('app.actions.save') }}
                        </AppButton>
                    </template>

                    <AppButton v-else variant="ghost" size="sm" @click="completing = task.id">
                        {{ t('leads.outcome') }}
                    </AppButton>
                </li>
            </ul>
        </AppCard>

        <AppCard :title="t('leads.add_note')">
            <textarea
                v-model="noteForm.body" rows="3"
                class="w-full rounded-card border border-line bg-surface-raised px-3 py-2 text-sm text-ink
                       focus:border-brand focus:outline-none focus:ring-2 focus:ring-accent"
            />
            <div class="mt-3">
                <AppButton variant="primary" :disabled="noteForm.processing" @click="addNote">
                    {{ t('app.actions.save') }}
                </AppButton>
            </div>

            <ul class="mt-4 divide-y divide-line">
                <li v-for="note in notes" :key="note.id" class="py-3">
                    <p class="whitespace-pre-line text-sm text-ink">{{ note.body }}</p>
                    <p class="numeral mt-1 text-xs text-ink-faint" dir="ltr">
                        {{ note.author }} · {{ note.created_at }}
                        <template v-if="note.stage_at_time"> · {{ note.stage_at_time }}</template>
                    </p>
                </li>
            </ul>
        </AppCard>
    </AdminLayout>
</template>
