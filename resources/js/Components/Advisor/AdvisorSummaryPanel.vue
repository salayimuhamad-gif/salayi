<script setup lang="ts">
import { computed } from 'vue';
import { t, formatNumber } from '@/lib/i18n';

/*
 * The editable summary §9.1 requires, including what is NOT known.
 *
 * UNANSWERED SLOTS STAY VISIBLE. This is the file's one non-negotiable. Hiding
 * them would let a person believe the advisor knew something it never asked
 * about, and they would then read the recommendations as better informed than
 * they are. The redesign made the panel prettier and did not make it quieter:
 * every slot the flow defines is listed, answered or not.
 *
 * REQUIRED IS NOW MARKED. `required` was already arriving from
 * AdvisorConversationFlow and the previous panel ignored it, so a visitor could
 * not tell which blanks were blocking the recommendation and which were
 * optional. It is a text label rather than an asterisk or a colour, per §13.
 *
 * EDIT HONOURS `editable`. The server sets it true for every row today, so this
 * changes nothing now; it means that the first row the flow locks will not
 * render a control that the amend endpoint would refuse.
 *
 * The panel owns no network calls. `startEdit` and `saveEdit` still live on the
 * page, against the same /advisor/amend endpoint, because moving a request into
 * a presentational component is how an endpoint quietly acquires a second
 * caller.
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

const props = defineProps<{
    summary: SummaryRow[];
    editing: string | null;
    progress: number;
}>();

/** Two-way bound to the page's `editValue`, so the input stays controlled there. */
const draft = defineModel<string>('draft', { required: true });

const emit = defineEmits<{
    start: [row: SummaryRow];
    save: [];
    cancel: [];
}>();

const answered = computed(() => props.summary.filter((row) => row.answered).length);
</script>

<template>
    <section class="mh-lux-panel mh-lux-gilded overflow-hidden">
        <header class="border-b border-line px-5 py-4">
            <h2 class="font-display text-base font-semibold text-ink">{{ t('advisor.chat.summary') }}</h2>

            <p class="mt-1 text-xs text-ink-muted">
                <span class="numeral">{{ formatNumber(answered) }}</span>
                /
                <span class="numeral">{{ formatNumber(summary.length) }}</span>
            </p>
        </header>

        <ul class="divide-y divide-line">
            <li v-for="row in summary" :key="row.key" class="px-5 py-3.5">
                <div class="flex flex-wrap items-center gap-x-3 gap-y-2">
                    <span class="min-w-0 flex-1">
                        <span class="block text-sm text-ink">{{ t(`advisor.chat.slots.${row.key}`) }}</span>
                        <span v-if="row.required && !row.answered" class="block text-xs text-caution">
                            {{ t('advisor.chat.required') }}
                        </span>
                    </span>

                    <template v-if="editing === row.key">
                        <input
                            v-model="draft"
                            type="text"
                            :aria-label="t(`advisor.chat.slots.${row.key}`)"
                            class="min-w-0 flex-1 rounded-card border border-line bg-surface-sunken px-2.5 py-1.5 text-sm text-ink
                                   focus:border-accent focus:outline-none focus:ring-2 focus:ring-accent"
                        >
                        <button type="button" class="mh-lux-btn mh-lux-btn-primary !px-3" @click="emit('save')">
                            {{ t('app.actions.save') }}
                        </button>
                        <button type="button" class="mh-lux-btn mh-lux-btn-ghost !px-3" @click="emit('cancel')">
                            {{ t('app.actions.cancel') }}
                        </button>
                    </template>

                    <template v-else>
                        <span
                            class="text-sm"
                            :class="row.answered ? 'text-ink' : 'text-ink-faint'"
                        >{{ row.answered ? row.value : t('advisor.chat.not_answered') }}</span>

                        <button
                            v-if="row.editable"
                            type="button"
                            class="mh-lux-btn mh-lux-btn-ghost !px-2.5 !text-xs"
                            @click="emit('start', row)"
                        >
                            {{ t('advisor.chat.edit') }}
                        </button>
                    </template>
                </div>
            </li>
        </ul>

        <footer class="border-t border-line px-5 py-4">
            <div class="flex items-center justify-between gap-3">
                <span class="mh-lux-eyebrow">{{ t('advisor.chat.progress') }}</span>
                <span class="numeral text-xs text-ink-muted">{{ progress }}%</span>
            </div>

            <div
                class="mt-2 h-1.5 overflow-hidden rounded-full bg-surface-sunken"
                role="progressbar"
                :aria-valuenow="progress"
                aria-valuemin="0"
                aria-valuemax="100"
                :aria-label="t('advisor.chat.progress')"
            >
                <div
                    class="h-full rounded-full bg-accent transition-[width] duration-500 ease-calm"
                    :style="{ width: `${progress}%` }"
                />
            </div>
        </footer>
    </section>
</template>
