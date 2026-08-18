<script setup lang="ts">
import { computed, ref } from 'vue';
import { Head, Link } from '@inertiajs/vue3';
import PublicLayout from '@/Layouts/PublicLayout.vue';
import AppEmptyState from '@/Components/ui/AppEmptyState.vue';
import AppIcon from '@/Components/Icons/AppIcon.vue';
import AiAdvisorHero from '@/Components/Public/AiAdvisorHero.vue';
import HomeProjectMap from '@/Components/Public/HomeProjectMap.vue';
import PublicQuickActions from '@/Components/Public/PublicQuickActions.vue';
import MarketMetricCard from '@/Components/Public/MarketMetricCard.vue';
import ProjectSummaryCard from '@/Components/Public/ProjectSummaryCard.vue';
import AreaSummaryList from '@/Components/Public/AreaSummaryList.vue';
import SectionHeading from '@/Components/Public/SectionHeading.vue';
import { t } from '@/lib/i18n';
import { useLocale } from '@/Composables/useLocale';
import { useScrollReveal } from '@/Composables/useScrollReveal';

/*
 * The Erbil market homepage (File one §4), re-architected.
 *
 * §4 ends: "Do not display invented or demo market data." That is why every
 * section below is either real published data or an explicit empty state, and
 * why a fresh installation's homepage looks bare rather than plausible.
 *
 * THE RE-ARCHITECTURE CHANGED THE STRUCTURE AND NOT ONE PROP. Same four
 * props, same shapes, same server. The page now runs on the layout's 'home'
 * chrome — no left rail (the horizontal topbar nav is the desktop
 * navigation), no inherited max-w-6xl box — and owns its own containers:
 *
 *   1. compact hero (h1 + sub + ONE advisor CTA + the AI orb);
 *   2. the Pricing Intelligence Map, filters directly above it, sized to the
 *      first desktop viewport — the page's dominant element;
 *   3. up to four market metric cards directly under the map;
 *   4. projects, areas, pathways;
 *   5. the charcoal market-intelligence band.
 *
 * The wide sections (hero, map, metrics) share one max-w-screen-2xl
 * container; the editorial sections below keep max-w-7xl. What did NOT
 * move: the empty states, the confidence and sample figures, the absence of
 * project prices and photography on cards, and the rule that a call to
 * action exists only where its feature does.
 */
const { localized } = useLocale();

interface Index {
    key: string;
    name: string | null;
    price_type: string | null;
    basis: string | null;
    value: string;
    change_percent: string | null;
    period: string;
    currency: string;
    sample_size: number | null;
    confidence: string;
    is_limited: boolean;
}

const props = defineProps<{
    market: {
        has_data: boolean;
        reason: string | null;
        indices: Index[];
        latest_period?: string | null;
    };
    projects: { has_data: boolean; items: Array<{ slug: string; name: string; area: string | null; project_type: string | null; construction_status: string | null }> };
    areas: { has_data: boolean; linkable: boolean; items: Array<{ slug: string; name: string; type: string | null; project_count: number }> };
    cta: { advisor: boolean; lifestyle: boolean; portfolio: boolean; map: boolean; invest: boolean; offers: boolean };
}>();

/*
 * Public paths are written bare and localized here. A literal `/projects/x` is
 * the Sorani URL under `prefix_except_default`; emitted from an Arabic page it
 * silently switched the visitor's language.
 *
 * `/account/portfolio` is deliberately NOT localized, exactly as before: the
 * portfolio routes are registered under an `account/portfolio` prefix rather
 * than through LocalizedRoutes, so prefixing it would point at a path that does
 * not exist.
 */
const actionHrefs = computed(() => ({
    advisor: localized('/advisor'),
    lifestyle: localized('/lifestyle'),
    portfolio: '/account/portfolio',
    map: localized('/map'),
    invest: localized('/invest'),
    offers: localized('/offers'),
}));

const projectHrefs = computed(() =>
    Object.fromEntries(props.projects.items.map((project) => [project.slug, localized(`/projects/${project.slug}`)])),
);

