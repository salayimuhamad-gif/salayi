<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref, shallowRef, watch } from 'vue';
import { Head, Link } from '@inertiajs/vue3';
import PublicLayout from '@/Layouts/PublicLayout.vue';
import AiAvatar from '@/Components/Public/AiAvatar.vue';
import AppAlert from '@/Components/ui/AppAlert.vue';
import AppIcon from '@/Components/Icons/AppIcon.vue';
import MobileBottomSheet from '@/Components/Public/MobileBottomSheet.vue';
import { formatNumber, t } from '@/lib/i18n';
import { useIsDesktop } from '@/Composables/useIsDesktop';
import { useLocale } from '@/Composables/useLocale';
import { createMapAdapter, type BoundaryCollection, type MapAdapter } from '@/lib/map';
import { normaliseTrend, trendArrowGlyph } from '@/lib/map/trend';

/*
 * The public Investment Map (/invest).
 *
 * The explorer's core in a curated configuration: approved, published
 * investment projects and the area context they sit in — nothing else. The
 * layer restriction is enforced by the SERVER (/invest/features drops every
 * other layer regardless of the request); what this page controls is only
 * presentation.
 *
 * The explorer's four load-bearing behaviours carry over unchanged:
 * viewport-scoped fetching, an always-rendered list beside the map (the
 * primary view on a narrow screen), clustered GeoJSON markers rather than
 * DOM nodes, and no fabricated coordinates or travel times.
 */
const { localized } = useLocale();

interface ProjectFeature {
    id: number;
    slug: string;
    name: string | null;
    area: string | null;
    lat: number;
    lng: number;
    type: string | null;
    price_from?: string | null;
    price_to?: string | null;
    currency?: string | null;
    price_type?: string | null;
    price_at?: string | null;
    /*
     * Server-decided, four-valued: `unknown` means insufficient comparable
     * history and renders as a NEUTRAL marker with no percentage — never as
     * flat, and never as nothing-at-all on the marker layer.
     */
    trend?: 'up' | 'down' | 'flat' | 'unknown' | null;
    trend_percent?: string | null;
}

interface SearchResult {
    id: number;
    slug: string;
    name: string | null;
    area: string | null;
    lat: number;
    lng: number;
}

/**
 * An area's representative point from the features payload. The server's own
 * contract (MapExplorerController): polygons draw the border, POINTS carry
 * the label — and only the polygon is zoom-gated. Below the boundary gate
 * this point is all that keeps a published area on the map.
 */
interface AreaContextPoint {
    id: number;
    slug: string;
    name: string | null;
    lat: number;
    lng: number;
}

const props = defineProps<{
    style_url: string | null;
    provider: string;
    provider_requested: string;
    provider_fallback_reason: string | null;
    google_key: string | null;
    layers: Array<{ key: string; flag: string | null }>;
    types: string[];
    distance: { unit: string; method: string; travel_time_available: boolean };
    limits: { max_per_layer: number; max_radius_km: number };
}>();

/* ------------------------------------------------------------------ state */

const container = ref<HTMLDivElement | null>(null);
const adapter = shallowRef<MapAdapter | null>(null);

const renderedProvider = ref<string | null>(null);
const clientFallbackReason = ref<string | null>(null);

const emptyBoundaries: BoundaryCollection = { type: 'FeatureCollection', features: [] };
const boundaries = ref<BoundaryCollection>(emptyBoundaries);

/*
 * Area representative points, alongside the polygons above and NEVER derived
 * from them. The server zoom-gates the polygons at the boundary threshold but
 * serves the points at every zoom; keeping only the polygons made a published
 * area vanish entirely the moment the visitor zoomed below the gate.
 */
const areaPoints = ref<AreaContextPoint[]>([]);

const projects = ref<ProjectFeature[]>([]);
const selected = ref<ProjectFeature | null>(null);
const showBoundaries = ref(false);

/** Project outlines, zoom-gated server-side; merged with area boundaries. */
const projectBoundaries = ref<BoundaryCollection>({ type: 'FeatureCollection', features: [] });

/* Type filter chips — the existing project-type system, nothing parallel. */
const activeTypes = ref<string[]>([]);

function toggleType(type: string): void {
    activeTypes.value = activeTypes.value.includes(type)
        ? activeTypes.value.filter((value) => value !== type)
        : [...activeTypes.value, type];
    void load();
}

/* Debounced search over approved investment content. */
const searchQuery = ref('');
const searchResults = ref<SearchResult[]>([]);
const searchOpen = ref(false);
const searching = ref(false);
let searchDebounce: ReturnType<typeof setTimeout> | undefined;

