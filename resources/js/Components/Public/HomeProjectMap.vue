<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref, shallowRef, watch } from 'vue';
import { Link, usePage } from '@inertiajs/vue3';
import AppIcon from '@/Components/Icons/AppIcon.vue';
import MobileBottomSheet from '@/Components/Public/MobileBottomSheet.vue';
import { formatNumber, t } from '@/lib/i18n';
import { useIsDesktop } from '@/Composables/useIsDesktop';
import { useLocale } from '@/Composables/useLocale';
import { createMapAdapter, type MapAdapter, type PriceTrend } from '@/lib/map';
import { normaliseTrend, trendArrowGlyph, trendHasClaim } from '@/lib/map/trend';

/*
 * The homepage's Pricing Intelligence Map (redesign §5.4) — homepage
 * section 2 and the page's major feature. A real, bounded instance of the
 * shared map infrastructure; a pricing-and-analytics instrument, never a
 * sales surface: no buy/sell/book/contact affordances exist here (R24).
 *
 * DATA HONESTY (R25), unchanged in spirit from the previous live map and
 * extended to the new pricing presentation:
 *   - every figure comes from `/invest/features` exactly as delivered
 *     (price_from/price_to/currency/price_type/price_at/trend/trend_percent);
 *   - `trend === 'unknown'` renders the neutral treatment with NO number;
 *   - the "previous price" is DERIVED client-side from price_from +
 *     trend_percent, and says so (`derived_note`) wherever it appears;
 *   - `price_at` is a date and renders as a date — no invented time;
 *   - when the investment layer is off (`source === 'map'`), the explorer
 *     feed carries no pricing: pins render without prices and the section
 *     states "pricing layer unavailable" instead of inventing anything;
 *   - clusters aggregate COUNTS only, never averaged prices (adapter).
 *
 * Costs stay bounded exactly as before: the MapLibre chunk is lazy, nothing
 * is constructed until the section approaches the viewport
 * (IntersectionObserver), and the data is one viewport request against the
 * same flag-gated endpoints the full surfaces use. Failure is honest: a
 * provider error settles into a compact fallback with the CTA link, never a
 * spinner; zero mapped projects states itself over a live basemap.
 *
 * FILTERS are strictly client-side over the fetched payload (§5.4): area,
 * project type, movement, last-update window — zero new endpoints. Per the
 * re-architecture the filter row sits DIRECTLY ABOVE the map at every
 * breakpoint — a horizontal scroll row below lg, wrapping above it — and
 * every control is a 44px touch target (the accessibility suite measures
 * '/'). The pin card stays a glass popover on desktop and a bottom sheet
 * below lg.
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
    type?: string | null;
    lat: number;
    lng: number;
    price_from?: string | null;
    price_to?: string | null;
    currency?: string | null;
    price_type?: string | null;
    price_at?: string | null;
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
/*
 * Construction can outlive the component: the visitor can scroll the map
 * into view and navigate away before the adapter chunk resolves. Unmount
 * then destroys a still-null ref, so the adapter that materialises later
 * must be destroyed HERE — and neither the fetch nor sync() may run against
 * an unmounted component.
 */
let disposed = false;
let observer: IntersectionObserver | null = null;

const trendArrow = trendArrowGlyph;
const pricing = computed(() => props.source === 'invest');

/* ------------------------------------------------------------- filters -- */

type Movement = 'all' | 'up' | 'down' | 'flat' | 'unknown';
type Window_ = 'all' | '7' | '30' | '90';

const movement = ref<Movement>('all');
const updatedWithin = ref<Window_>('all');
const areaFilter = ref('');
const typeFilter = ref('');

const areasPresent = computed(() =>
    [...new Set(projects.value.map((p) => p.area).filter((a): a is string => a !== null && a !== ''))].sort());

const typesPresent = computed(() =>
    [...new Set(projects.value.map((p) => p.type).filter((v): v is string => typeof v === 'string' && v !== ''))].sort());

