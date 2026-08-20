<script setup lang="ts">
import { computed, ref } from 'vue';
import { Head, Link, router } from '@inertiajs/vue3';
import PublicLayout from '@/Layouts/PublicLayout.vue';
import AppEmptyState from '@/Components/ui/AppEmptyState.vue';
import AppIcon from '@/Components/Icons/AppIcon.vue';
import AiAdvisorHero from '@/Components/Public/AiAdvisorHero.vue';
import AiDock from '@/Components/Public/AiDock.vue';
import HomeProjectMap from '@/Components/Public/HomeProjectMap.vue';
import MarketMetricCard from '@/Components/Public/MarketMetricCard.vue';
import ProjectSummaryCard from '@/Components/Public/ProjectSummaryCard.vue';
import AreaSummaryList from '@/Components/Public/AreaSummaryList.vue';
import { t } from '@/lib/i18n';
import { useLocale } from '@/Composables/useLocale';
import { useScrollReveal } from '@/Composables/useScrollReveal';
import type { IconName } from '@/Components/Icons/icons';

/*
 * The Erbil market homepage, recomposed to the approved Midnight Amber
 * reference (Wave 2B). SAME four props, same shapes, same server — the
 * reference supplied the visual grammar, never the content:
 *
 *   1. centred editorial hero over the atmosphere (the only h1);
 *   2. the REAL project search (GET /projects?q=, the existing published
 *      filter) styled as the reference search bar, plus glass chips that
 *      are the real flag-gated quick actions;
 *   3. the KPI strip — up to four REAL indices, never padded;
 *   4. the analytics grid (2fr+1fr): the Pricing Intelligence Map beside
 *      the real areas intelligence list;
 *   5. real projects in the three-card rhythm;
 *   6. the AI strip — a truthful advisor capability statement, never an
 *      invented insight — plus the floating dock, both only when the
 *      advisor flag is actually on;
 *   7. the lower duo: the market methodology statement and the confidence
 *      legend, both existing localized copy.
 *
 * §4's rule survives verbatim: no invented number, name, trend or insight;
 * every absence renders an honest empty state.
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
 * Public paths are written bare and localized here; `/account/portfolio`
 * stays unlocalized exactly as before (registered outside LocalizedRoutes).
 */
const actionHrefs = computed(() => ({
    advisor: localized('/advisor'),
    lifestyle: localized('/lifestyle'),
    portfolio: '/account/portfolio',
    map: localized('/map'),
    invest: localized('/invest'),
    offers: localized('/offers'),
    projects: localized('/projects'),
    market: localized('/market'),
    areas: localized('/areas'),
}));

const projectHrefs = computed(() =>
    Object.fromEntries(props.projects.items.map((project) => [project.slug, localized(`/projects/${project.slug}`)])),
);

const areaHrefs = computed(() =>
    Object.fromEntries(props.areas.items.map((area) => [area.slug, localized(`/areas/${area.slug}`)])),
);

/* At most FOUR indices in the KPI strip — sliced, never padded. */
const metricIndices = computed(() => props.market.indices.slice(0, 4));

const marketLinkable = computed(() => props.market.reason !== 'feature_disabled');

/*
 * The hero search bar submits the REAL published-projects filter: a GET to
 * /projects with the same `q` the index page already consumes. Nothing new
 * server-side; an empty query is simply the projects index.
 */
const searchQuery = ref('');

function submitSearch(): void {
    const q = searchQuery.value.trim();

    router.get(actionHrefs.value.projects, q === '' ? {} : { q });
}

/*
 * The chip row is the real flag-gated quick actions (§8.5 semantics moved
 * into the reference's chip grammar): filtered out when off, never dimmed.
 * The advisor deliberately is not a chip — it owns the strip and the dock.
 */
const quickChips = computed<Array<{ key: string; label: string; icon: IconName; href: string }>>(() =>
    (
        [
            { key: 'map', label: t('nav.public.map'), icon: 'map', href: actionHrefs.value.map, enabled: props.cta.map },
            { key: 'invest', label: t('nav.public.invest'), icon: 'invest', href: actionHrefs.value.invest, enabled: props.cta.invest },
            { key: 'offers', label: t('nav.public.offers'), icon: 'offers', href: actionHrefs.value.offers, enabled: props.cta.offers },
            { key: 'lifestyle', label: t('home.lifestyle_cta'), icon: 'spark', href: actionHrefs.value.lifestyle, enabled: props.cta.lifestyle },
            { key: 'portfolio', label: t('home.portfolio_cta'), icon: 'portfolio', href: actionHrefs.value.portfolio, enabled: props.cta.portfolio },
        ] as Array<{ key: string; label: string; icon: IconName; href: string; enabled: boolean }>
    )
        .filter((chip) => chip.enabled)
        .map(({ enabled: _enabled, ...chip }) => chip),
);

