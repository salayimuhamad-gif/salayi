<script setup lang="ts">
import { t } from '@/lib/i18n';

interface Entry {
    title: string;
    summary: string | null;
    event_type: string;
    direction: string;
    strength: number;
    evidence_class: string;
    effective_date: string | null;
    expected_date: string | null;
    source: string | null;
    source_url: string | null;
    confidence: string;
}

defineProps<{ timeline: Entry[] }>();

/*
 * Direction drives the marker colour, strength its size. A road opening and a
 * road closure are both "road" events, and rendering them identically would
 * make the timeline a list of things that happened rather than an account of
 * what they meant.
 */
const tone = (direction: string): string => ({
    positive: 'bg-positive',
    negative: 'bg-negative',
    neutral: 'bg-ink-faint',
}[direction] ?? 'bg-ink-faint');

const size = (strength: number): string =>
    strength >= 4 ? 'h-3 w-3' : strength >= 2 ? 'h-2.5 w-2.5' : 'h-2 w-2';

/*
 * The evidence class in the entry's own voice (Phase 13): a verified fact
 * wears the brand tone, a prediction wears caution — a claim about the
 * future must never dress like one that has happened. Observation and
 * interpretation stay neutral; their label carries the distinction. The
 * server resolves a legacy null class to admin_observation before it ever
 * reaches this component.
 */
const classTone = (evidenceClass: string): string => ({
    verified_fact: 'bg-brand/10 text-brand',
    prediction: 'bg-caution/10 text-caution',
}[evidenceClass] ?? 'bg-surface-sunken text-ink-muted');
</script>

<template>
    <ol class="relative space-y-5 border-s border-line ps-5">
        <li v-for="(entry, index) in timeline" :key="index" class="relative">
            <span
                class="absolute -start-[1.4rem] top-1.5 rounded-full ring-2 ring-surface"
                :class="[tone(entry.direction), size(entry.strength)]"
                aria-hidden="true"
            />

            <div class="flex flex-wrap items-baseline gap-x-3 gap-y-1">
                <time
                    v-if="entry.effective_date" :datetime="entry.effective_date"
                    class="numeral text-xs text-ink-faint" dir="ltr"
                >
                    {{ entry.effective_date }}
                </time>
                <span class="rounded-full bg-surface-sunken px-2 py-0.5 text-xs text-ink-muted">
                    {{ t(`knowledge.event_types.${entry.event_type}`) }}
                </span>
                <span
                    class="rounded-full px-2 py-0.5 text-xs"
                    :class="classTone(entry.evidence_class)"
                >
                    {{ t(`knowledge.evidence_classes.${entry.evidence_class}`) }}
                </span>
            </div>

            <p class="mt-1 text-sm font-medium text-ink">{{ entry.title }}</p>
            <p v-if="entry.summary" class="mt-0.5 text-sm text-ink-muted">{{ entry.summary }}</p>

            <!-- An expected date is a claim about the future and is labelled as
                 one, rather than sitting alongside the effective date as if
                 both had happened. -->
            <p v-if="entry.expected_date" class="numeral mt-1 text-xs text-caution" dir="ltr">
                {{ t('knowledge.expected') }}: {{ entry.expected_date }}
            </p>

            <p class="mt-1.5 text-xs text-ink-faint">
                <template v-if="entry.source">
                    {{ t('app.meta.source') }}:
                    <a
                        v-if="entry.source_url" :href="entry.source_url" rel="noopener nofollow external"
                        target="_blank" class="text-brand underline-offset-2 hover:underline"
                    >{{ entry.source }}</a>
                    <span v-else>{{ entry.source }}</span>
                </template>
                <span v-else class="text-caution">{{ t('app.meta.no_source') }}</span>
                · {{ t(`market.confidence.${entry.confidence === 'medium' ? 'moderate' : entry.confidence}`) }}
            </p>
        </li>
    </ol>
</template>
