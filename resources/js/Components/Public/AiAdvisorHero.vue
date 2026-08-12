<script setup lang="ts">
import { ref } from 'vue';
import { Link } from '@inertiajs/vue3';
import AppIcon from '@/Components/Icons/AppIcon.vue';
import AppButton from '@/Components/ui/AppButton.vue';
import AiAvatar from '@/Components/Public/AiAvatar.vue';
import AiInsightCard from '@/Components/Public/AiInsightCard.vue';
import SkylineErbil from '@/Components/Public/SkylineErbil.vue';
import { t } from '@/lib/i18n';
import { useLocale } from '@/Composables/useLocale';
import contourUrl from '@/Assets/erbil-contour.svg?url';

/*
 * The homepage's primary section (§8.1, redesigned per master prompt §5.2).
 *
 * The brief for this block is one sentence in §8.1: **AI is central to the
 * product, but it does not invent information.** Both halves are structural
 * here, not tonal.
 *
 * CENTRAL — first on the page, the only place champagne is used as a fill,
 * and the carrier of the two identity motifs (contour texture, skyline
 * baseline — both original inline/CSS art, never photography, never <img>).
 *
 * DOES NOT INVENT — the example questions are exactly that: examples,
 * rendered as links to the real advisor, never as answered messages, never
 * auto-submitting. The search field GETs the REAL `/projects` index filter
 * (`?q=`, verified server-side against ProjectProfileController) — no
 * fabricated search backend. The insight panel renders `status="idle"`
 * ALWAYS: the homepage receives no ai_available prop, so no live-status
 * claim may be made here (§5.2).
 *
 * When `enabled` is false the section stays, the call to action does not:
 * the unavailable message replaces it (role="status" — the acceptance suite
 * pins this), and the example questions become plain text rather than links
 * into a route that would 404 behind its feature middleware.
 */
defineProps<{
    enabled: boolean;
    href: string;
}>();

const { localized } = useLocale();

const q = ref('');

// Honest routing: the query lands on the real projects index GET filter.
function searchAction(): string {
    return localized('/projects');
}
</script>

<template>
    <section
        class="mh-lux-panel mh-lux-gilded mh-contour-bg relative overflow-hidden"
        :style="{ '--mh-contour-url': `url('${contourUrl}')` }"
    >
        <div class="relative z-10 flex flex-col gap-8 p-6 pb-14 sm:p-8 sm:pb-16 lg:flex-row lg:items-center lg:gap-10 lg:pb-18">
            <div class="min-w-0 flex-1">
                <p class="mh-eyebrow flex items-center">
                    <span class="inline-block h-px w-6 bg-accent me-3" aria-hidden="true"></span>
                    {{ t('home.eyebrow') }}
                </p>

                <h1 class="mt-3 font-display text-3xl font-bold leading-tight text-ink sm:text-4xl lg:text-5xl">
                    {{ t('home.hero_title') }}
                </h1>

                <p class="mt-3 max-w-prose text-ink-muted md:text-lg">{{ t('home.hero_sub') }}</p>

                <!-- The honesty statement §8.1 asks for, in the visitor's own
                     language rather than as a footnote. -->
                <p class="mt-2 max-w-prose text-sm text-ink-faint">{{ t('home.advisor_lede') }}</p>

                <!-- Market search entry: a plain GET against the real
                     `/projects` index filter. -->
                <form :action="searchAction()" method="get" class="mt-6 flex w-full max-w-lg items-center gap-2">
                    <label class="sr-only" for="hero-q">{{ t('home.search_label') }}</label>
                    <input
                        id="hero-q"
                        v-model="q"
                        name="q"
                        type="search"
                        :placeholder="t('home.search_placeholder')"
                        class="min-h-11 w-full rounded-field border border-line-strong bg-surface px-4 text-base
                               text-ink placeholder:text-ink-faint focus:outline-none focus-visible:ring-2
                               focus-visible:ring-accent"
                    />
                    <AppButton type="submit" variant="primary">
                        <AppIcon name="search" class="h-4 w-4" aria-hidden="true" />
                        <span class="hidden sm:inline">{{ t('home.search_action') }}</span>
                        <span class="sr-only sm:hidden">{{ t('home.search_action') }}</span>
                    </AppButton>
                </form>

                <div class="mt-5">
                    <AppButton v-if="enabled" :href="href" variant="ai" size="lg" class="w-full sm:w-auto">
                        {{ t('home.advisor_cta') }}
                        <AppIcon name="arrow-end" mirror class="h-4 w-4" aria-hidden="true" />
                    </AppButton>

                    <p v-else class="mh-ai-notice" role="status">
                        <span aria-hidden="true">!</span>
                        <span>{{ t('home.advisor_disabled') }}</span>
                    </p>
                </div>

                <div class="mt-6">
                    <p class="mh-eyebrow">{{ t('home.examples') }}</p>

                    <!-- Snap-scroll pill row on small screens, wrapping on md+. -->
                    <ul class="mt-3 flex snap-x snap-mandatory gap-2 overflow-x-auto pb-1 md:flex-wrap md:overflow-visible">
                        <li class="shrink-0 snap-start">
                            <Link v-if="enabled" :href="href" class="mh-lux-example">
                                {{ t('home.example_budget') }}
                            </Link>
                            <span v-else class="mh-lux-chip">{{ t('home.example_budget') }}</span>
                        </li>
                        <li class="shrink-0 snap-start">
                            <Link v-if="enabled" :href="href" class="mh-lux-example">
                                {{ t('home.example_compare') }}
                            </Link>
                            <span v-else class="mh-lux-chip">{{ t('home.example_compare') }}</span>
                        </li>
                        <li class="shrink-0 snap-start">
                            <Link v-if="enabled" :href="href" class="mh-lux-example">
                                {{ t('home.example_organise') }}
                            </Link>
                            <span v-else class="mh-lux-chip">{{ t('home.example_organise') }}</span>
                        </li>
                    </ul>
                </div>
            </div>

            <div class="hidden shrink-0 flex-col items-center gap-4 lg:flex lg:w-72">
                <AiAvatar class="h-40 w-40 xl:h-48 xl:w-48" />
                <!-- status="idle" is mandatory: no ai_available prop reaches
                     the homepage, so no live-status claim is rendered here. -->
                <AiInsightCard :title="t('home.insight_title')" status="idle" class="w-full">
                    {{ t('home.insight_body') }}
                </AiInsightCard>
            </div>

            <!-- The avatar keeps a compact presence above the copy on tablet. -->
            <div class="absolute end-6 top-6 lg:hidden" aria-hidden="true">
                <AiAvatar class="h-16 w-16 opacity-90 sm:h-20 sm:w-20" :glow="false" />
            </div>
        </div>

        <!-- Skyline anchored to the hero baseline: inline SVG paths, ink at
             6% — identity, never photography. -->
        <div class="pointer-events-none absolute inset-x-0 bottom-0 h-10 text-ink opacity-[.06] sm:h-14" aria-hidden="true">
            <SkylineErbil class="h-full w-full" />
        </div>
    </section>
</template>