function scheduleSearch(): void {
    if (searchDebounce !== undefined) {
        clearTimeout(searchDebounce);
    }

    const term = searchQuery.value.trim();

    if (term.length < 2) {
        searchResults.value = [];
        searchOpen.value = false;
        return;
    }

    searchDebounce = setTimeout(() => void runSearch(term), 250);
}

async function runSearch(term: string): Promise<void> {
    searching.value = true;

    try {
        const response = await fetch(
            localized(`/invest/search?q=${encodeURIComponent(term)}`),
            { headers: { Accept: 'application/json' } },
        );

        if (!response.ok) {
            throw new Error(String(response.status));
        }

        const data = await response.json();
        searchResults.value = data.results ?? [];
        searchOpen.value = true;
    } catch {
        // A failed search keeps the previous dropdown state; the field is
        // still usable and the next keystroke retries.
    } finally {
        searching.value = false;
    }
}

function chooseResult(result: SearchResult): void {
    searchOpen.value = false;
    searchQuery.value = result.name ?? '';
    select({ ...result, type: null });
}

/** Direction as a glyph. Never the only signal — text and sign ride along. */
const trendArrow = trendArrowGlyph;

/*
 * Selection presentation splits on lg, reactively: the floating glass card
 * is desktop chrome; below 1024px the same content rides in MobileBottomSheet
 * (§F — never a floating popover on a phone). The gate must sit on the
 * sheet's `open` prop, not only on CSS, because the sheet scroll-locks the
 * body from a watcher on `open` — logically open on desktop would lock the
 * page with no visible dialog.
 */
const isDesktop = useIsDesktop();

const mapFailed = ref(false);
const mapReady = ref(false);
/*
 * True while a map build (initial or retried) is in flight. This is what
 * makes the Retry button single-shot: a second tap while the first build is
 * still running must be a no-op, never a second adapter.
 */
const mapBuilding = ref(false);
const loading = ref(false);
const loadError = ref(false);
const offline = ref(typeof navigator !== 'undefined' ? !navigator.onLine : false);
const truncated = ref(false);
const mobileView = ref<'map' | 'list'>('map');

const hasResults = computed(() => projects.value.length > 0);
const boundariesAvailable = computed(() => props.layers.some((layer) => layer.key === 'areas'));

/* ------------------------------------------------------------- data load */

let debounce: ReturnType<typeof setTimeout> | undefined;

function scheduleLoad(): void {
    if (debounce !== undefined) {
        clearTimeout(debounce);
    }
    debounce = setTimeout(() => void load(), 250);
}

/*
 * Data-fetch generation token. Overlapping load() calls are legal — moveend,
 * a filter change, the Data retry — but only the NEWEST call may write
 * state. Without this, two in-flight fetches resolve in network order and a
 * slow stale response can overwrite fresher data, which is exactly the
 * failure the stale-data rule exists to prevent.
 */
let loadAttempt = 0;

async function load(): Promise<void> {
    const attempt = ++loadAttempt;
    const bounds = adapter.value?.getBounds() ?? null;

    // With no map the list still populates from Erbil's box — the map
    // failing must not take the data with it (same rule as the explorer).
    const box = bounds ?? { north: 36.35, south: 36.05, east: 44.15, west: 43.85 };

    const params = new URLSearchParams();
    params.set('north', String(box.north));
    params.set('south', String(box.south));
    params.set('east', String(box.east));
    params.set('west', String(box.west));
    params.append('layers[]', 'projects');
    if (showBoundaries.value && boundariesAvailable.value) {
        params.append('layers[]', 'areas');
    }
    activeTypes.value.forEach((type) => params.append('types[]', type));

    const zoom = adapter.value?.getZoom();
    if (zoom !== null && zoom !== undefined) {
        params.set('zoom', String(zoom));
    }

    loading.value = true;
    loadError.value = false;

    try {
        const response = await fetch(localized(`/invest/features?${params.toString()}`), {
            headers: { Accept: 'application/json' },
        });

        if (!response.ok) {
            throw new Error(String(response.status));
        }

        const data = await response.json();

        if (attempt !== loadAttempt) {
            return;
        }

        projects.value = data.projects ?? [];
        boundaries.value = showBoundaries.value ? (data.boundaries ?? emptyBoundaries) : emptyBoundaries;
        /*
         * The points are gated on the visitor's TOGGLE, never on the zoom
         * level: below the boundary threshold the polygon payload is
         * legitimately empty while the points still arrive, and they are
         * what keeps the area from disappearing on zoom out.
         */
        areaPoints.value = showBoundaries.value ? (data.areas ?? []) : [];
        projectBoundaries.value = data.project_boundaries ?? { type: 'FeatureCollection', features: [] };
        truncated.value = Boolean(data.truncated);

        // A selected project keeps its card fresh across refreshes — the
        // richer viewport row (price, trend) replaces a bare search result.
        if (selected.value) {
            const updated = projects.value.find((p) => p.id === selected.value?.id);
            if (updated) {
                selected.value = updated;
            }
        }
    } catch {
        // Kept deliberately: blanking the list on a failed refresh punishes
        // the visitor for a dropped request by taking away data they had.
        if (attempt === loadAttempt) {
            loadError.value = true;
        }
    } finally {
        if (attempt === loadAttempt) {
            loading.value = false;
            syncSource();
        }
    }
}

