<script setup lang="ts">
import { ref } from 'vue';
import { Head, Link } from '@inertiajs/vue3';
import PublicLayout from '@/Layouts/PublicLayout.vue';
import AppCard from '@/Components/ui/AppCard.vue';
import AppAlert from '@/Components/ui/AppAlert.vue';
import AppEmptyState from '@/Components/ui/AppEmptyState.vue';
import { t, formatNumber } from '@/lib/i18n';
import { useLocale } from '@/Composables/useLocale';

/*
 * Bare public paths are the *default-locale* (Sorani) URLs under
 * `prefix_except_default`. Emitting them from an Arabic or English page threw
 * the visitor back into Sorani, so each one is wrapped by `localized()`.
 */
const { localized } = useLocale();

/*
 * Lifestyle match results (File two §8).
 *
 * §8 requires the result be EXPLAINABLE and not decided by a language model.
 * That shapes this page more than any styling decision: the score is never
 * shown alone. Every match can be expanded to the eight weighted components
 * that produced it, each with the reason LifestyleMatcher recorded, and the
 * measured distances from the household's own pinned locations.
 *
 * The weighting is printed on the page too. A household is entitled to know
 * that budget is worth 25 and family proximity 8 before they trust the
 * ordering — a score whose weighting is hidden is not explainable, whatever
 * its breakdown says.
 */
interface Component {
    score: number;
    weight: number;
    weighted: string;
    reason: string;
}

interface Match {
    project: {
        id: number;
        slug: string;
        name: string;
        area: string | null;
        property_type: string | null;
        price: string | null;
    };
    score: number;
    components: Record<string, Component>;
    disqualified: boolean;
    disqualification_reasons: string[];
    confidence: string;
    distances: Record<string, number>;
}

defineProps<{
    has_profile: boolean;
    matches: Match[];
    weights: Record<string, number>;
}>();

const expanded = ref<number | null>(null);

function toggle(id: number): void {
    expanded.value = expanded.value === id ? null : id;
}

function humaniseDistance(metres: number): string {
    if (metres < 1000) return `${Math.round(metres / 10) * 10} m`;

    return `${(metres / 1000).toFixed(metres < 10000 ? 1 : 0)} km`;
}

const scoreTone = (score: number): string =>
    score >= 75 ? 'text-positive' : score >= 50 ? 'text-ink' : 'text-caution';
</script>

<template>
    <Head :title="t('advisor.lifestyle.results_title')" />

    <PublicLayout>
        <article class="mx-auto max-w-3xl space-y-6">
            <header>
                <h1 class="font-display text-2xl font-bold text-ink">
                    {{ t('advisor.lifestyle.results_title') }}
                </h1>
            </header>

            <AppEmptyState
                v-if="!has_profile"
                :title="t('advisor.lifestyle.no_profile')"
                :description="t('advisor.lifestyle.title')"
            />

            <template v-else>
                <AppEmptyState
                    v-if="matches.length === 0"
                    :title="t('app.states.empty')"
                    :description="t('advisor.lifestyle.no_profile')"
                />

                <AppCard
                    v-for="match in matches"
                    v-else
                    :key="match.project.id"
                    :class="match.disqualified ? 'opacity-70' : ''"
                >
                    <div class="flex flex-wrap items-baseline justify-between gap-3">
                        <div class="min-w-0">
                            <Link
                                :href="localized(`/projects/${match.project.slug}`)"
                                class="font-display text-lg font-semibold text-ink underline-offset-2 hover:underline"
                            >
                                {{ match.project.name }}
                            </Link>
                            <p v-if="match.project.area" class="text-xs text-ink-muted">
                                {{ match.project.area }}
                            </p>
                        </div>

                        <p class="numeral font-display text-2xl font-semibold" :class="scoreTone(match.score)" dir="ltr">
                            {{ formatNumber(match.score) }}
                        </p>
                    </div>

                    <!-- A hard requirement was not met. This is not a weak
                         score, and saying so plainly matters: "42% match" on a
                         property outside a stated requirement invites the
                         household to consider it anyway. -->
                    <AppAlert v-if="match.disqualified" variant="warning" class="mt-3">
                        {{ t('advisor.lifestyle.disqualified') }}
                    </AppAlert>

                    <!-- Measured distances from the household's own pins. -->
                    <ul v-if="Object.keys(match.distances).length > 0" class="mt-3 flex flex-wrap gap-x-4 gap-y-1">
                        <li
                            v-for="(metres, kind) in match.distances"
                            :key="kind"
                            class="text-xs text-ink-muted"
                        >
                            {{ t(`advisor.priority_kinds.${kind}`) }}:
                            <span class="numeral text-ink" dir="ltr">{{ humaniseDistance(metres) }}</span>
                        </li>
                    </ul>

                    <button
                        type="button"
                        class="mt-3 text-xs text-brand underline-offset-2 hover:underline
                               focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-accent"
                        :aria-expanded="expanded === match.project.id"
                        @click="toggle(match.project.id)"
                    >
                        {{ t('advisor.lifestyle.why') }}
                    </button>

                    <!-- The breakdown that makes the number defensible. -->
                    <dl v-if="expanded === match.project.id" class="mt-3 space-y-2 border-t border-line pt-3">
                        <div
                            v-for="(component, key) in match.components"
                            :key="key"
                            class="flex flex-wrap items-baseline justify-between gap-2"
                        >
                            <dt class="text-xs text-ink">
                                {{ key }}
                                <span class="numeral text-ink-faint" dir="ltr">×{{ component.weight }}</span>
                            </dt>
                            <dd class="flex items-baseline gap-2">
                                <span class="text-xs text-ink-muted">{{ component.reason }}</span>
                                <span class="numeral text-xs text-ink" dir="ltr">{{ component.weighted }}</span>
                            </dd>
                        </div>
                    </dl>
                </AppCard>

                <!-- §8 explainability: the weighting itself is public. -->
                <AppCard :title="t('advisor.lifestyle.weights')">
                    <ul class="flex flex-wrap gap-x-5 gap-y-1.5">
                        <li v-for="(weight, key) in weights" :key="key" class="text-xs text-ink-muted">
                            {{ key }}
                            <span class="numeral text-ink" dir="ltr">{{ formatNumber(weight) }}</span>
                        </li>
                    </ul>
                </AppCard>
            </template>
        </article>
    </PublicLayout>
</template>