/* The confidence legend — the same real levels the KPI evidence rows use. */
const confidenceLevels = [
    { key: 'high', tone: 'bg-positive' },
    { key: 'moderate', tone: 'bg-ink-faint' },
    { key: 'low', tone: 'bg-caution' },
    { key: 'insufficient', tone: 'bg-negative' },
] as const;

// Scroll-reveal over the page's sections — JS-armed, reduced-motion safe.
const pageRoot = ref<HTMLElement | null>(null);
useScrollReveal(pageRoot);

/* One reference-width container: 1200px, 20/32px gutters. */
const box = 'mx-auto w-full max-w-[1200px] px-5 sm:px-8';
</script>

<template>
    <Head :title="t('home.hero_title')" />

    <PublicLayout chrome="home" palette="midnight">
        <div ref="pageRoot" class="pb-8">
            <!-- 1 · Hero: typography over the atmosphere. -->
            <div class="mh-reveal" :class="box">
                <AiAdvisorHero :enabled="cta.advisor" />

                <!-- 2 · The real search, in the reference's glass well. -->
                <form
                    role="search"
                    class="mh-searchbar mx-auto mt-8 flex w-full max-w-[640px] items-center gap-3 px-4 py-2 sm:px-5"
                    @submit.prevent="submitSearch"
                >
                    <AppIcon name="search" class="h-4 w-4 shrink-0 text-ink-faint" aria-hidden="true" />
                    <label class="sr-only" for="home-search">{{ t('home.search_label') }}</label>
                    <input
                        id="home-search"
                        v-model="searchQuery"
                        type="search"
                        name="q"
                        class="min-h-11 flex-1 text-sm"
                        :placeholder="t('home.search_placeholder')"
                        autocomplete="off"
                    >
                    <!-- Full 44px target — the touch floor is never overridden
                         down, even inside a compact bar. -->
                    <button type="submit" class="mh-lux-btn mh-lux-btn-primary shrink-0 !px-4 text-xs">
                        {{ t('home.search_action') }}
                    </button>
                </form>

                <!-- Real flag-gated destinations as the chip row. -->
                <div v-if="quickChips.length > 0" class="mt-4 flex flex-wrap justify-center gap-2.5">
                    <Link
                        v-for="chip in quickChips"
                        :key="chip.key"
                        :href="chip.href"
                        class="mh-chip-glass focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-accent"
                    >
                        <AppIcon :name="chip.icon" class="h-3.5 w-3.5 shrink-0 text-ink-faint" aria-hidden="true" />
                        {{ chip.label }}
                    </Link>
                </div>
            </div>

            <!-- 3 · KPI strip: real indices only, honest empty state. -->
            <section class="mh-reveal mt-14 lg:mt-16" :class="box" :aria-label="t('home.indices')">
                <div class="mb-3 flex flex-wrap items-baseline justify-between gap-x-6 gap-y-1 px-0.5">
                    <h2 class="mh-microlabel">{{ t('home.indices') }}</h2>
                    <p v-if="market.has_data && market.latest_period" class="text-xs text-ink-faint">
                        {{ t('market.period') }}: <span class="numeral">{{ market.latest_period }}</span>
                    </p>
                </div>

                <div v-if="!market.has_data" class="mh-lux-panel !rounded-glass">
                    <!-- The "gathering data" hint belongs to the no-data case
                         only; a switched-off module gathers nothing. Compact:
                         honest, but never the page's dominant panel. -->
                    <AppEmptyState
                        compact
                        :title="market.reason === 'feature_disabled'
                            ? t('home.feature_off')
                            : t('home.no_market_data')"
                        :description="market.reason === 'feature_disabled' ? undefined : t('market.public.none_hint')"
                    />
                </div>

                <div v-else class="grid gap-3.5 sm:grid-cols-2 lg:grid-cols-4">
                    <MarketMetricCard
                        v-for="index in metricIndices"
                        :key="index.key"
                        :index="index"
                        animated
                    />
                </div>
            </section>

            <!-- 4 · Analytics grid: the map beside the real area intelligence. -->
            <section class="mh-reveal mt-3.5" :class="box">
                <div class="grid gap-3.5" :class="(cta.invest || cta.map) ? 'lg:grid-cols-[2fr_1fr]' : ''">
                    <HomeProjectMap
                        v-if="cta.invest || cta.map"
                        :source="cta.invest ? 'invest' : 'map'"
                        :href="cta.invest ? actionHrefs.invest : actionHrefs.map"
                    />

                    <div class="mh-lux-panel !rounded-glass flex min-w-0 flex-col p-5 sm:p-6">
                        <div class="mb-2 flex items-center justify-between gap-4">
                            <h2 class="mh-microlabel">{{ t('home.areas') }}</h2>
                            <Link
                                v-if="areas.linkable"
                                :href="actionHrefs.areas"
                                class="mh-link-amber inline-flex min-h-11 items-center gap-1"
                            >
                                {{ t('nav.public.areas') }}
                                <AppIcon name="chevron" mirror class="h-3.5 w-3.5" aria-hidden="true" />
                            </Link>
                        </div>

                        <div v-if="!areas.has_data" class="flex flex-1 items-center justify-center">
                            <AppEmptyState compact :title="t('home.no_areas')" />
                        </div>

                        <AreaSummaryList
                            v-else
                            :areas="areas.items"
                            :linkable="areas.linkable"
                            :hrefs="areaHrefs"
                            bare
                        />
                    </div>
                </div>
            </section>

            <!-- 5 · Projects: the three-card rhythm, real records only. -->
            <section class="mh-reveal mt-10 lg:mt-12" :class="box" :aria-label="t('home.projects')">
                <div class="mb-3.5 flex items-center justify-between gap-4 px-0.5">
                    <h2 class="mh-microlabel">{{ t('home.projects') }}</h2>
                    <Link :href="actionHrefs.projects" class="mh-link-amber inline-flex min-h-11 items-center gap-1">
                        {{ t('nav.public.projects') }}
                        <AppIcon name="chevron" mirror class="h-3.5 w-3.5" aria-hidden="true" />
                    </Link>
                </div>

                <div v-if="!projects.has_data" class="mh-lux-panel !rounded-glass">
                    <AppEmptyState compact :title="t('home.no_projects')" />
                </div>

                <div v-else class="grid gap-3.5 sm:grid-cols-2 lg:grid-cols-3">
                    <ProjectSummaryCard
                        v-for="(project, index) in projects.items"
                        :key="project.slug"
                        :project="project"
                        :href="projectHrefs[project.slug]"
                        :variant="index % 3"
                    />
                </div>
            </section>

            <!-- 6 · The AI strip: a truthful capability statement and the way
                 into the real advisor — never an invented market claim. -->
            <section
                v-if="cta.advisor"
                class="mh-reveal mt-10 lg:mt-12"
                :class="box"
                :aria-label="t('home.insight_title')"
            >
                <div class="mh-ai-strip flex flex-wrap items-center gap-4 p-5 sm:p-6">
                    <span class="mh-spark flex h-11 w-11 shrink-0 items-center justify-center rounded-[13px]" aria-hidden="true">
                        <AppIcon name="spark" class="h-5 w-5" />
                    </span>
                    <p class="mh-ai-strip-text min-w-0 flex-1 basis-60 text-sm leading-relaxed">
                        <strong>{{ t('home.insight_title') }} —</strong>
                        {{ t('home.insight_body') }}
                    </p>
                    <Link
                        :href="actionHrefs.advisor"
                        class="mh-lux-btn mh-amber-grad mh-lift-hover w-full shrink-0 !rounded-[13px] font-medium
                               shadow-[0_12px_30px_rgba(221,134,54,0.38),inset_0_1px_0_rgba(255,255,255,0.35)]
                               sm:w-auto"
                    >
                        {{ t('home.advisor_cta') }}
                    </Link>
                </div>
            </section>

            <!-- 7 · Lower duo: methodology and the confidence legend — the
                 platform explaining its own honesty, in existing copy. -->
            <section class="mh-reveal mt-3.5" :class="box">
                <div class="grid gap-3.5 md:grid-cols-2">
                    <div class="mh-lux-panel !rounded-glass p-5 sm:p-6">
                        <h2 class="mh-microlabel mb-3">{{ t('market.public.title') }}</h2>
                        <p class="text-sm leading-relaxed text-ink-muted">{{ t('market.public.intro') }}</p>
                        <p class="mt-2 text-sm leading-relaxed text-ink-muted">{{ t('market.public.methodology_notice') }}</p>
                        <Link
                            v-if="marketLinkable"
                            :href="actionHrefs.market"
                            class="mh-link-amber mt-3 inline-flex min-h-11 items-center gap-1"
                        >
                            {{ t('nav.public.market') }}
                            <AppIcon name="chevron" mirror class="h-3.5 w-3.5" aria-hidden="true" />
                        </Link>
                    </div>

                    <div class="mh-lux-panel !rounded-glass p-5 sm:p-6">
                        <h2 class="mh-microlabel mb-3">{{ t('market.explanation.confidence') }}</h2>
                        <ul class="space-y-1">
                            <li
                                v-for="level in confidenceLevels"
                                :key="level.key"
                                class="flex items-center gap-2.5 py-1 text-sm text-ink-muted"
                            >
                                <span class="h-1.5 w-1.5 shrink-0 rounded-pill" :class="level.tone" aria-hidden="true" />
                                {{ t(`market.confidence.${level.key}`) }}
                            </li>
                        </ul>
                    </div>
                </div>
            </section>
        </div>

        <!-- Floating AI dock — only when the advisor actually exists. -->
        <AiDock v-if="cta.advisor" :href="actionHrefs.advisor" />
    </PublicLayout>
</template>