/* --------------------------------------------------------- map rendering */

function syncSource(): void {
    if (!mapReady.value) {
        return;
    }

    adapter.value?.setPoints([
        ...projects.value.map((project) => ({
            lat: project.lat,
            lng: project.lng,
            title: project.name ?? '',
            // The brand accent's default value — the gold the reference
            // design uses — while still reading as "the accent" beside the
            // rest of the page. Used only when no trend icon applies.
            colour: '#c9a227',
            id: project.id,
            /*
             * THE trend, on the marker itself: the adapter renders a
             * direction-shaped icon (chevron up/down, bar, neutral dot) so
             * price movement is readable on the map, not only in the card.
             * A null from an older payload degrades to `unknown` — a
             * missing claim, never a fabricated one.
             */
            trend: normaliseTrend(project.trend),
        })),
        /*
         * Area context as plain dots — the explorer's exact vocabulary: no
         * `id`, so a click cannot masquerade as a project selection, and no
         * trend, so the marker layer never implies a price claim for an
         * area. Present whenever the context toggle is on, at EVERY zoom —
         * below the boundary gate these dots are the area's only mark.
         */
        ...areaPoints.value.map((area) => ({
            lat: area.lat,
            lng: area.lng,
            title: area.name ?? '',
            colour: '#c9a227',
        })),
    ]);

    // One boundaries source: area context (when toggled) plus the projects'
    // own outlines. The adapter renders both with the gold accent.
    adapter.value?.setBoundaries({
        type: 'FeatureCollection',
        features: [...boundaries.value.features, ...projectBoundaries.value.features],
    });
}

/*
 * Selecting no longer forces the map tab: the bottom sheet teleports over
 * whichever tab is active, so a visitor choosing from the list stays on the
 * list — same pattern as the homepage map. The camera still moves, and the
 * hidden map absorbs it safely (the adapter resizes on reveal).
 */
function select(project: ProjectFeature): void {
    selected.value = project;
    adapter.value?.flyTo({ lat: project.lat, lng: project.lng }, 15);
}

/** Recover a live Google failure by rebuilding MapLibre, once. */
async function handleRuntimeFailure(): Promise<void> {
    if (renderedProvider.value !== 'google' || fallingBack) {
        // Nowhere left to fall back to: tear the dead adapter down instead
        // of leaving its context, worker and observer idling behind the
        // failure message for the rest of the visit.
        adapter.value?.destroy();
        adapter.value = null;
        mapReady.value = false;
        mapFailed.value = true;
        return;
    }

    fallingBack = true;
    mapReady.value = false;

    adapter.value?.destroy();
    adapter.value = null;

    /*
     * The fallback build claims a generation and the building flag exactly
     * like initialiseMap(). Without this, a compound failure (Google dies,
     * then the MapLibre fallback style dies too) re-enters this handler,
     * flips mapFailed while the first call is still unwinding, and a Retry
     * pressed in that window races the stale catch below — which would then
     * destroy the retry's healthy adapter. Token-gated, the stale call can
     * touch nothing that is no longer its own.
     */
    const attempt = ++mapAttempt;
    mapBuilding.value = true;

    try {
        const result = await createMapAdapter('maplibre', adapterOptions());

        if (disposed || attempt !== mapAttempt) {
            result.adapter.destroy();

            return;
        }

        adapter.value = result.adapter;
        renderedProvider.value = result.adapter.provider;
        clientFallbackReason.value = 'google-maps-runtime-failure';

        await result.adapter.ready();

        if (disposed || attempt !== mapAttempt) {
            return;
        }

        mapReady.value = true;
        mapFailed.value = false;
        void load();
    } catch {
        // The fallback build itself failed: destroy whatever was installed
        // before readiness rejected. After disposal the unmount hook already
        // owns the teardown.
        if (!disposed && attempt === mapAttempt) {
            adapter.value?.destroy();
            adapter.value = null;
            mapFailed.value = true;
        }
    } finally {
        if (!disposed && attempt === mapAttempt) {
            mapBuilding.value = false;
        }

        // Deliberately unguarded: a plain local with no template binding,
        // and leaving it stuck true would lock the fallback path forever.
        fallingBack = false;
    }
}

