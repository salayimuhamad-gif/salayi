<script setup lang="ts">
import { computed } from 'vue';
import AppIcon from '@/Components/Icons/AppIcon.vue';
import AiAvatar from '@/Components/Public/AiAvatar.vue';
import UserAvatar from '@/Components/ui/UserAvatar.vue';
import { t, formatNumber } from '@/lib/i18n';

/*
 * One turn of the interview (§9.1).
 *
 * WHY THE ROLE IS SPELLED OUT IN WORDS. §13 forbids meaning carried by colour
 * alone, and "the darker bubble is the robot" is exactly that. Each turn
 * therefore names its speaker in text — `advisor.chat.you` /
 * `advisor.chat.assistant` — and the alignment, the avatar and the tint are
 * reinforcement rather than the signal.
 *
 * WITHHELD. Rendered as withheld, above the content, unchanged from before.
 * §9 requires the advisor to say plainly when it cannot answer; a turn that
 * silently disappears makes a principled refusal look like a bug. The
 * `withheld_reason` code is deliberately still not printed: it is an internal
 * string like `not_validated` with no translation behind it, so showing it
 * would put untranslated English in front of a Sorani reader. It is recorded in
 * the limitations report instead.
 *
 * EVIDENCE COUNT. Shown when the server sends one, as before. It is the number
 * of records the answer was grounded in, and it is the difference between an
 * assertion and a citation.
 *
 * `whitespace-pre-line` is preserved: the composer emits paragraph breaks and
 * collapsing them turns a structured answer into a wall.
 */
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
    message: Message;
    // Absent on surfaces that render anonymous transcripts; the icon fallback
    // below keeps those exactly as they were.
    userAvatar?: { photo: string | null; thumb: string | null; initials: string } | null;
}>();

const isUser = computed(() => props.message.role === 'user');
</script>

<template>
    <li class="flex gap-3" :class="isUser ? 'flex-row-reverse' : ''">
        <UserAvatar
            v-if="isUser && userAvatar"
            :photo="userAvatar.thumb"
            :initials="userAvatar.initials"
            size="sm"
            class="mt-0.5"
        />
        <span
            v-else
            class="mt-0.5 flex h-9 w-9 shrink-0 items-center justify-center overflow-hidden rounded-full border border-line"
            :class="isUser ? 'bg-surface-sunken' : 'mh-lux-field'"
            aria-hidden="true"
        >
            <AppIcon v-if="isUser" name="user" class="h-4 w-4 text-ink-muted" />
            <AiAvatar v-else :glow="false" class="h-9 w-9" />
        </span>

        <div class="min-w-0 flex-1" :class="isUser ? 'flex flex-col items-end' : ''">
            <p class="mh-lux-eyebrow mb-1.5">
                {{ isUser ? t('advisor.chat.you') : t('advisor.chat.assistant') }}
            </p>

            <div
                class="max-w-full rounded-panel px-4 py-3"
                :class="isUser ? 'bg-surface-sunken' : 'border border-line bg-surface-raised'"
            >
                <p
                    v-if="message.is_withheld"
                    class="mb-2 flex items-center gap-2 text-xs font-medium text-caution"
                >
                    <span aria-hidden="true">!</span>
                    {{ t('advisor.chat.withheld') }}
                </p>

                <p class="whitespace-pre-line text-sm leading-relaxed text-ink">{{ message.content }}</p>

                <!--
                    Real server fields, both of them. `content_class` is
                    validated against a fixed set by the model and every member
                    has a translation under advisor.content_classes, so the chip
                    is the server's own classification of the turn rather than
                    this component's guess at it.
                -->
                <div
                    v-if="!isUser && (message.content_class || message.evidence_count > 0)"
                    class="mt-3 flex flex-wrap items-center gap-2 border-t border-line pt-2.5"
                >
                    <span v-if="message.content_class" class="mh-lux-chip">
                        {{ t(`advisor.content_classes.${message.content_class}`) }}
                    </span>

                    <span v-if="message.evidence_count > 0" class="mh-lux-chip">
                        {{ t('advisor.chat.evidence') }}
                        <span class="numeral">{{ formatNumber(message.evidence_count) }}</span>
                    </span>
                </div>
            </div>
        </div>
    </li>
</template>
