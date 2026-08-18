<script setup lang="ts">
import { computed } from 'vue';
import { t } from '@/lib/i18n';

const props = defineProps<{
    source: string | null;
    verifiedAt: string | null;
    confidence: string | null;
    ageDays: number | null;
}>();

/*
 * Spec 5: every public fact carries a source and a freshness marker.
 *
 * Freshness is expressed as an age, not only a date. "Verified 2026-01-15"
 * makes a reader do arithmetic before they can judge it; "verified 190 days
 * ago" is the judgement itself, and the tone shifts as it decays so a stale
 * figure looks stale rather than merely dated.
 */
const tone = computed(() => {
    if (props.ageDays === null) return 'text-ink-faint';
    if (props.ageDays > 365) return 'text-negative';
    if (props.ageDays > 180) return 'text-caution';
    return 'text-ink-muted';
});

const freshness = computed(() => {
    if (props.ageDays === null) return t('app.meta.never_verified');
    if (props.ageDays > 365) return t('projects.freshness.over_a_year');
    if (props.ageDays > 180) return t('projects.freshness.over_six_months');
    if (props.ageDays > 30) return t('projects.freshness.months');
    return t('projects.freshness.recent');
});
</script>

<template>
    <div class="mh-provenance flex-wrap" :class="tone">
        <span v-if="source">{{ t('app.meta.source') }}: {{ source }}</span>
        <span v-else class="text-caution">{{ t('app.meta.no_source') }}</span>

        <span aria-hidden="true">·</span>

        <span>
            {{ freshness }}
            <time v-if="verifiedAt" :datetime="verifiedAt" class="numeral">({{ verifiedAt }})</time>
        </span>

        <template v-if="confidence">
            <span aria-hidden="true">·</span>
            <span>{{ t('app.meta.confidence') }}: {{ t(`market.confidence.${confidence === 'medium' ? 'moderate' : confidence}`) }}</span>
        </template>
    </div>
</template>
