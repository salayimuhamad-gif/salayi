<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref, shallowRef } from 'vue';
import { Link, usePage } from '@inertiajs/vue3';
import AppIcon from '@/Components/Icons/AppIcon.vue';
import { formatNumber, t } from '@/lib/i18n';
import { useLocale } from '@/Composables/useLocale';
import { createMapAdapter, type MapAdapter, type PriceTrend } from '@/lib/map';
import { normaliseTrend, trendArrowGlyph } from '@/lib/map/trend';

/*
 * The homepage's LIVE project map (map brief, homepage section).
 *
 * A real, small, bounded instance of the shared map infrastructure — not a
 * second implementation and not a decorative card. It shows published
 * projects with real coordinates around Erbil, nothing else: no places, no
 * offers, no companies, exactly one fetch. Costs are held down three ways:
 * the MapLibre chunk stays lazy (the adapter dynamic-imports it), nothing
 * at all is constructed until the section actually scrolls into view
 * (IntersectionObserver), and the data is one viewport request against the
 * same flag-gated endpoints the full surfaces use — the invest endpoint
 * when the investment map is enabled (its rows carry the real trend), the
 * explorer endpoint otherwise. When neither flag is on, the parent renders
 * the old teaser instead of this component — the flags stay authoritative.
 *
 * Failure is honest: a provider error settles into a compact fallback with
 * the CTA link, never a spinner; zero mapped projects states itself over a
 * live basemap.
 */
const props = defineProps<{
    /** Which enabled surface backs the homepage map, decided by the parent. */
    source: 'invest' | 'map';
    /** Localized link into the full experience. */
    href: string;
}>();

const { localized } = useLocale();
const page = usePage();

const styleUrl = computed(() => (page.props.map as { style_url?: string | null } | undefined)?.style_url ?? null);

interface HomeProject {
    id: number;
    slug: string;
    name: string | null;
    area: string | null;
    lat: number;
    lng: number;
    price_from?: string | null;
    currency?: string | null;
    trend?: PriceTrend | null;
    trend_percent?: string | null;
}

const wrapper = ref<HTMLElement | null>(null);
const container = ref<HTMLDivElement | null>(null);
const adapter = shallowRef<MapAdapter | null>(null);

const mapReady = ref(false);
const mapFailed = ref(false);
const loaded = ref(false);
const projects = ref<HomeProject[]>([]);
const selected = ref<HomeProject | null>(null);

/** Erbil and its suburbs — the homepage never asks for the world. */
const ERBIL_BOX = { north: 36.42, south: 35.95, east: 44.32, west: 43.7 };

/** A homepage section, not an explorer: cap what one glance can use. */
const HOME_LIMIT = 60;

let started = false;
let observer: IntersectionObserver | null = null;

const trendArrow = trendArrowGlyph;

async function start(): Promise<void> {
    if (started || !container.value) return;
    started = true;

    try {
        const result = await createMapAdapter('maplibre', {
            container: container.value,
            styleUrl: styleUrl.value,
            googleKey: null,
            centre: { lat: 36.19, lng: 44.009 },
            zoom: 11,
            minZoom: 9,
            maxZoom: 16,
            maxBounds: ERBIL_BOX,
            accentColour: '#c9a227',
            events: {
                onMoveEnd: () => {},
                onClick: () => {
                    selected.value = null;
                },
                onError: () => {
                    if (!mapReady.value) mapFailed.value = true;
                },
                onMarkerClick: (id: number) => {
                    const project = projects.value.find((candidate) => candidate.id === id);

                    if (project) {
                        selected.value = project;
                        adapter.value?.flyTo({ lat: project.lat, lng: project.lng }, 14);
                    }
                },
            },
        });

        adapter.value = result.adapter;

        await result.adapter.ready();
        mapReady.value = true;
    } catch {
        mapFailed.value = true;
    }

    // The list of mapped projects is useful even when tiles failed.
    await load();
    sync();
}

async function load(): Promise<void> {
    const params = new URLSearchParams();
    params.set('north', String(ERBIL_BOX.north));
    params.set('south', String(ERBIL_BOX.south));
    params.set('east', String(ERBIL_BOX.east));
    params.set('west', String(ERBIL_BOX.west));
    params.set('zoom', '11');
    params.append('layers[]', 'projects');

    const endpoint = props.source === 'invest' ? '/invest/features' : '/map/features';

    try {
        const response = await fetch(localized(`${endpoint}?${params.toString()}`), {
            headers: { Accept: 'application/json' },
        });

        if (!response.ok) throw new Error(String(response.status));

        const data = await response.json();
        projects.value = (data.projects ?? []).slice(0, HOME_LIMIT);
    } catch {
        projects.value = [];
    } finally {
        loaded.value = true;
    }
}

