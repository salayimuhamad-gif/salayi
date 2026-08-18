<script setup lang="ts">
import { t } from '@/lib/i18n';

interface ByType {
    type: string;
    mean: string;
    count: number;
    displayable: boolean;
    contributes_to_official: boolean;
    ai_generated: boolean;
    requires_provenance_label: boolean;
}

interface Category {
    category: string;
    group: string;
    inverted: boolean;
    official: {
        score: string | null;
        confidence: string;
        contributing_types: string[];
        sample_size: number;
    };
    by_type: ByType[];
}

defineProps<{ ratings: { categories: Category[]; official_count: number } }>();

/*
 * Some categories are better when lower — traffic, noise, construction
 * disruption. Drawing a low score on those in the "bad" colour would tell a
 * reader the opposite of the truth, so the tone follows the category's own
 * direction rather than the number.
 */
function tone(score: string, inverted: boolean): string {
    const value = Number(score);
    const good = inverted ? value <= 2 : value >= 4;
    const poor = inverted ? value >= 4 : value <= 2;
    return good ? 'text-positive' : poor ? 'text-negative' : 'text-ink';
}

function width(score: string): string {
    return `${Math.max(0, Math.min(100, (Number(score) / 5) * 100))}%`;
}
</script>

<template>
    <div class="space-y-5">
        <div v-for="category in ratings.categories" :key="category.category" class="space-y-2">
            <div class="flex items-baseline justify-between gap-3">
                <p class="text-sm font-medium text-ink">
                    {{ t(`projects.rating_categories.${category.category}`) }}
                    <span v-if="category.inverted" class="text-xs font-normal text-ink-faint">
                        {{ t('projects.ratings.lower_is_better') }}
                    </span>
                </p>

                <p
                    v-if="category.official.score" class="numeral text-sm font-semibold"
                    :class="tone(category.official.score, category.inverted)" dir="ltr"
                >
                    {{ category.official.score }}<span class="text-xs font-normal text-ink-faint">/5</span>
                </p>
            </div>

            <div v-if="category.official.score" class="h-1.5 overflow-hidden rounded-full bg-surface-sunken">
                <div class="h-full rounded-full bg-brand" :style="{ inlineSize: width(category.official.score) }" />
            </div>

            <!--
              Each provenance type is listed separately beneath the official
              score. Spec 13.2: an internal expert judgement and an aggregate of
              anonymous users are different claims, and collapsing them into one
              number would hide which one a reader is actually trusting.
            -->
            <ul class="flex flex-wrap gap-x-4 gap-y-1 text-xs text-ink-muted">
                <li v-for="entry in category.by_type" :key="entry.type" class="flex items-center gap-1.5">
                    <span :class="entry.requires_provenance_label ? 'text-caution' : 'text-ink-faint'">
                        {{ t(`projects.rating_types.${entry.type}`) }}
                    </span>
                    <span class="numeral" dir="ltr">{{ entry.mean }}</span>
                    <span class="numeral text-ink-faint" dir="ltr">(n={{ entry.count }})</span>
                    <span v-if="entry.ai_generated" class="text-caution">{{ t('app.meta.ai_generated') }}</span>
                </li>
            </ul>

            <p v-if="category.official.score" class="text-xs text-ink-faint">
                {{ t('market.explanation.confidence') }}:
                {{ t(`market.confidence.${category.official.confidence}`) }}
                · <span class="numeral">n={{ category.official.sample_size }}</span>
            </p>
        </div>
    </div>
</template>
