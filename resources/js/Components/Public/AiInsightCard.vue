<script setup lang="ts">
import { computed } from 'vue';
import ConfidenceMeter from '@/Components/Public/ConfidenceMeter.vue';

/*
 * The AI insight card (redesign §13.2): a 2px teal start rule, a status dot
 * with an ADJACENT TEXT meaning (never colour alone), an optional confidence
 * meter and optional provenance chips.
 *
 * Status honesty is structural: `idle` renders a neutral dot and no live
 * claim — the homepage receives no ai_available prop, so its hero panel is
 * ALWAYS idle. `live` may pulse; `degraded` is caution-toned and static;
 * `analyzing` carries the scanning shimmer ONLY while real work is pending.
 */
const props = withDefaults(defineProps<{
    title: string;
    status?: 'live' | 'degraded' | 'idle' | 'analyzing';
    statusLabel?: string;
    confidence?: number;
    confidenceLabel?: string;
    provenance?: string[];
}>(), { status: 'idle', statusLabel: undefined, confidence: undefined, confidenceLabel: undefined, provenance: () => [] });

const dotClass = computed(() => ({
    live: 'bg-ai mh-status-dot--live',
    analyzing: 'bg-ai',
    degraded: 'bg-caution-bright',
    idle: 'bg-line-strong',
})[props.status]);
</script>

<template>
    <article
        class="relative overflow-hidden rounded-card border border-line bg-surface-raised p-4 shadow-hairline"
        :class="{ 'mh-ai-shimmer': status === 'analyzing' }"
    >
        <span class="absolute inset-y-0 start-0 w-0.5 bg-ai" aria-hidden="true"></span>

        <header class="flex items-center gap-2">
            <span class="inline-block h-2 w-2 shrink-0 rounded-pill" :class="dotClass" aria-hidden="true"></span>
            <h3 class="min-w-0 text-sm font-semibold text-ink">{{ title }}</h3>
            <span v-if="statusLabel" class="ms-auto shrink-0 text-xs text-ink-faint">{{ statusLabel }}</span>
        </header>

        <div class="mt-2 text-sm text-ink-muted">
            <slot />
        </div>

        <ConfidenceMeter
            v-if="confidence !== undefined"
            :value="confidence"
            :label="confidenceLabel"
            class="mt-3"
        />

        <ul v-if="provenance.length > 0" class="mt-3 flex flex-wrap gap-1.5">
            <li v-for="chip in provenance" :key="chip" class="mh-provenance">{{ chip }}</li>
        </ul>
    </article>
</template>