const areaHrefs = computed(() =>
    Object.fromEntries(props.areas.items.map((area) => [area.slug, localized(`/areas/${area.slug}`)])),
);

/*
 * The metric strip under the map shows AT MOST FOUR indices (§5's "3–4
 * market metric cards") — the same server rows, sliced, never padded: two
 * real indices render two cards, and the full list lives on /market.
 */
const metricIndices = computed(() => props.market.indices.slice(0, 4));

/*
 * The charcoal market-intelligence band repeats up to three of the SAME
 * indices — reuse, never new data — and links to /market only when the
 * feature is actually on (`reason` tells us when it is off, and the backend
 * 404s the route then). The /market pending-list is NOT mirrored here: the
 * homepage receives no `unavailable{}` prop, and declaring specific absences
 * from a hardcoded list could contradict the server the day it ships one of
 * them. The band states the methodology and points at /market for the full
 * declaration.
 */
const bandIndices = computed(() => props.market.indices.slice(0, 3));
const marketLinkable = computed(() => props.market.reason !== 'feature_disabled');

// Scroll-reveal over the page's sections — JS-armed, reduced-motion safe.
const pageRoot = ref<HTMLElement | null>(null);
useScrollReveal(pageRoot);

/*
 * Shared containers: the wide band for the map-led top of the page, the
 * editorial measure below it.
 */
const wideBox = 'mx-auto w-full max-w-screen-2xl px-4 lg:px-8';
const proseBox = 'mx-auto w-full max-w-7xl px-4 lg:px-8';

/*
 * Mobile snap-carousel classes for card rails (§6.2): a scroll row below sm,
 * the grid above it. CSS-only — no carousel library, edge behavior from
 * scroll-snap alone.
 */
const railClass = 'max-sm:flex max-sm:snap-x max-sm:snap-mandatory max-sm:gap-3 max-sm:overflow-x-auto '
    + 'max-sm:pb-2 grid gap-4 sm:grid-cols-2 lg:grid-cols-3';
const metricRailClass = 'max-sm:flex max-sm:snap-x max-sm:snap-mandatory max-sm:gap-3 max-sm:overflow-x-auto '
    + 'max-sm:pb-2 grid gap-4 sm:grid-cols-2 lg:grid-cols-4';
const railCardClass = 'max-sm:w-[78vw] max-sm:shrink-0 max-sm:snap-start';
</script>