const filtersActive = computed(() =>
    movement.value !== 'all' || updatedWithin.value !== 'all' || areaFilter.value !== '' || typeFilter.value !== '');

function resetFilters(): void {
    movement.value = 'all';
    updatedWithin.value = 'all';
    areaFilter.value = '';
    typeFilter.value = '';
}

function withinWindow(project: HomeProject): boolean {
    if (updatedWithin.value === 'all') return true;
    if (!project.price_at) return false;

    const at = Date.parse(project.price_at);
    if (!Number.isFinite(at)) return false;

    return Date.now() - at <= Number(updatedWithin.value) * 24 * 60 * 60 * 1000;
}

const visibleProjects = computed(() => projects.value.filter((project) => {
    if (movement.value !== 'all' && normaliseTrend(project.trend) !== movement.value) return false;
    if (areaFilter.value !== '' && project.area !== areaFilter.value) return false;
    if (typeFilter.value !== '' && project.type !== typeFilter.value) return false;

    return withinWindow(project);
}));

/* -------------------------------------------------------- derived price -- */

function numeric(value: string | null | undefined): number | null {
    if (value === null || value === undefined) return null;
    const parsed = Number(value);

    return Number.isFinite(parsed) ? parsed : null;
}

/**
 * Previous price, DERIVED from price_from + trend_percent per R25 — only for
 * a claimed trend, never for `unknown`, and always labelled as derived.
 */
const derivedPrevious = computed<{ value: number; delta: number } | null>(() => {
    const row = selected.value;
    if (!row || !pricing.value) return null;

    const trend = normaliseTrend(row.trend);
    if (!trendHasClaim(trend)) return null;

    const latest = numeric(row.price_from);
    const pct = numeric(row.trend_percent);
    if (latest === null || pct === null) return null;

    const divisor = 1 + pct / 100;
    if (divisor === 0) return null;

    const previous = latest / divisor;

    return { value: previous, delta: latest - previous };
});

const selectedTrend = computed<PriceTrend>(() => normaliseTrend(selected.value?.trend));

const movementTone: Record<Exclude<Movement, 'all'>, string> = {
    up: 'text-positive',
    down: 'text-negative',
    flat: 'text-caution',
    unknown: 'text-ink-faint',
};

/* ------------------------------------------------------------ lifecycle -- */

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

        if (disposed) {
            result.adapter.destroy();

            return;
        }

        adapter.value = result.adapter;

        await result.adapter.ready();

        if (disposed) {
            return;
        }

        mapReady.value = true;
    } catch {
        // The adapter installed just above must not idle behind the failure
        // state: destroy it now — after disposal the unmount hook already
        // did, and no state may change.
        if (!disposed) {
            adapter.value?.destroy();
            adapter.value = null;
            mapFailed.value = true;
        }
    }

    if (disposed) {
        return;
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

    adapter.value?.setPoints(visibleProjects.value.map((project) => {
        const price = pricing.value ? numeric(project.price_from) : null;

        return {
            lat: project.lat,
            lng: project.lng,
            title: project.name ?? '',
            colour: '#c9a227',
            id: project.id,
            // Real trend from the invest rows; the explorer rows carry none
            // and read as `unknown` — a neutral marker, never a pretend arrow.
            trend: normaliseTrend(project.trend),
            // The recorded price ON the pin (§5.4) — pre-formatted, Latin
            // digits, currency code attached; absent when the pricing layer
            // is off or the project has no recorded price.
            ...(price !== null && project.currency
                ? { label: `${formatNumber(price)} ${project.currency}` }
                : {}),
        };
    }));

    if (selected.value && !visibleProjects.value.some((p) => p.id === selected.value?.id)) {
        selected.value = null;
    }
}

watch(visibleProjects, () => sync());

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
    disposed = true;
    observer?.disconnect();
    adapter.value?.destroy();
    adapter.value = null;
});