function sync(): void {
    if (!mapReady.value) return;

    adapter.value?.setPoints(projects.value.map((project) => ({
        lat: project.lat,
        lng: project.lng,
        title: project.name ?? '',
        colour: '#c9a227',
        id: project.id,
        // Real trend from the invest rows; the explorer rows carry none and
        // read as `unknown` — a neutral marker, never a pretend arrow.
        trend: normaliseTrend(project.trend),
    })));
}

onMounted(() => {
    if (typeof IntersectionObserver === 'undefined') {
        void start();
        return;
    }

    observer = new IntersectionObserver((entries) => {
        if (entries.some((entry) => entry.isIntersecting)) {
            observer?.disconnect();
            observer = null;
            void start();
        }
    }, { rootMargin: '200px' });

    if (wrapper.value) observer.observe(wrapper.value);
});

onBeforeUnmount(() => {
    observer?.disconnect();
    adapter.value?.destroy();
    adapter.value = null;
});
</script>

<template>
    <section ref="wrapper" :aria-label="t('home.live_map.title')" data-testid="home-project-map">
        <div class="mb-4 flex items-baseline justify-between gap-3">
            <div>
                <p class="mh-lux-eyebrow mb-1">{{ t('nav.public.invest') }}</p>
                <h2 class="font-display text-xl font-semibold text-ink">{{ t('home.live_map.title') }}</h2>
            </div>
            <Link :href="href" class="inline-flex shrink-0 items-center gap-1.5 text-sm font-medium text-accent">
                {{ t('home.live_map.open_full') }}
                <AppIcon name="arrow-end" class="h-4 w-4 rtl:-scale-x-100" />
            </Link>
        </div>

        <div class="mh-lux-panel mh-lux-gilded relative overflow-hidden">
            <!-- Provider failure: compact, human, with the way forward. -->
            <div v-if="mapFailed" class="flex min-h-[220px] flex-col items-center justify-center gap-3 p-6 text-center">
                <p class="text-sm text-ink-muted">{{ t('map.states.provider_failed') }}</p>
                <Link :href="href" class="mh-lux-btn mh-lux-btn-primary text-sm">
                    {{ t('home.live_map.open_full') }}
                </Link>
            </div>

            <template v-else>
                <div
                    ref="container"
                    class="h-[320px] w-full sm:h-[380px]"
                    role="application"
                    :aria-label="t('home.live_map.title')"
                />

                <!-- Truthful empty state, over a live basemap. -->
                <div
                    v-if="mapReady && loaded && projects.length === 0"
                    class="pointer-events-none absolute inset-x-0 bottom-4 z-10 flex justify-center px-4"
                    aria-live="polite"
                >
                    <p class="mh-invest-chip !cursor-default text-center">{{ t('home.live_map.empty') }}</p>
                </div>

                <!-- Selection: name, area, qualified price, trend, link. -->
                <div
                    v-if="selected"
                    class="mh-invest-glass absolute bottom-3 start-3 max-w-[calc(100%-1.5rem)] rounded-card p-3.5 sm:max-w-xs"
                    role="status"
                >
                    <p class="text-sm font-semibold text-ink">{{ selected.name }}</p>
                    <p v-if="selected.area" class="mt-0.5 text-xs text-ink-muted">{{ selected.area }}</p>
                    <p v-if="selected.price_from" class="numeral mt-1 text-xs text-ink">
                        {{ t('map.invest.price_from_label') }}
                        {{ formatNumber(Number(selected.price_from)) }}
                        <span class="text-ink-muted">{{ selected.currency }}</span>
                        <span
                            v-if="selected.trend && selected.trend !== 'unknown'"
                            class="ms-1.5 font-semibold"
                            :class="{
                                'text-positive': selected.trend === 'up',
                                'text-negative': selected.trend === 'down',
                                'text-caution': selected.trend === 'flat',
                            }"
                        >
                            <span aria-hidden="true">{{ trendArrow(selected.trend) }}</span>
                            <template v-if="selected.trend_percent">{{ selected.trend_percent }}%</template>
                            <span class="sr-only">{{ t(`map.invest.trend.${selected.trend}`) }}</span>
                        </span>
                    </p>
                    <div class="mt-2.5 flex items-center gap-2.5">
                        <Link
                            :href="localized(`/projects/${selected.slug}`)"
                            class="mh-lux-btn mh-lux-btn-primary !py-1 text-xs"
                        >
                            {{ t('map.invest.view_project') }}
                        </Link>
                        <button
                            type="button"
                            class="mh-lux-btn mh-lux-btn-ghost !py-1 text-xs"
                            @click="selected = null"
                        >
                            {{ t('app.actions.close') }}
                        </button>
                    </div>
                </div>
            </template>
        </div>
    </section>
</template>