let fallingBack = false;

/*
 * Construction can outlive the page: an Inertia navigation away while the
 * adapter chunk is still importing unmounts against a null adapter ref, so
 * whichever adapter resolves afterwards — initial OR runtime-fallback —
 * must be destroyed here before installation, and no page state (or
 * pointless load()) may run after disposal.
 */
let disposed = false;

/*
 * Build generation token, the wizard picker's pattern: each call to
 * initialiseMap() claims a new generation, and an attempt that is no longer
 * the newest may not install an adapter or touch page state. The UI guard
 * (`mapBuilding`) already prevents overlapping builds; the token keeps a
 * stale build harmless even if a path around that guard ever appears.
 */
let mapAttempt = 0;

function adapterOptions() {
    return {
        container: container.value as HTMLElement,
        styleUrl: props.style_url,
        googleKey: props.google_key,
        centre: { lat: 36.19, lng: 44.009 },
        zoom: 12,
        // MULK-drawn text and pins tuned for the dark public basemap.
        labelScheme: 'dark' as const,
        // Pinned to Erbil and its suburbs: the surface is about one city,
        // and nobody should have to find it from a world view.
        minZoom: 10,
        maxZoom: 18,
        maxBounds: { west: 43.7, south: 35.95, east: 44.32, north: 36.42 },
        accentColour: '#c9a227',
        events: {
            onMoveEnd: () => scheduleLoad(),
            onClick: () => {
                selected.value = null;
            },
            onError: () => void handleRuntimeFailure(),
            // Selecting FROM THE MAP: a click on a project marker opens its
            // card, exactly like choosing it in the list.
            onMarkerClick: (id: number) => {
                const project = projects.value.find((candidate) => candidate.id === id);

                if (project) {
                    select(project);
                }
            },
        },
    };
}

async function initialiseMap(): Promise<void> {
    if (!container.value) {
        mapFailed.value = true;
        void load();
        return;
    }

    const attempt = ++mapAttempt;
    mapBuilding.value = true;

    try {
        const result = await createMapAdapter(props.provider, adapterOptions());

        // One check covers every provider outcome — Google, Google→MapLibre
        // fallback, plain MapLibre — they all resolve through this call.
        if (disposed || attempt !== mapAttempt) {
            result.adapter.destroy();

            return;
        }

        adapter.value = result.adapter;
        renderedProvider.value = result.adapter.provider;
        clientFallbackReason.value = result.fallbackReason ?? null;

        await result.adapter.ready();

        if (disposed || attempt !== mapAttempt) {
            return;
        }

        mapReady.value = true;
    } catch {
        // The adapter installed just above must not idle behind the failure
        // message: destroy it now — after disposal the unmount hook already
        // did, and no state may change.
        if (!disposed && attempt === mapAttempt) {
            adapter.value?.destroy();
            adapter.value = null;
            mapFailed.value = true;
        }
    } finally {
        if (!disposed && attempt === mapAttempt) {
            mapBuilding.value = false;
        }
    }

    if (disposed || attempt !== mapAttempt) {
        return;
    }

    void load();
}

/**
 * In-place recovery from a failed map: destroy whatever is left, reset the
 * failure state, and run the SAME construction path again — provider choice
 * and the Google→MapLibre construction fallback included. The admin pickers
 * have carried this lifecycle since Phase 2; this is the public-surface
 * counterpart. The `mapBuilding` guard makes a double tap a no-op instead
 * of a second adapter, and the fresh `mapAttempt` generation strands any
 * build this one supersedes.
 */
function retryMap(): void {
    if (mapBuilding.value || disposed) {
        return;
    }

    adapter.value?.destroy();
    adapter.value = null;
    mapReady.value = false;
    mapFailed.value = false;

    void initialiseMap();
}

/* -------------------------------------------------------------- lifecycle */

const goOnline = (): void => {
    offline.value = false;
    void load();
};
const goOffline = (): void => {
    offline.value = true;
};

onMounted(() => {
    window.addEventListener('online', goOnline);
    window.addEventListener('offline', goOffline);

    void initialiseMap();
});

onBeforeUnmount(() => {
    disposed = true;
    window.removeEventListener('online', goOnline);
    window.removeEventListener('offline', goOffline);
    if (debounce !== undefined) clearTimeout(debounce);
    adapter.value?.destroy();
});

watch(showBoundaries, () => void load());
</script>

