<script setup lang="ts">
import { computed, ref } from 'vue';
import { Head } from '@inertiajs/vue3';
import PublicLayout from '@/Layouts/PublicLayout.vue';
import AppCard from '@/Components/ui/AppCard.vue';
import AppAlert from '@/Components/ui/AppAlert.vue';
import ProvenanceChip from '@/Components/ProvenanceChip.vue';
import PriceHistory from '@/Components/PriceHistory.vue';
import RatingPanel from '@/Components/RatingPanel.vue';
import MediaGallery from '@/Components/MediaGallery.vue';
import ErbilMapPreview from '@/Components/Public/ErbilMapPreview.vue';
import KnowledgeTimeline from '@/Components/KnowledgeTimeline.vue';
import { t, formatNumber } from '@/lib/i18n';

interface Provenance {
    source: string | null;
    verified_at: string | null;
    confidence: string | null;
    age_days: number | null;
}

const props = defineProps<{
    project: {
        slug: string;
        name: string;
        name_is_fallback: boolean;
        description: string | null;
        missing_translations: string[];
        developer: { name: string; is_verified: boolean } | null;
        area: string | null;
        type: string | null;
        construction_status: string | null;
        delivery_status: string | null;
        is_adverse: boolean;
        completion_percent: number | null;
        completion_is_implied: boolean;
        dates: Record<string, string>;
        facts: Record<string, number>;
        geometry: { latitude: number | null; longitude: number | null; boundary_wkt: string | null };
        official_url: string | null;
        provenance: Provenance;
        published_at: string | null;
    };
    seo: {
        title: string;
        description: string;
        canonical: string;
        alternates: Array<{ hreflang: string; href: string }>;
        structured_data: Record<string, unknown>;
    };
    prices: {
        series: Array<{
            price_type: string;
            family: string;
            requires_qualifier: boolean;
            currency: string;
            points: Array<{ period: string; value: string; sample_size: number | null; source: string | null; confidence: string }>;
            change_percent: string | null;
            sources: string[];
        }>;
        has_verified: boolean;
        has_asking_only: boolean;
        period_from: string | null;
        period_to: string | null;
    };
    media: Array<{
        url: string; alt: string | null; credit: string | null;
        width: number | null; height: number | null;
    }>;
    timeline: Array<{
        title: string; summary: string | null; event_type: string;
        direction: string; strength: number;
        effective_date: string | null; expected_date: string | null;
        source: string | null; source_url: string | null; confidence: string;
    }>;
    ratings: {
        categories: Array<{
            category: string;
            group: string;
            inverted: boolean;
            official: { score: string | null; confidence: string; contributing_types: string[]; sample_size: number };
            by_type: Array<{
                type: string; mean: string; count: number; displayable: boolean;
                contributes_to_official: boolean; ai_generated: boolean; requires_provenance_label: boolean;
            }>;
        }>;
        has_any: boolean;
        official_count: number;
    };
    nearby: Array<{
        name: string;
        category: string | null;
        category_key: string | null;
        icon: string | null;
        group: string | null;
        distance_m: number;
        compass: string | null;
        latitude: number;
        longitude: number;
        is_stale: boolean;
        has_travel_data: boolean;
        source: string;
        calculated_at: string | null;
    }>;
    unavailable: Record<string, boolean>;
}>();

/**
 * Distance is rounded to 10 m below a kilometre, matching Geodesy::humanise().
 * A "437 m" derived from source data with tens of metres of positional error
 * implies a precision the data does not have, and readers treat a precise
 * number as a reliable one.
 */
/*
 * Category filtering (File two §7 "allow filtering by place type").
 *
 * Filtering on category_key rather than the display name: the name is
 * translated, so a chip that matched on it would stop matching the moment the
 * visitor switched language.
 */
const activeCategory = ref<string | null>(null);

const nearbyCategories = computed(() => {
    const seen = new Map<string, { key: string; label: string; count: number }>();

    for (const place of props.nearby) {
        if (place.category_key === null) continue;

        const existing = seen.get(place.category_key);

        if (existing) {
            existing.count += 1;

            continue;
        }

        seen.set(place.category_key, {
            key: place.category_key,
            label: place.category ?? place.category_key,
            count: 1,
        });
    }

    return [...seen.values()].sort((a, b) => b.count - a.count);
});

const visibleNearby = computed(() =>
    activeCategory.value === null
        ? props.nearby
        : props.nearby.filter((p) => p.category_key === activeCategory.value),
);

// §7 "show the date of the last update". The oldest calculation in the list is
// the honest one to show: quoting the newest would imply the whole panel is
// that fresh when part of it may be months older.
const nearbyCalculatedAt = computed<string | null>(() => {
    const dates = props.nearby.map((p) => p.calculated_at).filter((d): d is string => d !== null);

    return dates.length > 0 ? dates.sort()[0] : null;
});

function humaniseDistance(metres: number): string {
    if (metres < 1000) return `${Math.round(metres / 10) * 10} m`;
    return `${(metres / 1000).toFixed(metres < 10000 ? 1 : 0)} km`;
}

