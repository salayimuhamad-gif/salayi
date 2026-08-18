<script setup lang="ts">
import { computed } from 'vue';
import { t } from '@/lib/i18n';

const props = defineProps<{
    explanation: {
        period: string;
        effective_date: string | null;
        sample_size: number;
        excluded_outliers: number;
        sources: string[];
        confidence: string;
        revision_status: string;
        revision_number: number;
        methodology_version: string;
        is_limited: boolean;
        warning: string | null;
    };
    requiresQualifier: boolean;
    priceType: string;
}>();

/*
 * Spec 15.3 lists eight things that must accompany every public index value.
 * They are rendered together rather than tucked behind a link, because a
 * disclosure a reader has to go looking for is one most readers never see —
 * and the ones who most need it are the least likely to look.
 */
const tone = computed(() => ({
    high: 'text-positive',
    moderate: 'text-ink-muted',
    low: 'text-caution',
    insufficient: 'text-negative',
}[props.explanation.confidence] ?? 'text-ink-muted'));
</script>

<template>
    <div class="space-y-2 text-xs">
        <!-- An asking-price index is not a market rate. Stated first, before
             the reader has absorbed the number as one. -->
        <p v-if="requiresQualifier" class="rounded-card bg-caution/10 px-3 py-2 text-caution">
            {{ t(`market.public.qualifier.${priceType}`) }}
        </p>

        <dl class="grid grid-cols-2 gap-x-4 gap-y-1.5 sm:grid-cols-3">
            <div>
                <dt class="text-ink-faint">{{ t('market.explanation.sample') }}</dt>
                <dd class="numeral text-ink">{{ explanation.sample_size }}</dd>
            </div>
            <div>
                <dt class="text-ink-faint">{{ t('market.explanation.confidence') }}</dt>
                <dd :class="tone">{{ t(`market.confidence.${explanation.confidence}`) }}</dd>
            </div>
            <div>
                <dt class="text-ink-faint">{{ t('market.explanation.methodology') }}</dt>
                <dd class="numeral text-ink" dir="ltr">{{ explanation.methodology_version }}</dd>
            </div>
            <div v-if="explanation.excluded_outliers > 0">
                <dt class="text-ink-faint">{{ t('market.explanation.excluded') }}</dt>
                <dd class="numeral text-ink">{{ explanation.excluded_outliers }}</dd>
            </div>
            <div v-if="explanation.effective_date">
                <dt class="text-ink-faint">{{ t('market.explanation.effective') }}</dt>
                <dd class="numeral text-ink" dir="ltr">{{ explanation.effective_date }}</dd>
            </div>
            <!-- A revised figure says so. A published number that quietly
                 changed is indistinguishable from one that was always
                 different. -->
            <div v-if="explanation.revision_status !== 'initial'">
                <dt class="text-ink-faint">{{ t('market.explanation.revision') }}</dt>
                <dd class="text-caution">
                    {{ t('market.explanation.revised') }}
                    <span class="numeral">{{ explanation.revision_number }}</span>
                </dd>
            </div>
        </dl>

        <p v-if="explanation.sources.length > 0" class="text-ink-faint">
            {{ t('app.meta.source') }}: {{ explanation.sources.join(', ') }}
        </p>

        <p v-if="explanation.is_limited && explanation.warning" class="text-caution">
            {{ t(`market.warnings.${explanation.warning}`) }}
        </p>
    </div>
</template>