<template>
    <Head :title="t('map.invest.title')" />

    <PublicLayout>
        <div class="mb-4">
            <p class="mh-lux-eyebrow mb-1">{{ t('nav.public.invest') }}</p>
            <h1 class="font-display text-2xl font-bold text-ink">{{ t('map.invest.title') }}</h1>
            <p class="mt-1 text-sm text-ink-muted">{{ t('map.invest.intro') }}</p>
        </div>

        <!-- Provider fallbacks are stated, never silent — same contract as
             the explorer. -->
        <AppAlert
            v-if="provider_fallback_reason === 'missing_key'"
            class="mb-3"
            variant="info"
            :message="t('map.states.provider_fallback')"
        />
        <AppAlert
            v-else-if="clientFallbackReason"
            class="mb-3"
            variant="warning"
            :message="t('map.states.provider_fallback_client')"
        />

        <AppAlert
            v-if="offline" class="mb-3" variant="warning"
            :message="`${t('map.states.offline')} — ${t('map.states.offline_hint')}`"
        />
        <!-- Provider failure carries its own recovery: Retry rebuilds the map
             in place — no full page reload required. -->
        <AppAlert v-else-if="mapFailed" class="mb-3" variant="warning">
            {{ t('map.states.provider_failed') }} — {{ t('map.states.provider_failed_hint') }}
            <button
                type="button"
                data-testid="map-retry"
                class="mh-lux-btn mh-lux-btn-secondary ms-3 !py-1.5 text-sm"
                :disabled="mapBuilding"
                @click="retryMap"
            >
                {{ t('map.states.retry') }}
            </button>
        </AppAlert>
        <!-- A failed refresh keeps the stale data (stated by the hint) and
             offers a DATA retry — a plain re-run of load(), never a map
             rebuild. -->
        <AppAlert v-if="loadError" class="mb-3" variant="danger">
            {{ t('map.states.error') }} — {{ t('map.states.error_hint') }}
            <button
                type="button"
                data-testid="data-retry"
                class="mh-lux-btn mh-lux-btn-secondary ms-3 !py-1.5 text-sm"
                :disabled="loading"
                @click="load"
            >
                {{ t('map.states.retry') }}
            </button>
        </AppAlert>
        <AppAlert v-if="truncated" class="mb-3" variant="warning" :message="t('map.zoom_in_notice')" />

        <!-- -------------------------------------------- search + filters -->
        <div class="mb-4 space-y-3">
            <div class="relative max-w-md">
                <label class="mh-label mb-1.5 block" for="invest-search">
                    {{ t('map.invest.search_label') }}
                </label>
                <input
                    id="invest-search"
                    v-model="searchQuery"
                    type="search"
                    class="mh-invest-search mh-touch-target w-full rounded-card px-3.5 py-2.5 text-sm text-ink"
                    :placeholder="t('map.invest.search_placeholder')"
                    autocomplete="off"
                    :aria-expanded="searchOpen"
                    aria-controls="invest-search-results"
                    @input="scheduleSearch"
                    @keydown.escape="searchOpen = false"
                >

                <div
                    v-if="searchOpen"
                    id="invest-search-results"
                    class="mh-invest-glass absolute inset-x-0 top-full z-20 mt-1 overflow-hidden rounded-card"
                >
                    <p v-if="searching" class="px-3.5 py-2.5 text-sm text-ink-muted">
                        {{ t('map.states.loading') }}
                    </p>
                    <p v-else-if="searchResults.length === 0" class="px-3.5 py-2.5 text-sm text-ink-muted">
                        {{ t('map.invest.search_empty') }}
                    </p>
                    <ul v-else>
                        <li v-for="result in searchResults" :key="result.id">
                            <button
                                type="button"
                                class="mh-touch-target block w-full px-3.5 py-2.5 text-start text-sm text-ink transition-colors hover:bg-surface-sunken focus-visible:bg-surface-sunken focus-visible:outline-none"
                                @click="chooseResult(result)"
                            >
                                {{ result.name }}
                                <span v-if="result.area" class="text-xs text-ink-muted"> — {{ result.area }}</span>
                            </button>
                        </li>
                    </ul>
                </div>
            </div>

            <div>
                <p class="mh-label mb-1.5">{{ t('map.invest.filters_label') }}</p>
                <div class="flex flex-wrap gap-2">
                    <button
                        v-for="type in types"
                        :key="type"
                        type="button"
                        class="mh-invest-chip"
                        :aria-pressed="activeTypes.includes(type)"
                        @click="toggleType(type)"
                    >
                        {{ t(`projects.types.${type}`) }}
                    </button>
                </div>
            </div>
        </div>

        <!-- Mobile: one surface at a time, selected explicitly. -->
        <div class="mb-3 flex gap-2 lg:hidden" role="tablist">
            <button
                type="button" role="tab" class="mh-invest-chip"
                :aria-pressed="mobileView === 'map'" :aria-selected="mobileView === 'map'"
                @click="mobileView = 'map'"
            >
                {{ t('map.map_view') }}
            </button>
            <button
                type="button" role="tab" class="mh-invest-chip"
                :aria-pressed="mobileView === 'list'" :aria-selected="mobileView === 'list'"
                @click="mobileView = 'list'"
            >
                {{ t('map.list_view') }}
                <span class="numeral">{{ projects.length }}</span>
            </button>
        </div>

        <div class="grid gap-4 lg:grid-cols-[minmax(0,1fr)_360px]">
            <!-- ------------------------------------------------------ map -->
            <section
                class="mh-lux-panel mh-lux-gilded relative overflow-hidden"
                :class="{ 'hidden lg:block': mobileView === 'list' }"
            >
                <div
                    ref="container"
                    class="mh-map-ground h-[420px] w-full lg:h-[560px]"
                    role="application"
                    :aria-label="t('map.invest.title')"
                />
                <div class="mh-invest-vignette" aria-hidden="true" />

                <!-- Zero projects is a STATE, not a failure: the basemap
                     stays live and pannable, and this floating notice says
                     so instead of the page going blank. Yields its spot to
                     the refetch pill and the refresh-failed chip below. -->
                <!-- All three status overlays sit at bottom-24 below lg:
                     the fixed bottom navigation (z-40, ~60px) paints over
                     the map's bottom edge whenever that edge meets the
                     viewport bottom, so an overlay at bottom-4 is exactly
                     where the nav hides it and swallows its taps. Measured
                     at 360×800: a bottom-4 chip lands fully inside the nav
                     band and pointer events reach the nav's /map link. -->
                <div
                    v-if="mapReady && !loading && !loadError && !hasResults"
                    class="pointer-events-none absolute inset-x-0 bottom-24 z-10 flex justify-center px-4 lg:bottom-4"
                    aria-live="polite"
                >
                    <p class="mh-map-toast">
                        {{ t('map.invest.map_empty_overlay') }}
                    </p>
                </div>

                <!-- NEW-REFETCH: after the first load, a viewport or filter
                     refetch was invisible on the mobile map tab — the only
                     signal lived in the list pane the map tab hides. This
                     pill states it politely, without veiling the live map or
                     the stale markers. -->
                <div
                    v-if="mapReady && loading"
                    data-testid="map-updating"
                    class="pointer-events-none absolute inset-x-0 bottom-24 z-10 flex justify-center px-4 lg:bottom-4"
                    aria-live="polite"
                >
                    <p class="mh-map-toast">
                        {{ t('map.states.loading_features') }}
                    </p>
                </div>

                <!-- A dropped refresh, stated where the visitor is looking:
                     the stale data stays (the hint says so) and Retry re-runs
                     the DATA fetch only — the live map is never rebuilt for a
                     failed refresh. -->
                <!-- No role="status" here: the loadError AppAlert above is
                     already a live region carrying the same words, and two
                     simultaneous regions announce the failure twice. -->
                <div
                    v-if="mapReady && loadError && !loading"
                    data-testid="map-refetch-failed"
                    class="absolute inset-x-0 bottom-24 z-10 flex justify-center px-4 lg:bottom-4"
                >
                    <div class="mh-map-toast mh-map-toast--error">
                        <span class="text-xs text-ink">
                            {{ t('map.states.error') }} — {{ t('map.states.error_hint') }}
                        </span>
                        <button
                            type="button"
                            data-testid="data-retry-overlay"
                            class="mh-invest-chip disabled:cursor-not-allowed disabled:opacity-50"
                            :disabled="loading"
                            @click="load"
                        >
                            {{ t('map.states.retry') }}
                        </button>
                    </div>
                </div>

                <!-- REV-P3-RETRY: the explorer states its failure inside the
                     canvas too; invest now matches, with the in-place Retry. -->
                <div
                    v-if="mapFailed"
                    class="absolute inset-0 z-10 grid place-items-center bg-surface-sunken p-6 text-center text-sm
                           text-ink-muted"
                >
                    <div>
                        <p>{{ t('map.states.provider_failed_hint') }}</p>
                        <button
                            type="button"
                            data-testid="map-retry-overlay"
                            class="mh-lux-btn mh-lux-btn-secondary mt-3"
                            :disabled="mapBuilding"
                            @click="retryMap"
                        >
                            {{ t('map.states.retry') }}
                        </button>
                    </div>
                </div>

                <!-- Floating chrome: the boundary toggle, glass over tiles. -->
                <div
                    v-if="boundariesAvailable && mapReady"
                    class="absolute start-3 top-3 flex gap-2"
                >
                    <button
                        type="button"
                        class="mh-invest-chip"
                        :aria-pressed="showBoundaries"
                        @click="showBoundaries = !showBoundaries"
                    >
                        {{ t('map.invest.boundaries_label') }}
                    </button>
                </div>

                <!-- The companion (§18 of the map brief): the product's own
                     AI face, as a quiet guide in the map's corner. Desktop
                     only — on a phone every pixel over the map is expensive
                     and §19 forbids exactly this kind of overlay there. It is
                     DECORATION: aria-hidden face, no live region (the
                     selected card's role=status already announces), no
                     timers, no cursor tracking; its only motion is the eye
                     pulse the global reduced-motion rule stops. -->
                <!-- Compacted (Map Phase 1): the companion keeps its quiet
                     corner presence as the avatar alone — the text panel made
                     it a standing glass notice over the lower map, and the
                     selection card already announces the selected project. -->
                <div
                    class="mh-invest-glass absolute bottom-3 end-3 hidden rounded-full p-1.5 lg:flex"
                    aria-hidden="true"
                >
                    <AiAvatar class="h-9 w-9 shrink-0" :glow="false" />
                </div>

                <!-- Selected project on DESKTOP: glass card floating over the
                     map. Below lg the same content rides in the bottom sheet
                     after the grid — never a floating popover on a phone.
                     z-10 keeps the card above the map's own corner chrome. -->
                <div
                    v-if="selected && isDesktop"
                    class="mh-invest-glass absolute bottom-3 start-3 z-10 max-w-[calc(100%-1.5rem)] rounded-card p-4 sm:max-w-sm"
                    role="status"
                >
                    <p class="mh-lux-eyebrow mb-1">{{ t('map.invest.selected') }}</p>
                    <p class="font-semibold text-ink">{{ selected.name }}</p>
                    <p v-if="selected.area" class="mt-0.5 text-sm text-ink-muted">{{ selected.area }}</p>
                    <p v-if="selected.type" class="mt-0.5 text-xs text-ink-faint">
                        {{ t(`projects.types.${selected.type}`) }}
                    </p>
                    <p v-if="selected.price_from" class="numeral mt-2 text-sm text-ink">
                        {{ t('map.invest.price_from_label') }}
                        {{ formatNumber(Number(selected.price_from)) }}
                        <span class="text-xs text-ink-muted">{{ selected.currency }}</span>
                        <span
                            v-if="selected.trend && selected.trend !== 'unknown'"
                            class="ms-2 text-xs font-semibold"
                            :class="{
                                'text-positive': selected.trend === 'up',
                                'text-negative': selected.trend === 'down',
                                'text-caution': selected.trend === 'flat',
                            }"
                        >
                            <span aria-hidden="true">{{ trendArrow(selected.trend) }}</span>
                            {{ selected.trend_percent }}%
                            <span class="sr-only">{{ t(`map.invest.trend.${selected.trend}`) }}</span>
                        </span>
                        <!-- Unknown is a stated absence, not a hidden one:
                             screen readers hear that no trend is claimed. -->
                        <span v-else-if="selected.trend === 'unknown'" class="sr-only">
                            {{ t('map.invest.trend.unknown') }}
                        </span>
                    </p>
                    <div class="mt-3 flex items-center gap-3">
                        <Link
                            :href="localized(`/projects/${selected.slug}`)"
                            class="mh-lux-btn mh-lux-btn-primary !py-1.5 text-sm"
                        >
                            {{ t('map.invest.view_project') }}
                        </Link>
                        <button
                            type="button"
                            class="mh-lux-btn mh-lux-btn-ghost !py-1.5 text-sm"
                            @click="selected = null"
                        >
                            {{ t('app.actions.close') }}
                        </button>
                    </div>
                </div>
            </section>

            <!-- ----------------------------------------------------- list -->
            <section
                :class="{ 'hidden lg:block': mobileView === 'map' }"
                :aria-label="t('map.invest.projects_label')"
            >
                <div class="mb-2 flex items-baseline justify-between gap-2">
                    <p class="mh-label">{{ t('map.invest.projects_label') }}</p>
                    <!-- Refetch feedback for the list view too: the old
                         gate (`loading && !hasResults`) could never show
                         again once results existed, so a refresh looked
                         like nothing was happening. -->
                    <span v-if="loading" class="ms-auto text-xs text-ink-faint">
                        {{ t('map.states.loading_features') }}
                    </span>
                    <p class="numeral text-xs text-ink-faint">{{ projects.length }}</p>
                </div>

                <p v-if="loading && !hasResults" class="text-sm text-ink-muted">
                    {{ t('map.states.loading_features') }}
                </p>

                <div v-else-if="!hasResults" class="mh-lux-card p-5 text-center">
                    <p class="text-sm text-ink">{{ t('map.invest.empty') }}</p>
                    <p class="mt-1 text-xs text-ink-muted">{{ t('map.invest.empty_hint') }}</p>
                </div>

                <ul v-else class="max-h-[560px] space-y-2 overflow-y-auto pe-1">
                    <li v-for="project in projects" :key="project.id">
                        <div
                            class="mh-lux-card mh-lux-card-interactive p-3.5"
                            :class="{ '!border-accent/60': selected?.id === project.id }"
                        >
                            <button
                                type="button"
                                class="mh-touch-target block w-full text-start focus-visible:outline-none"
                                @click="select(project)"
                            >
                                <p class="text-sm font-semibold text-ink">{{ project.name }}</p>
                                <p v-if="project.area" class="mt-0.5 text-xs text-ink-muted">
                                    {{ project.area }}
                                </p>
                                <p v-if="project.price_from" class="numeral mt-1 text-xs text-ink">
                                    {{ formatNumber(Number(project.price_from)) }}
                                    <span class="text-ink-muted">{{ project.currency }}</span>
                                    <span
                                        v-if="project.trend && project.trend !== 'unknown'"
                                        class="ms-1.5 font-semibold"
                                        :class="{
                                            'text-positive': project.trend === 'up',
                                            'text-negative': project.trend === 'down',
                                            'text-caution': project.trend === 'flat',
                                        }"
                                    >
                                        <span aria-hidden="true">{{ trendArrow(project.trend) }}</span>
                                        {{ project.trend_percent }}%
                                        <span class="sr-only">{{ t(`map.invest.trend.${project.trend}`) }}</span>
                                    </span>
                                </p>
                            </button>
                            <Link
                                :href="localized(`/projects/${project.slug}`)"
                                class="mh-touch-target mt-2 inline-flex items-center gap-1.5 text-xs font-medium text-accent"
                            >
                                {{ t('map.invest.view_project') }}
                                <AppIcon name="arrow-end" class="h-3.5 w-3.5 rtl:-scale-x-100" />
                            </Link>
                        </div>
                    </li>
                </ul>
            </section>
        </div>

        <!-- Selected project below lg: the same card content as a bottom
             sheet (teleported, so it overlays either mobile tab). Content
             mirrors the desktop card exactly — same strings, same numeral
             bidi isolation, same stated-absence rule for an unknown trend. -->
        <MobileBottomSheet
            :open="selected !== null && !isDesktop"
            :title="selected?.name ?? undefined"
            :detents="['peek', 'half']"
            @close="selected = null"
        >
            <template v-if="selected">
                <p class="mh-lux-eyebrow mb-1">{{ t('map.invest.selected') }}</p>
                <p v-if="selected.area" class="text-sm text-ink-muted">{{ selected.area }}</p>
                <p v-if="selected.type" class="mt-0.5 text-xs text-ink-faint">
                    {{ t(`projects.types.${selected.type}`) }}
                </p>
                <p v-if="selected.price_from" class="numeral mt-3 text-base text-ink">
                    {{ t('map.invest.price_from_label') }}
                    {{ formatNumber(Number(selected.price_from)) }}
                    <span class="text-sm text-ink-muted">{{ selected.currency }}</span>
                    <span
                        v-if="selected.trend && selected.trend !== 'unknown'"
                        class="ms-2 text-sm font-semibold"
                        :class="{
                            'text-positive': selected.trend === 'up',
                            'text-negative': selected.trend === 'down',
                            'text-caution': selected.trend === 'flat',
                        }"
                    >
                        <span aria-hidden="true">{{ trendArrow(selected.trend) }}</span>
                        {{ selected.trend_percent }}%
                        <span class="sr-only">{{ t(`map.invest.trend.${selected.trend}`) }}</span>
                    </span>
                    <!-- Unknown is a stated absence, not a hidden one. -->
                    <span v-else-if="selected.trend === 'unknown'" class="sr-only">
                        {{ t('map.invest.trend.unknown') }}
                    </span>
                </p>
                <Link
                    :href="localized(`/projects/${selected.slug}`)"
                    class="mh-lux-btn mh-lux-btn-primary mt-4 w-full"
                >
                    {{ t('map.invest.view_project') }}
                </Link>
            </template>
        </MobileBottomSheet>
    </PublicLayout>
</template>