const factRows = computed(() =>
    Object.entries(props.project.facts).map(([key, value]) => ({
        key,
        label: t(`projects.fields.${key === 'land_area_sqm' ? 'land_area' : key === 'building_count' ? 'buildings' : 'units'}`),
        value: formatNumber(value),
    })),
);

const missingSections = computed(() =>
    Object.entries(props.unavailable).filter(([, missing]) => missing).map(([key]) => key),
);
</script>

<template>
    <Head>
        <title>{{ seo.title }}</title>
        <meta name="description" :content="seo.description">
        <link rel="canonical" :href="seo.canonical">
        <!-- hreflang alternates, spec 31.2 -->
        <link
            v-for="alternate in seo.alternates"
            :key="alternate.hreflang"
            rel="alternate"
            :hreflang="alternate.hreflang"
            :href="alternate.href"
        >
        <!-- Structured data asserts only what the page below actually shows.
             Markup claiming more than the visible page is misleading by
             definition. -->
        <component :is="'script'" type="application/ld+json">{{ JSON.stringify(seo.structured_data) }}</component>
    </Head>

    <PublicLayout>
        <article class="space-y-6">
            <header>
                <p v-if="project.area" class="mh-label">{{ project.area }}</p>
                <h1 class="mt-1 font-display text-2xl font-bold text-ink">{{ project.name }}</h1>

                <!-- The reader is told when they are seeing a fallback language
                     rather than being quietly served another one. -->
                <p v-if="project.name_is_fallback" class="mt-1 text-xs text-caution">
                    {{ t('projects.translation_fallback') }}
                </p>

                <div class="mt-4">
                    <ProvenanceChip
                        :source="project.provenance.source"
                        :verified-at="project.provenance.verified_at"
                        :confidence="project.provenance.confidence"
                        :age-days="project.provenance.age_days"
                    />
                </div>
            </header>

            <!-- A stalled or delayed project is stated plainly, not buried in a
                 status field a reader has to interpret. -->
            <AppAlert v-if="project.is_adverse" variant="warning">
                {{ t('projects.adverse_notice') }}
            </AppAlert>

            <MediaGallery v-if="media.length > 0" :media="media" />

            <AppCard v-if="project.description" :title="t('projects.about')">
                <p class="whitespace-pre-line leading-relaxed text-ink-muted">{{ project.description }}</p>
            </AppCard>

            <div class="grid gap-5 sm:grid-cols-2">
                <AppCard :title="t('projects.status_section')">
                    <dl class="space-y-3 text-sm">
                        <div v-if="project.construction_status" class="flex justify-between gap-3">
                            <dt class="text-ink-muted">{{ t('projects.fields.construction_status') }}</dt>
                            <dd class="font-medium text-ink">
                                {{ t(`projects.construction_statuses.${project.construction_status}`) }}
                            </dd>
                        </div>
                        <div v-if="project.delivery_status" class="flex justify-between gap-3">
                            <dt class="text-ink-muted">{{ t('projects.fields.delivery_status') }}</dt>
                            <dd class="font-medium text-ink">
                                {{ t(`projects.delivery_statuses.${project.delivery_status}`) }}
                            </dd>
                        </div>
                        <div v-if="project.completion_percent !== null" class="flex justify-between gap-3">
                            <dt class="text-ink-muted">{{ t('projects.fields.completion_percent') }}</dt>
                            <dd class="numeral font-medium text-ink">
                                {{ project.completion_percent }}%
                                <!-- An implied figure is labelled as implied. -->
                                <span v-if="project.completion_is_implied" class="text-xs font-normal text-ink-faint">
                                    {{ t('projects.implied') }}
                                </span>
                            </dd>
                        </div>
                        <div v-for="(value, key) in project.dates" :key="key" class="flex justify-between gap-3">
                            <dt class="text-ink-muted">{{ t(`projects.fields.${key === 'launch' ? 'launch_date' : key}`) }}</dt>
                            <dd class="numeral font-medium text-ink">{{ value }}</dd>
                        </div>
                    </dl>
                </AppCard>

                <AppCard v-if="factRows.length > 0" :title="t('projects.scale_section')">
                    <dl class="space-y-3 text-sm">
                        <div v-for="row in factRows" :key="row.key" class="flex justify-between gap-3">
                            <dt class="text-ink-muted">{{ row.label }}</dt>
                            <dd class="numeral font-medium text-ink">{{ row.value }}</dd>
                        </div>
                    </dl>
                </AppCard>
            </div>

            <AppCard v-if="project.developer" :title="t('projects.fields.developer')">
                <div class="flex flex-wrap items-center gap-2">
                    <span class="font-medium text-ink">{{ project.developer.name }}</span>
                    <span
                        v-if="project.developer.is_verified"
                        class="rounded-full bg-positive/10 px-2.5 py-0.5 text-xs text-positive"
                    >{{ t('companies.verification.verified') }}</span>
                </div>
            </AppCard>

            <AppCard v-if="timeline.length > 0" :title="t('knowledge.timeline_title')">
                <KnowledgeTimeline :timeline="timeline" />
            </AppCard>

            <AppCard v-if="ratings.has_any" :title="t('projects.ratings.title')">
                <p class="mb-4 text-xs text-ink-faint">{{ t('projects.ratings.provenance_notice') }}</p>
                <RatingPanel :ratings="ratings" />
            </AppCard>

            <AppCard v-if="prices.series.length > 0" :title="t('market.history.title')">
                <PriceHistory :prices="prices" />
            </AppCard>

            <!-- The project located on its EXISTING coordinates (redesign
                 §12.1): renders only when the server sent both, degrades
                 silently on provider failure. No coordinate is ever invented. -->
            <AppCard
                v-if="project.geometry.latitude !== null && project.geometry.longitude !== null"
                :title="t('nav.public.map')"
                :padded="false"
            >
                <ErbilMapPreview
                    :lat="project.geometry.latitude"
                    :lng="project.geometry.longitude"
                    :label="project.name ?? ''"
                />
            </AppCard>

            <AppCard v-if="nearby.length > 0" :title="t('geography.nearby.title')">
                <p class="mb-3 text-xs text-ink-faint">{{ t('geography.nearby.straight_line_notice') }}</p>

                <!-- File two §7: filter by place type. Chips rather than a
                     select, because on a phone this is the primary way people
                     use this panel — "show me the schools" — and a select hides
                     both the options and how many of each there are. -->
                <div v-if="nearbyCategories.length > 1" class="mb-4 flex flex-wrap gap-2">
                    <button
                        type="button"
                        class="rounded-card border px-2.5 py-1 text-xs transition-colors
                               focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-accent"
                        :class="activeCategory === null
                            ? 'border-brand bg-brand text-white'
                            : 'border-line text-ink-muted hover:bg-surface-sunken'"
                        :aria-pressed="activeCategory === null"
                        @click="activeCategory = null"
                    >
                        {{ t('geography.nearby.filter_all') }}
                    </button>

                    <button
                        v-for="category in nearbyCategories"
                        :key="category.key"
                        type="button"
                        class="rounded-card border px-2.5 py-1 text-xs transition-colors
                               focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-accent"
                        :class="activeCategory === category.key
                            ? 'border-brand bg-brand text-white'
                            : 'border-line text-ink-muted hover:bg-surface-sunken'"
                        :aria-pressed="activeCategory === category.key"
                        @click="activeCategory = category.key"
                    >
                        {{ category.label }}
                        <span class="numeral opacity-70">{{ formatNumber(category.count) }}</span>
                    </button>
                </div>

                <ul class="divide-y divide-line">
                    <li
                        v-for="place in visibleNearby"
                        :key="`${place.category_key}-${place.name}`"
                        class="flex items-center gap-3 py-2.5"
                    >
                        <!-- The category colour dot doubles as the icon slot.
                             An icon name with no icon font loaded would render
                             as stray text, so the marker is drawn, not typed. -->
                        <span
                            class="inline-block h-2 w-2 shrink-0 rounded-full bg-brand/60"
                            aria-hidden="true"
                        />

                        <div class="min-w-0 flex-1">
                            <p class="truncate text-sm font-medium text-ink">{{ place.name }}</p>
                            <p class="truncate text-xs text-ink-muted">{{ place.category }}</p>
                        </div>

                        <div class="shrink-0 text-end">
                            <p class="numeral text-sm text-ink">{{ humaniseDistance(place.distance_m) }}</p>
                            <p v-if="place.is_stale" class="text-xs text-caution">
                                {{ t('geography.nearby.stale') }}
                            </p>
                        </div>
                    </li>
                </ul>

                <!-- §7: the date of the last calculation. A distance with no
                     age is a claim the reader cannot judge. -->
                <p v-if="nearbyCalculatedAt" class="numeral mt-4 text-xs text-ink-faint" dir="ltr">
                    {{ t('geography.nearby.last_updated') }}: {{ nearbyCalculatedAt }}
                </p>
            </AppCard>

            <!-- Sections the product has not populated are declared, not
                 silently absent. Spec 17.5: say when data is insufficient. -->
            <AppCard v-if="missingSections.length > 0" :title="t('projects.not_yet_available')">
                <ul class="space-y-1.5 text-sm text-ink-muted">
                    <li v-for="section in missingSections" :key="section" class="flex gap-2">
                        <span aria-hidden="true">·</span>
                        <span>{{ t(`projects.sections_pending.${section}`) }}</span>
                    </li>
                </ul>
            </AppCard>

            <footer class="border-t border-line pt-5">
                <a
                    v-if="project.official_url"
                    :href="project.official_url"
                    rel="noopener nofollow external"
                    target="_blank"
                    class="text-sm text-brand underline-offset-2 hover:underline"
                >{{ t('projects.fields.official_url') }}</a>
            </footer>
        </article>
    </PublicLayout>
</template>