<template>
    <Head :title="t('home.hero_title')" />

    <PublicLayout chrome="home">
        <div ref="pageRoot" class="space-y-12 md:space-y-16">
            <!-- 1 · Compact hero: the only h1, one CTA, the orb. Its own
                 vertical padding keeps the map inside the first viewport. -->
            <div :class="wideBox">
                <AiAdvisorHero :enabled="cta.advisor" :href="actionHrefs.advisor" />

                <!-- 2 · The Pricing Intelligence Map — the page's dominant
                     element, directly under the compact hero (§5). Backed by
                     whichever public map surface is enabled — invest first
                     (its rows carry the real prices and trends), the explorer
                     otherwise, honestly unpriced. Both flags off: the section
                     is absent, never a dead link. -->
                <HomeProjectMap
                    v-if="cta.invest || cta.map"
                    :source="cta.invest ? 'invest' : 'map'"
                    :href="cta.invest ? actionHrefs.invest : actionHrefs.map"
                />
            </div>

            <!-- 3 · Market metric cards, directly under the map (§5): at most
                 four, same real indices, explicit empty state otherwise. -->
            <section class="mh-reveal" :class="wideBox">
                <div class="mb-3 flex flex-wrap items-baseline justify-between gap-x-6 gap-y-1">
                    <h2 class="font-display text-xl font-semibold text-ink sm:text-2xl">{{ t('home.indices') }}</h2>
                    <p v-if="market.has_data && market.latest_period" class="text-sm text-ink-muted">
                        {{ t('market.period') }}: <span class="numeral">{{ market.latest_period }}</span>
                    </p>
                </div>

                <div v-if="!market.has_data" class="mh-lux-panel">
                    <AppEmptyState
                        :title="market.reason === 'feature_disabled'
                            ? t('home.feature_off')
                            : t('home.no_market_data')"
                        :description="t('home.hero_sub')"
                    />
                </div>

                <div v-else :class="metricRailClass">
                    <MarketMetricCard
                        v-for="index in metricIndices"
                        :key="index.key"
                        :index="index"
                        animated
                        :class="railCardClass"
                    />
                </div>
            </section>

            <!-- 4 · Projects. Deliberately no price: a card price would be an
                 unqualified number, which is what the indices above take care
                 to avoid being. No photography either. -->
            <section class="mh-reveal" :class="proseBox">
                <SectionHeading :eyebrow="t('nav.public.projects')" :title="t('home.projects')" />

                <div v-if="!projects.has_data" class="mh-lux-panel">
                    <AppEmptyState :title="t('home.no_projects')" />
                </div>

                <div v-else :class="railClass">
                    <ProjectSummaryCard
                        v-for="project in projects.items"
                        :key="project.slug"
                        :project="project"
                        :href="projectHrefs[project.slug]"
                        :class="railCardClass"
                    />
                </div>
            </section>

            <!-- 5 · Areas that actually have something to show. Links only
                 when the public Area profile is switched on. -->
            <section class="mh-reveal" :class="proseBox">
                <SectionHeading :eyebrow="t('nav.public.areas')" :title="t('home.areas')" />

                <div v-if="!areas.has_data" class="mh-lux-panel">
                    <AppEmptyState :title="t('home.no_areas')" />
                </div>

                <AreaSummaryList
                    v-else
                    :areas="areas.items"
                    :linkable="areas.linkable"
                    :hrefs="areaHrefs"
                />
            </section>

            <!-- 6 · Pathways: each card exists only where its feature does. -->
            <section
                v-if="cta.advisor || cta.lifestyle || cta.portfolio || cta.map || cta.offers"
                class="mh-reveal"
                :class="proseBox"
            >
                <SectionHeading :title="t('home.quick_actions')" />

                <PublicQuickActions :cta="cta" :hrefs="actionHrefs" />
            </section>

            <!-- 7 · Market-intelligence band: the page's one dark composition
                 (the footer is light now). Same indices as section 3 — reuse,
                 no new data — the methodology statement, and the way into
                 /market, link only when the feature is on. Full-bleed under
                 the home chrome, so no negative-margin escapes. -->
            <section class="mh-dark-band mh-reveal px-4 py-12 lg:px-8 lg:py-16">
                <div class="mx-auto max-w-7xl">
                    <p class="mh-eyebrow flex items-center">
                        <span class="inline-block h-px w-6 bg-accent me-3" aria-hidden="true"></span>
                        {{ t('market.public.title') }}
                    </p>
                    <h2 class="mt-3 max-w-2xl font-display text-2xl font-semibold text-ink md:text-3xl">
                        {{ t('market.public.intro') }}
                    </h2>
                    <p class="mt-3 max-w-2xl text-sm text-ink-muted">
                        {{ t('market.public.methodology_notice') }}
                    </p>

                    <div v-if="market.has_data && bandIndices.length > 0" class="mt-8 grid gap-4 md:grid-cols-3">
                        <MarketMetricCard
                            v-for="index in bandIndices"
                            :key="`band-${index.key}`"
                            :index="index"
                        />
                    </div>

                    <div v-if="marketLinkable" class="mt-8">
                        <Link
                            :href="localized('/market')"
                            class="inline-flex min-h-11 items-center gap-2 rounded-card border border-line px-4
                                   text-sm font-medium text-ink transition-colors duration-200 ease-calm
                                   hover:border-accent"
                        >
                            {{ t('nav.public.market') }}
                            <AppIcon name="arrow-end" mirror class="h-4 w-4" aria-hidden="true" />
                        </Link>
                    </div>
                </div>
            </section>
        </div>
    </PublicLayout>
</template>