/*
 * Reactive: crossing 1024px with a pin selected swaps the popover and the
 * bottom sheet immediately, and the sheet's body scroll-lock is released the
 * moment its `open` prop turns false.
 */
const isDesktop = useIsDesktop();
</script>

<template>
    <section ref="wrapper" :aria-label="t('home.pricing_map.title')" data-testid="home-project-map">
        <!-- Compact heading row: the map itself is the section's statement,
             so the heading is one line with the way into the full surface. -->
        <div class="mb-3 flex flex-wrap items-end justify-between gap-x-6 gap-y-1">
            <div class="min-w-0">
                <h2 class="font-display text-xl font-semibold text-ink sm:text-2xl">
                    {{ t('home.pricing_map.title') }}
                </h2>
                <p v-if="pricing" class="mt-1 max-w-prose text-sm text-ink-muted">
                    {{ t('home.pricing_map.lead') }}
                </p>
            </div>
            <Link
                :href="href"
                class="hidden min-h-11 items-center gap-1.5 text-sm font-medium text-accent-strong
                       transition-colors duration-200 ease-calm hover:text-ink lg:inline-flex"
            >
                {{ t('home.live_map.open_full') }}
                <AppIcon name="arrow-end" class="h-4 w-4" mirror />
            </Link>
        </div>

        <!-- Honest notice: the explorer feed carries no pricing enrichment. -->
        <p
            v-if="!pricing"
            class="mb-3 flex items-start gap-2 rounded-card border border-line bg-surface-sunken p-3 text-sm text-ink-muted"
        >
            <AppIcon name="layers" class="mt-0.5 h-4 w-4 shrink-0 text-ink-faint" aria-hidden="true" />
            {{ t('home.pricing_map.pricing_unavailable') }}
        </p>

        <!-- Client-side filters DIRECTLY ABOVE the map, all breakpoints
             (re-architecture §5/§6): a horizontal scroll row below lg,
             wrapping above it. Every control is a 44px touch target. -->
        <div
            v-if="pricing && loaded && projects.length > 0"
            class="mb-3 flex items-center gap-2 overflow-x-auto pb-1 lg:flex-wrap lg:overflow-visible lg:pb-0"
        >
            <span class="mh-label shrink-0 whitespace-nowrap">{{ t('home.pricing_map.movement') }}</span>
            <button
                v-for="option in (['up', 'down', 'flat', 'unknown'] as const)"
                :key="option"
                type="button"
                class="mh-invest-chip min-h-11 shrink-0 whitespace-nowrap"
                :aria-pressed="movement === option"
                @click="movement = movement === option ? 'all' : option"
            >
                <span v-if="option !== 'unknown'" aria-hidden="true" :class="movementTone[option]">
                    {{ trendArrow(option) }}
                </span>
                {{ t(`home.pricing_map.movement_${option}`) }}
            </button>

            <label class="mh-label ms-3 shrink-0 whitespace-nowrap" for="pm-window">
                {{ t('home.pricing_map.updated_within') }}
            </label>
            <select
                id="pm-window"
                v-model="updatedWithin"
                class="min-h-11 shrink-0 rounded-field border border-line-strong bg-surface-raised px-2 text-sm text-ink"
            >
                <option value="all">{{ t('home.pricing_map.updated_any') }}</option>
                <option value="7">{{ t('home.pricing_map.days_7') }}</option>
                <option value="30">{{ t('home.pricing_map.days_30') }}</option>
                <option value="90">{{ t('home.pricing_map.days_90') }}</option>
            </select>

            <label class="sr-only" for="pm-area">{{ t('home.pricing_map.area_label') }}</label>
            <select
                id="pm-area"
                v-model="areaFilter"
                class="min-h-11 shrink-0 rounded-field border border-line-strong bg-surface-raised px-2 text-sm text-ink"
            >
                <option value="">{{ t('home.pricing_map.all_areas') }}</option>
                <option v-for="area in areasPresent" :key="area" :value="area">{{ area }}</option>
            </select>

            <label class="sr-only" for="pm-type">{{ t('home.pricing_map.type_label') }}</label>
            <select
                id="pm-type"
                v-model="typeFilter"
                class="min-h-11 shrink-0 rounded-field border border-line-strong bg-surface-raised px-2 text-sm text-ink"
            >
                <option value="">{{ t('home.pricing_map.all_types') }}</option>
                <option v-for="value in typesPresent" :key="value" :value="value">
                    {{ t(`projects.types.${value}`) }}
                </option>
            </select>

            <button
                v-if="filtersActive"
                type="button"
                class="mh-lux-btn mh-lux-btn-ghost min-h-11 shrink-0 whitespace-nowrap !py-1 text-xs"
                @click="resetFilters()"
            >
                {{ t('home.pricing_map.reset') }}
            </button>
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
                <!-- The dominant surface (re-architecture §5): first-viewport
                     height on desktop, a large share of the screen on mobile. -->
                <div
                    ref="container"
                    class="h-[56vh] max-h-[700px] min-h-[360px] w-full lg:h-[clamp(480px,62vh,700px)]"
                    role="application"
                    :aria-label="t('home.pricing_map.title')"
                />

                <!-- Floating way into the full surface, ON the map — the
                     glass chip stays visible at every breakpoint. -->
                <Link
                    :href="href"
                    class="mh-invest-chip absolute end-3 top-3 z-10 min-h-11 whitespace-nowrap"
                >
                    {{ t('home.live_map.open_full') }}
                    <AppIcon name="external" class="h-4 w-4" aria-hidden="true" />
                </Link>

                <!-- Truthful empty state, over a live basemap. -->
                <div
                    v-if="mapReady && loaded && projects.length === 0"
                    class="pointer-events-none absolute inset-x-0 bottom-4 z-10 flex justify-center px-4"
                    aria-live="polite"
                >
                    <p class="mh-invest-chip !cursor-default text-center">{{ t('home.live_map.empty') }}</p>
                </div>

                <!-- Desktop selection: the pin card as a glass popover. -->
                <div
                    v-if="selected && isDesktop"
                    class="mh-invest-glass absolute bottom-3 start-3 z-10 max-w-[calc(100%-1.5rem)] rounded-card p-4 lg:max-w-sm"
                    role="status"
                >
                    <p class="text-sm font-semibold text-ink">{{ selected.name }}</p>
                    <p v-if="selected.area" class="mt-0.5 text-xs text-ink-muted">
                        {{ selected.area }}
                        <template v-if="selected.type"> · {{ t(`projects.types.${selected.type}`) }}</template>
                    </p>

                    <template v-if="pricing && selected.price_from">
                        <p class="mt-2 text-xs text-ink-faint">{{ t('home.pricing_map.latest_price') }}</p>
                        <p class="numeral text-sm font-semibold text-ink">
                            {{ formatNumber(Number(selected.price_from)) }}
                            <template v-if="selected.price_to"> – {{ formatNumber(Number(selected.price_to)) }}</template>
                            <span class="text-xs font-normal text-ink-muted">{{ selected.currency }}</span>
                        </p>
                        <p v-if="selected.price_type" class="mt-0.5 text-xs text-ink-faint">
                            {{ t(`market.price_types.${selected.price_type}`) }}
                        </p>

                        <p v-if="trendHasClaim(selectedTrend)" class="mt-1.5 text-xs" :class="movementTone[selectedTrend]">
                            <span aria-hidden="true">{{ trendArrow(selectedTrend) }}</span>
                            <span class="numeral">{{ selected.trend_percent }}%</span>
                            <span class="sr-only">{{ t(`map.invest.trend.${selectedTrend}`) }}</span>
                            <template v-if="derivedPrevious">
                                · <span class="numeral">{{ formatNumber(Math.abs(derivedPrevious.delta)) }}</span>
                                {{ selected.currency }}
                            </template>
                        </p>
                        <p v-else class="mt-1.5 text-xs text-ink-faint">{{ t('map.invest.trend.unknown') }}</p>

                        <p v-if="derivedPrevious" class="mt-1 text-xs text-ink-faint">
                            {{ t('home.pricing_map.previous_price') }}:
                            <span class="numeral">{{ formatNumber(derivedPrevious.value) }}</span>
                            {{ selected.currency }} — {{ t('home.pricing_map.derived_note') }}
                        </p>

                        <p v-if="selected.price_at" class="mt-1 text-xs text-ink-faint">
                            {{ t('home.pricing_map.last_update') }}: <span class="numeral">{{ selected.price_at }}</span>
                        </p>
                    </template>

                    <div class="mt-3 flex items-center gap-2.5">
                        <Link :href="localized(`/projects/${selected.slug}`)" class="mh-lux-btn mh-lux-btn-primary !py-1.5 text-xs">
                            {{ pricing ? t('home.pricing_map.price_history') : t('map.invest.view_project') }}
                        </Link>
                        <button type="button" class="mh-lux-btn mh-lux-btn-ghost !py-1.5 text-xs" @click="selected = null">
                            {{ t('app.actions.close') }}
                        </button>
                    </div>
                </div>
            </template>
        </div>

        <!-- Mobile pin card: same content, as a bottom sheet. -->
        <MobileBottomSheet
            :open="selected !== null && !isDesktop"
            :title="selected?.name ?? undefined"
            :detents="['peek', 'half']"
            @close="selected = null"
        >
            <template v-if="selected">
                <p v-if="selected.area" class="text-sm text-ink-muted">
                    {{ selected.area }}
                    <template v-if="selected.type"> · {{ t(`projects.types.${selected.type}`) }}</template>
                </p>

                <template v-if="pricing && selected.price_from">
                    <p class="mt-3 text-xs text-ink-faint">{{ t('home.pricing_map.latest_price') }}</p>
                    <p class="numeral text-lg font-semibold text-ink">
                        {{ formatNumber(Number(selected.price_from)) }}
                        <template v-if="selected.price_to"> – {{ formatNumber(Number(selected.price_to)) }}</template>
                        <span class="text-sm font-normal text-ink-muted">{{ selected.currency }}</span>
                    </p>
                    <p v-if="selected.price_type" class="mt-0.5 text-xs text-ink-faint">
                        {{ t(`market.price_types.${selected.price_type}`) }}
                    </p>

                    <p v-if="trendHasClaim(selectedTrend)" class="mt-2 text-sm" :class="movementTone[selectedTrend]">
                        <span aria-hidden="true">{{ trendArrow(selectedTrend) }}</span>
                        <span class="numeral">{{ selected.trend_percent }}%</span>
                        <span class="sr-only">{{ t(`map.invest.trend.${selectedTrend}`) }}</span>
                    </p>
                    <p v-else class="mt-2 text-sm text-ink-faint">{{ t('map.invest.trend.unknown') }}</p>

                    <p v-if="derivedPrevious" class="mt-1.5 text-xs text-ink-faint">
                        {{ t('home.pricing_map.previous_price') }}:
                        <span class="numeral">{{ formatNumber(derivedPrevious.value) }}</span>
                        {{ selected.currency }} — {{ t('home.pricing_map.derived_note') }}
                    </p>

                    <p v-if="selected.price_at" class="mt-1.5 text-xs text-ink-faint">
                        {{ t('home.pricing_map.last_update') }}: <span class="numeral">{{ selected.price_at }}</span>
                    </p>
                </template>

                <Link
                    :href="localized(`/projects/${selected.slug}`)"
                    class="mh-lux-btn mh-lux-btn-primary mt-4 w-full"
                >
                    {{ pricing ? t('home.pricing_map.price_history') : t('map.invest.view_project') }}
                </Link>
            </template>
        </MobileBottomSheet>
    </section>
</template>
