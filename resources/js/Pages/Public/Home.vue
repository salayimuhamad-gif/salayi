<script setup lang="ts">
import { computed } from 'vue';
import { Head } from '@inertiajs/vue3';
import PublicLayout from '@/Layouts/PublicLayout.vue';
import AppEmptyState from '@/Components/ui/AppEmptyState.vue';
import AiAdvisorHero from '@/Components/Public/AiAdvisorHero.vue';
import InvestMapTeaser from '@/Components/Public/InvestMapTeaser.vue';
import PublicQuickActions from '@/Components/Public/PublicQuickActions.vue';
import MarketMetricCard from '@/Components/Public/MarketMetricCard.vue';
import ProjectSummaryCard from '@/Components/Public/ProjectSummaryCard.vue';
import AreaSummaryList from '@/Components/Public/AreaSummaryList.vue';
import { t } from '@/lib/i18n';
import { useLocale } from '@/Composables/useLocale';

/*
 * The Erbil market homepage (File one §4).
 *
 * §4 ends: "Do not display invented or demo market data." That is why every
 * section below is either real published data or an explicit empty state, and
 * why a fresh installation's homepage looks bare rather than plausible.
 *
 * The bare version is the correct one. A homepage showing invented Erbil prices
 * is indistinguishable to a visitor from one showing real prices, and the first
 * time somebody acts on a fabricated figure the platform's claim to be market
 * intelligence is finished.
 *
 * Every index carries its sample size and confidence beside it, for the same
 * reason the portfolio shows a range beside a midpoint: an index built on
 * eleven observations and one built on eleven hundred read identically as a
 * number, and only one of them should move a decision.
 */
/*
 * THE REDESIGN (§8) CHANGED THE PRESENTATION AND NOT ONE PROP.
 *
 * Same four props, same shapes, same server. What moved:
 *
 *   - The advisor became the page's primary section rather than one button in
 *     a row of three, because §8.1 asks for it and because it is the thing this
 *     product is.
 *   - `cta.map` and `cta.offers` are now used. They were already arriving from
 *     HomeController and the previous page ignored them, so two switched-on
 *     modules had no route out of the homepage. Rendering them is reading a
 *     real flag, not inventing a feature.
 *   - Each section's markup moved into a component so the page reads as a
 *     composition rather than four hundred lines of nesting.
 *
 * What did NOT move: the empty states, the confidence and sample figures, the
 * absence of project prices and photography, and the rule that a call to action
 * exists only where its feature does.
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
</script>

<template>
    <Head :title="t('home.hero_title')" />

    <PublicLayout>
        <div class="space-y-10">
            <!-- §8.1: the primary section, and the only h1 on the page. -->
            <AiAdvisorHero :enabled="cta.advisor" :href="actionHrefs.advisor" />

            <section v-if="cta.advisor || cta.lifestyle || cta.portfolio || cta.map || cta.offers">
                <h2 class="mb-4 font-display text-lg font-semibold text-ink">
                    {{ t('home.quick_actions') }}
                </h2>

                <PublicQuickActions :cta="cta" :hrefs="actionHrefs" />
            </section>

            <!-- The Investment Map preview: a flag-gated invitation, not a
                 second map. The real experience (and its heavy chunk) lives
                 behind the click. -->
            <InvestMapTeaser v-if="cta.invest" :href="actionHrefs.invest" />

            <!-- Market indices -->
            <section>
                <h2 class="mb-4 font-display text-xl font-semibold text-ink">{{ t('home.indices') }}</h2>

                <div v-if="!market.has_data" class="mh-lux-panel">
                    <AppEmptyState
                        :title="market.reason === 'feature_disabled'
                            ? t('home.feature_off')
                            : t('home.no_market_data')"
                        :description="t('home.hero_sub')"
                    />
                </div>

                <div v-else class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                    <MarketMetricCard v-for="index in market.indices" :key="index.key" :index="index" />
                </div>
            </section>

            <!-- Projects. Deliberately no price: a card price would be an
                 unqualified number, which is what the indices above take care to
                 avoid being. No photography either — see ProjectSummaryCard. -->
            <section>
                <h2 class="mb-4 font-display text-xl font-semibold text-ink">{{ t('home.projects') }}</h2>

                <div v-if="!projects.has_data" class="mh-lux-panel">
                    <AppEmptyState :title="t('home.no_projects')" />
                </div>

                <div v-else class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                    <ProjectSummaryCard
                        v-for="project in projects.items"
                        :key="project.slug"
                        :project="project"
                        :href="projectHrefs[project.slug]"
                    />
                </div>
            </section>

            <!-- Areas that actually have something to show. -->
            <section>
                <h2 class="mb-4 font-display text-xl font-semibold text-ink">{{ t('home.areas') }}</h2>

                <div v-if="!areas.has_data" class="mh-lux-panel">
                    <AppEmptyState :title="t('home.no_areas')" />
                </div>

                <!--
                    Links only when the public Area profile is switched on. The
                    chip previously pointed at `/areas/{id}` with no route behind
                    it, so every area on the homepage was a 404; the flag now
                    governs the link and the route together, and the un-linked
                    form still carries the real name and count.
                -->
                <AreaSummaryList
                    v-else
                    :areas="areas.items"
                    :linkable="areas.linkable"
                    :hrefs="areaHrefs"
                />
            </section>
        </div>
    </PublicLayout>
</template>
