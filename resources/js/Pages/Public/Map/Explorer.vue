<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref, shallowRef, watch } from 'vue';
import { Head, Link } from '@inertiajs/vue3';
import PublicLayout from '@/Layouts/PublicLayout.vue';
import AppAlert from '@/Components/ui/AppAlert.vue';
import { t, formatNumber } from '@/lib/i18n';
import { useLocale } from '@/Composables/useLocale';
import AreaIntelligenceCard, { type AreaIntel } from '@/Components/Public/AreaIntelligenceCard.vue';
import CompareAreasPanel, { type CompareResponse } from '@/Components/Public/CompareAreasPanel.vue';
import MobileBottomSheet from '@/Components/Public/MobileBottomSheet.vue';
import { useIsDesktop } from '@/Composables/useIsDesktop';
import {
    boundaryBounds,
    createMapAdapter,
    poiCategoryFor,
    type BoundaryCollection,
    type BoundaryIdentity,
    type MapAdapter,
    type MapBounds,
} from '@/lib/map';
import { trendColour } from '@/lib/map/trend';

/*
 * The public Map Explorer (File one §5, File two §10).
 *
 * Four behaviours here are not conveniences. They are the difference between a
 * usable map and an unusable one on Erbil mobile data:
 *
 *   - FEATURES ARE FETCHED PER VIEWPORT. An unbounded request returns every
 *     place in the city before the first tile renders.
 *
 *   - THERE IS ALWAYS A LIST. §5.10 requires a fallback when maps fail, and the
 *     list is not a degraded mode shown after an error — it is rendered beside
 *     the map always, and is the primary view on a narrow screen. A visitor
 *     whose tiles never load, or who simply prefers reading, gets the same data.
 *
 *   - MARKERS ARE A CLUSTERED GeoJSON SOURCE, not DOM nodes. Three hundred
 *     absolutely-positioned divs is what makes a mid-range Android drop frames
 *     while panning; MapLibre clusters on the GPU.
 *
 *   - EVERY DISTANCE IS LABELLED STRAIGHT-LINE. §10.5. The server sends
 *     `distance_method` and `travel_time_available`; nothing here derives a
 *     duration from a distance, because that number would be a fabrication
 *     that reads as a measurement.
 */
const { localized } = useLocale();

interface Feature {
    id: number;
    name?: string | null;
    title?: string | null;
    lat: number;
    lng: number;
    slug?: string;
    area?: string | null;
    area_slug?: string;
    public_id?: string;
    company?: string | null;
    company_slug?: string;
    category?: string | null;
    colour?: string | null;
    is_sponsored?: boolean;
    disclosure?: string | null;
    value?: string | null;
    currency?: string | null;
    period?: string | null;
    direction?: string | null;
    change_percent?: string | null;
    sample_size?: number | null;
    requires_qualifier?: boolean;
    distance_km: number | null;
    distance_method: string | null;
    travel_time_minutes: number | null;
}

type LayerKey = 'projects' | 'areas' | 'places' | 'offers' | 'companies' | 'prices';

const props = defineProps<{
    style_url: string | null;
    provider: string;
    provider_requested: string;
    provider_fallback_reason: string | null;
    google_key: string | null;
    layers: Array<{ key: string; flag: string | null }>;
    categories: Array<{ key: string; name: string | null; icon: string | null; colour: string | null }>;
    distance: { unit: string; method: string; travel_time_available: boolean };
    limits: { max_per_layer: number; max_radius_km: number };
    /** Map Phase 4: whether the Market heatmap mode may exist at all. */
    market: { available: boolean };
}>();

/* ------------------------------------------------------------------ state */

const container = ref<HTMLDivElement | null>(null);
const adapter = shallowRef<MapAdapter | null>(null);

/*
 * Which provider ACTUALLY rendered, and why it differs from the request.
 * `provider` from the server is the intent; this is the outcome. They can
 * disagree — a present, well-formed key can still be rejected at request time
 * for a referrer restriction, which the server cannot know.
 */
const renderedProvider = ref<string | null>(null);
const clientFallbackReason = ref<string | null>(null);
const emptyBoundaries: BoundaryCollection = { type: 'FeatureCollection', features: [] };
const boundaries = ref<BoundaryCollection>(emptyBoundaries);

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
const permissionDenied = ref(false);
const mobileView = ref<'map' | 'list'>('map');

const availableKeys = computed(() => props.layers.map((layer) => layer.key as LayerKey));
const active = ref<LayerKey[]>([]);
const activeCategories = ref<string[]>([]);

const empty: Record<LayerKey, Feature[]> = {
    projects: [], areas: [], places: [], offers: [], companies: [], prices: [],
};
const features = ref<Record<LayerKey, Feature[]>>({ ...empty });

/* Radius search */
const centre = ref<{ lat: number; lng: number } | null>(null);
const radiusKm = ref<number | null>(null);
const pickingCentre = ref(false);

/* Drawn area */
const drawing = ref(false);
const ring = ref<Array<{ lat: number; lng: number }>>([]);

/* ----------------------------------------------- selected area (Phase 3) */

const isDesktop = useIsDesktop();
const { isRtl } = useLocale();

/*
 * ONE canonical selected-area state. Every entry path — polygon click, the
 * area list, live location — resolves into selectArea() and renders the
 * same card; there is no second selection system to drift from this one.
 */
const selectedArea = ref<BoundaryIdentity | null>(null);
const areaIntel = ref<AreaIntel | null>(null);
const areaIntelPhase = ref<'loading' | 'ready' | 'error' | 'rate_limited'>('loading');
/** Mobile sheet visibility; desktop renders the floating card instead. */
const areaSheetOpen = ref(false);

/*
 * Race safety (§23): only the LATEST selection may populate the card. The
 * attempt token invalidates stale responses outright and the controller
 * aborts the in-flight request so a quick A→B click never renders A late.
 */
let areaAttempt = 0;
let areaAbort: AbortController | null = null;

function armBoundaryInteraction(): void {
    adapter.value?.setBoundaryInteractive?.(!pickingCentre.value && !drawing.value);
}

// Centre-pick and draw KEEP their click meanings even over a polygon: the
// adapter's boundary interaction is simply off while a mode is active.
watch([pickingCentre, drawing], () => armBoundaryInteraction());

function selectArea(identity: BoundaryIdentity, focus = true, hint?: AreaFocusHint): void {
    // A repeated tap on the already-selected area re-frames and re-opens
    // the sheet, but never burns a second resolve round-trip (and with it
    // the shared rate limit) on data that is already here or in flight.
    const repeat = selectedArea.value?.slug === identity.slug
        && (areaIntelPhase.value === 'ready' || areaIntelPhase.value === 'loading');

    selectedArea.value = identity;
    areaSheetOpen.value = true;
    adapter.value?.setSelectedBoundary?.(identity.slug);

    if (focus) {
        focusArea(identity, hint);
    }

    if (!repeat) {
        void fetchAreaIntel(identity.slug);
    }
}

function clearArea(): void {
    areaAttempt += 1;
    areaAbort?.abort();
    areaAbort = null;
    selectedArea.value = null;
    areaIntel.value = null;
    areaSheetOpen.value = false;
    adapter.value?.setSelectedBoundary?.(null);
}

/**
 * Search results carry navigation data an unloaded area cannot supply from
 * the viewport (Phase 5 §21): the server's cached bbox and centroid ride
 * along as LAST-RESORT camera fallbacks — loaded geometry, when present,
 * still wins.
 */
interface AreaFocusHint {
    bounds?: MapBounds | null;
    point?: { lat: number; lng: number } | null;
}

/*
 * Camera focus happens ONCE, at the moment of explicit selection (§15) —
 * never from a watcher — with padding reserving room for the desktop card
 * (on the START side, so the physical side follows the locale) or the
 * mobile sheet. A polygon absent from the loaded boundaries (zoomed-out
 * entry from the list) falls back to the area's centroid point.
 */
function focusArea(identity: BoundaryIdentity, hint?: AreaFocusHint): void {
    const map = adapter.value;

    if (!map) return;

    /*
     * Desktop reserves the card's START-side float (340px + gutters); the
     * physical side follows the locale. Mobile reserves a modest bottom band
     * — the sheet overlays the page, not the 420px map panel, and a heavier
     * reservation would force the fit below BOUNDARY_MIN_ZOOM and unload
     * the very polygon that was just selected.
     */
    const padding = isDesktop.value
        ? {
            top: 56,
            bottom: 56,
            left: isRtl.value ? 56 : 396,
            right: isRtl.value ? 396 : 56,
        }
        : { top: 48, bottom: 120, left: 24, right: 24 };

    const feature = boundaries.value.features.find(
        (candidate) => (candidate.properties as { slug?: string } | undefined)?.slug === identity.slug,
    );
    // Loaded geometry first; the search response's cached bbox only when
    // the polygon is not in this viewport's payload (§21) — the fit then
    // moves the camera, and the ordinary moveend fetch brings the real
    // geometry in for the selection styling to land on.
    const box = (feature ? boundaryBounds(feature.geometry) : null) ?? hint?.bounds ?? null;

    if (box && map.fitBounds) {
        map.fitBounds(box, { padding, maxZoom: 15 });

        return;
    }

    const row = features.value.areas.find((area) => area.slug === identity.slug) ?? hint?.point ?? null;

    if (row) {
        map.flyTo({ lat: row.lat, lng: row.lng }, 13);
    }
}

async function fetchAreaIntel(slug: string): Promise<void> {
    const attempt = ++areaAttempt;

    areaAbort?.abort();
    const controller = new AbortController();
    areaAbort = controller;

    areaIntelPhase.value = 'loading';

    try {
        const response = await fetch(localized(`/location/resolve?area=${encodeURIComponent(slug)}`), {
            headers: { Accept: 'application/json' },
            signal: controller.signal,
        });

        if (attempt !== areaAttempt) return;

        if (response.status === 429) {
            areaIntelPhase.value = 'rate_limited';

            return;
        }

        if (!response.ok) {
            areaIntelPhase.value = 'error';

            return;
        }

        const payload = (await response.json()) as AreaIntel;

        if (attempt !== areaAttempt) return;

        areaIntel.value = payload;
        areaIntelPhase.value = 'ready';

        // The payload's identity is locale-fresh; adopt it so a language
        // switch mid-session never leaves a stale name on the card.
        if (payload.area) {
            selectedArea.value = { slug: payload.area.slug, name: payload.area.name, type: payload.area.type };
        }
    } catch (error) {
        if ((error as { name?: string }).name === 'AbortError' || attempt !== areaAttempt) return;

        areaIntelPhase.value = 'error';
    }
}

function retryAreaIntel(): void {
    if (selectedArea.value) {
        void fetchAreaIntel(selectedArea.value.slug);
    }
}

/**
 * A service group activated from the card (§16): enable the places layer
 * and toggle exactly that group's categories — never everything at once,
 * and the existing zoom gates and marker priority stay untouched.
 */
function toggleServiceGroup(group: { categories: Array<{ key: string }> }): void {
    const keys = group.categories.map((category) => category.key);

    if (!active.value.includes('places') && availableKeys.value.includes('places')) {
        active.value = [...active.value, 'places'];
    }

    const allOn = keys.every((key) => activeCategories.value.includes(key));

    activeCategories.value = allOn
        ? activeCategories.value.filter((key) => !keys.includes(key))
        : [...new Set([...activeCategories.value, ...keys])];

    scheduleLoad();
}

function selectAreaFromRow(feature: Feature): void {
    if (!feature.slug) return;

    selectArea({ slug: feature.slug, name: feature.name ?? '', type: (feature as { type?: string }).type ?? '' });
}

/* ---------------------------------------------- unified search (Phase 5) */

/*
 * One trilingual search over MULK's own Areas, Projects and Places —
 * /map/search, never a geocoder. Selection is NAVIGATION: an area result
 * lands in the Phase 3 canonical selection (same card, same highlight), a
 * project/place result flies the camera and leaves a compact context strip
 * with the real profile route. Nothing here touches the Market mode, its
 * filters, or the visitor's radius/drawn-area state — the query goes to
 * the server bare, so text search is city-wide while spatial filters keep
 * governing the layer fetches they always governed.
 */

interface SearchAreaRow {
    kind: 'area';
    slug: string;
    name: string;
    type: string;
    type_label: string;
    breadcrumb: Array<{ name: string }>;
    lat: number | null;
    lng: number | null;
    bounds: MapBounds | null;
}

interface SearchProjectRow {
    kind: 'project';
    slug: string;
    name: string;
    project_type: string;
    area_name: string | null;
    area_slug: string | null;
    lat: number;
    lng: number;
}

interface SearchPlaceRow {
    kind: 'place';
    slug: string;
    name: string;
    category: string | null;
    category_name: string | null;
    area_name: string | null;
    lat: number;
    lng: number;
}

type SearchRow = SearchAreaRow | SearchProjectRow | SearchPlaceRow;

const searchQuery = ref('');
const searchOpen = ref(false);
const searchPhase = ref<'loading' | 'ready' | 'error' | 'rate_limited'>('ready');
const searchGroups = ref<{ areas: SearchAreaRow[]; projects: SearchProjectRow[]; places: SearchPlaceRow[] }>({
    areas: [], projects: [], places: [],
});
/** Keyboard-active option, as an index into searchFlat; −1 = none. */
const searchActive = ref(-1);
const searchInput = ref<HTMLInputElement | null>(null);

/**
 * Compact context for a searched project/place — name, containing area,
 * and the profile route. Mutually exclusive with the Area card (§25): a
 * project/place choice clears the area selection, and any area selection
 * retires this strip, so the map never claims two subjects at once.
 */
const searchContext = ref<{ kind: 'project' | 'place'; slug: string; name: string; area_name: string | null } | null>(null);

watch(selectedArea, (identity) => {
    if (identity !== null) searchContext.value = null;
});

let searchAttempt = 0;
let searchAbort: AbortController | null = null;
let searchDebounce: ReturnType<typeof setTimeout> | undefined;

const searchFlat = computed<SearchRow[]>(() =>
    (mapMode.value === 'compare'
        ? [...searchGroups.value.areas]
        : [
            ...searchGroups.value.areas,
            ...searchGroups.value.projects,
            ...searchGroups.value.places,
        ]));

/** Grouped for rendering, with each option's global index for the combobox. */
const searchGrouped = computed(() => {
    let index = 0;
    const build = (key: 'areas' | 'projects' | 'places', rows: SearchRow[]) =>
        ({ key, rows: rows.map((row) => ({ row, index: index++ })) });

    // Compare mode picks AREAS (Phase 6 §26): the same search, narrowed to
    // the one group a comparison slot can hold.
    if (mapMode.value === 'compare') {
        return [build('areas', searchGroups.value.areas)]
            .filter((group) => group.rows.length > 0);
    }

    return [
        build('areas', searchGroups.value.areas),
        build('projects', searchGroups.value.projects),
        build('places', searchGroups.value.places),
    ].filter((group) => group.rows.length > 0);
});

const searchActiveId = computed(() =>
    (searchOpen.value && searchActive.value >= 0 ? `map-search-option-${searchActive.value}` : undefined));

const searchContextHref = computed(() => {
    const context = searchContext.value;

    if (!context) return null;

    return context.kind === 'project'
        ? localized(`/projects/${context.slug}`)
        : localized(`/places/${context.slug}`);
});

/** The secondary line under a result: what it is, and where. */
function searchRowMeta(row: SearchRow): string {
    if (row.kind === 'area') {
        const crumbs = row.breadcrumb.map((crumb) => crumb.name).filter((name) => name !== '');

        return crumbs.length > 0 ? `${row.type_label} · ${crumbs.join(' · ')}` : row.type_label;
    }

    const label = row.kind === 'project' ? t(`projects.types.${row.project_type}`) : (row.category_name ?? '');

    if (row.area_name === null || row.area_name === '') return label;

    return label === '' ? row.area_name : `${label} · ${row.area_name}`;
}

function onSearchInput(): void {
    searchActive.value = -1;

    if (searchDebounce !== undefined) clearTimeout(searchDebounce);

    const query = searchQuery.value.trim();

    // Below the server's own minimum there is nothing to ask (§6/§17):
    // strand any in-flight answer so it cannot reopen a closed dropdown.
    if (query.length < 2) {
        searchAttempt += 1;
        searchAbort?.abort();
        searchAbort = null;
        searchGroups.value = { areas: [], projects: [], places: [] };
        searchPhase.value = 'ready';
        searchOpen.value = false;

        return;
    }

    searchOpen.value = true;
    searchPhase.value = 'loading';
    searchDebounce = setTimeout(() => {
        void runSearch(query);
    }, 300);
}

async function runSearch(query: string): Promise<void> {
    // Generation token + abort (§17): M→Mu→Muf→Mufti may overlap on the
    // wire, but only the LATEST query's answer may render.
    const attempt = ++searchAttempt;

    searchAbort?.abort();
    const controller = new AbortController();
    searchAbort = controller;

    searchPhase.value = 'loading';

    try {
        const response = await fetch(localized(`/map/search?q=${encodeURIComponent(query)}`), {
            headers: { Accept: 'application/json' },
            signal: controller.signal,
        });

        if (attempt !== searchAttempt) return;

        if (response.status === 429) {
            searchPhase.value = 'rate_limited';

            return;
        }

        if (!response.ok) {
            searchPhase.value = 'error';

            return;
        }

        const payload = (await response.json()) as {
            groups?: { areas?: SearchAreaRow[]; projects?: SearchProjectRow[]; places?: SearchPlaceRow[] };
        };

        if (attempt !== searchAttempt) return;

        searchGroups.value = {
            areas: payload.groups?.areas ?? [],
            projects: payload.groups?.projects ?? [],
            places: payload.groups?.places ?? [],
        };
        searchPhase.value = 'ready';
        searchActive.value = -1;
    } catch (error) {
        if ((error as { name?: string }).name === 'AbortError' || attempt !== searchAttempt) return;

        searchPhase.value = 'error';
    }
}

function retrySearch(): void {
    const query = searchQuery.value.trim();

    if (query.length >= 2) void runSearch(query);
}

function closeSearch(): void {
    searchOpen.value = false;
    searchActive.value = -1;
}

function onSearchFocus(): void {
    if (searchQuery.value.trim().length >= 2) {
        searchOpen.value = true;
    }
}

function scrollActiveOption(): void {
    if (typeof document === 'undefined' || searchActive.value < 0) return;

    document.getElementById(`map-search-option-${searchActive.value}`)?.scrollIntoView({ block: 'nearest' });
}

function onSearchKeydown(event: KeyboardEvent): void {
    if (event.key === 'Escape') {
        if (searchOpen.value) {
            event.preventDefault();
            closeSearch();
        }

        return;
    }

    if (!searchOpen.value) {
        if ((event.key === 'ArrowDown' || event.key === 'ArrowUp') && searchQuery.value.trim().length >= 2) {
            event.preventDefault();
            searchOpen.value = true;
        }

        return;
    }

    const count = searchFlat.value.length;

    if (event.key === 'ArrowDown' && count > 0) {
        event.preventDefault();
        searchActive.value = (searchActive.value + 1) % count;
        scrollActiveOption();
    } else if (event.key === 'ArrowUp' && count > 0) {
        event.preventDefault();
        searchActive.value = searchActive.value <= 0 ? count - 1 : searchActive.value - 1;
        scrollActiveOption();
    } else if (event.key === 'Enter') {
        const row = searchFlat.value[searchActive.value];

        if (searchActive.value >= 0 && row) {
            event.preventDefault();
            chooseResult(row);
        }
    }
}

function chooseResult(row: SearchRow): void {
    closeSearch();
    // Blurring also closes the on-screen keyboard, so on a phone the map
    // the visitor just navigated is actually visible (§35).
    searchInput.value?.blur();
    searchQuery.value = row.name;
    mobileView.value = 'map';

    if (row.kind === 'area') {
        chooseArea(row);
    } else if (row.kind === 'project') {
        chooseProject(row);
    } else {
        choosePlace(row);
    }
}

function chooseArea(row: SearchAreaRow): void {
    // Compare mode's picker IS this search (Phase 6 §26): an area result
    // fills the next A/B/C slot instead of opening the Phase 3 card.
    if (mapMode.value === 'compare') {
        addComparedArea(row);

        return;
    }

    // Phase 3's canonical selection — same card, same polygon highlight,
    // same resolve budget — with the response's cached bbox/centroid as
    // the camera fallback for an area outside the loaded viewport (§21).
    // Explore/Market mode and every market filter stay untouched (§20/§26).
    selectArea({ slug: row.slug, name: row.name, type: row.type }, true, {
        bounds: row.bounds,
        point: row.lat !== null && row.lng !== null ? { lat: row.lat, lng: row.lng } : null,
    });
}

function chooseProject(row: SearchProjectRow): void {
    clearArea();

    // The marker arrives the ordinary way: layer on, camera there, and the
    // debounced viewport fetch loads it — no second lookup, no DOM marker.
    if (!active.value.includes('projects') && availableKeys.value.includes('projects')) {
        active.value = [...active.value, 'projects'];
    }

    adapter.value?.flyTo({ lat: row.lat, lng: row.lng }, 15);
    scheduleLoad();
    searchContext.value = { kind: 'project', slug: row.slug, name: row.name, area_name: row.area_name };
}

function choosePlace(row: SearchPlaceRow): void {
    clearArea();

    // Enable the places layer and THIS category only (§27) — never all
    // categories, and never switching an unrelated one off.
    if (availableKeys.value.includes('places')) {
        if (!active.value.includes('places')) {
            active.value = [...active.value, 'places'];
        }

        if (row.category !== null && !activeCategories.value.includes(row.category)) {
            activeCategories.value = [...activeCategories.value, row.category];
        }
    }

    adapter.value?.flyTo({ lat: row.lat, lng: row.lng }, 16);
    scheduleLoad();
    searchContext.value = { kind: 'place', slug: row.slug, name: row.name, area_name: row.area_name };
}

/* --------------------------------------------- market heatmap (Phase 4) */

/*
 * The Market mode: the SAME explorer, wearing the market engine's answer
 * on its polygons. Every figure and direction below comes from
 * /map/market, which derives through MarketMovementService — the exact
 * rules the Market pulse panel answers from. This page adds no arithmetic:
 * an area the engine made no claim about simply is not in the payload,
 * and its polygon stays untinted. Unknown is never dressed up as flat.
 */

interface MarketHeatRow {
    area_slug: string;
    direction: 'up' | 'down' | 'flat';
    change_percent: string | null;
    current_value: string;
    previous_value: string;
    currency: string;
    basis: string;
    transaction: string;
    price_type: string;
    family: string;
    property_type: string | null;
    requires_qualifier: boolean;
    period_current: string;
    period_previous: string;
    sample_size: number | null;
    confidence: string;
}

interface MarketHeatResponse {
    available: boolean;
    reason: string | null;
    transaction: string;
    window: string;
    property_type: string | null;
    windows: Record<string, boolean>;
    property_types: string[];
    rows: MarketHeatRow[];
    truncated: boolean;
}

/** The movement product's window vocabulary, verbatim — never a new one. */
const MARKET_WINDOWS = ['7d', '30d', '1m', '3m', '6m', '1y', 'all'] as const;

const MARKET_TRANSACTIONS = ['sale', 'rent'] as const;

const mapMode = ref<'explore' | 'market' | 'compare'>('explore');

/**
 * The mode vocabulary: Explore always, Market only where the market
 * feature exists, Compare always (Phase 6 — it needs only the explorer
 * itself; each metric states its own availability).
 */
const mapModes = computed<Array<'explore' | 'market' | 'compare'>>(() =>
    (props.market.available ? ['explore', 'market', 'compare'] : ['explore', 'compare']));
const marketTransaction = ref<string>('sale');
const marketWindow = ref<string>('all');
/** null = the spanning all-categories index — the product's honest "all". */
const marketPropertyType = ref<string | null>(null);
const marketData = ref<MarketHeatResponse | null>(null);
const marketPhase = ref<'idle' | 'loading' | 'ready' | 'error'>('idle');
const marketBelowZoom = ref(false);

/** Adopted from the features response, never hardcoded twice. */
const boundaryZoomThreshold = ref(11);

let marketAttempt = 0;
let marketAbort: AbortController | null = null;

function setMapMode(mode: 'explore' | 'market' | 'compare'): void {
    if (mapMode.value === mode) return;

    const previous = mapMode.value;

    mapMode.value = mode;

    // Leaving a mode retires ITS claims on the map — heat clears exactly
    // as it always has; compare outlines clear while the selected set is
    // kept, so returning to Compare restores it.
    if (previous === 'market') {
        marketAttempt += 1;
        marketAbort?.abort();
        marketAbort = null;
        marketPhase.value = 'idle';
        marketBelowZoom.value = false;
        adapter.value?.setMarketHeat?.(null);
    }

    if (previous === 'compare') {
        compareAttempt += 1;
        compareAbort?.abort();
        compareAbort = null;
        focusedCompared.value = null;
        adapter.value?.setComparedBoundaries?.(null);
    }

    if (mode === 'market' || mode === 'compare') {
        // Heat paints polygons and compare outlines them, and polygons only
        // arrive with the areas layer — entering either mode switches it on
        // the ordinary way, visible on the ordinary chip.
        if (!active.value.includes('areas')) {
            active.value = [...active.value, 'areas'];
            void load();
        }
    }

    if (mode === 'market') {
        void fetchMarketHeat();
    }

    if (mode === 'compare') {
        /*
         * §33: Compare owns the multi-area state; the Phase 3 single-area
         * card would contradict it, so entering the mode clears any open
         * selection and boundary clicks focus comparison columns instead.
         */
        clearArea();
        adapter.value?.setComparedBoundaries?.(
            comparedAreas.value.length > 0
                ? comparedAreas.value.map((area) => area.slug)
                : null,
        );

        if (comparedAreas.value.length >= 2) {
            void fetchCompare();
        }
    }
}

async function fetchMarketHeat(): Promise<void> {
    if (mapMode.value !== 'market') return;

    const attempt = ++marketAttempt;

    marketAbort?.abort();
    const controller = new AbortController();
    marketAbort = controller;

    const zoom = adapter.value?.getZoom();
    const bounds = adapter.value?.getBounds();

    // Below the boundary gate there is nothing to paint: state it instead
    // of fetching heat for polygons the server will not send.
    marketBelowZoom.value = zoom !== null && zoom !== undefined && zoom < boundaryZoomThreshold.value;

    if (marketBelowZoom.value || !bounds) {
        adapter.value?.setMarketHeat?.(null);

        /*
         * Missing bounds while the map is still CONSTRUCTING is not an
         * answer — hold the loading voice; the map-ready paths re-invoke
         * this fetch the moment bounds exist, so entering the mode during
         * the adapter chunk's load self-corrects instead of stranding a
         * silent, dataless "ready". A genuinely failed map settles as
         * ready: there is nothing to paint on, and the failure overlay
         * already owns that story.
         */
        marketPhase.value = bounds !== null && bounds !== undefined
            ? 'ready'
            : (mapFailed.value ? 'ready' : 'loading');

        return;
    }

    if (marketData.value === null) {
        marketPhase.value = 'loading';
    }

    try {
        const params = new URLSearchParams();
        params.set('north', String(bounds.north));
        params.set('south', String(bounds.south));
        params.set('east', String(bounds.east));
        params.set('west', String(bounds.west));
        params.set('transaction', marketTransaction.value);
        params.set('period', marketWindow.value);
        if (marketPropertyType.value !== null) {
            params.set('property_type', marketPropertyType.value);
        }

        const response = await fetch(localized(`/map/market?${params.toString()}`), {
            headers: { Accept: 'application/json' },
            signal: controller.signal,
        });

        if (attempt !== marketAttempt) return;

        if (!response.ok) {
            marketPhase.value = 'error';

            return;
        }

        const payload = (await response.json()) as MarketHeatResponse;

        if (attempt !== marketAttempt) return;

        marketData.value = payload;
        marketPhase.value = 'ready';

        const heat: Record<string, 'up' | 'down' | 'flat'> = {};

        for (const row of payload.rows) {
            heat[row.area_slug] = row.direction;
        }

        adapter.value?.setMarketHeat?.(payload.rows.length > 0 ? heat : null);
    } catch (error) {
        if ((error as { name?: string }).name === 'AbortError' || attempt !== marketAttempt) return;

        marketPhase.value = 'error';
    }
}

/*
 * §37: ONE shared filter state. Compare inherits whatever Market holds and
 * writes back to the same refs, so the two modes can never contradict;
 * each pick refreshes only the active mode's data (fetchMarketHeat and
 * fetchCompare both no-op outside their own mode), and §38 holds by
 * construction — the compared areas live in their own state.
 */
function pickMarketTransaction(mode: string): void {
    if (marketTransaction.value === mode) return;

    marketTransaction.value = mode;
    void fetchMarketHeat();
    void fetchCompare();
}

function pickMarketWindow(key: string): void {
    if (marketWindow.value === key) return;

    marketWindow.value = key;
    void fetchMarketHeat();
    void fetchCompare();
}

function pickMarketPropertyType(value: string | null): void {
    if (marketPropertyType.value === value) return;

    marketPropertyType.value = value;
    void fetchMarketHeat();
    void fetchCompare();
}

/* The category vocabulary comes from the server's enum exposure — a case
 * added to PropertyType appears here without touching this page. */
const marketPropertyTypes = computed<string[]>(() => marketData.value?.property_types ?? []);

/* The pulse panel's convention: the selected window stays operable (its
 * emptiness is explained in the notice); every OTHER window with no honest
 * pair is disabled with the reason on the control. */
const marketWindowDisabled = (key: string): boolean =>
    marketPhase.value === 'ready'
    && !marketBelowZoom.value
    && key !== marketWindow.value
    && !(marketData.value?.windows?.[key] ?? false);

const marketNotice = computed<string | null>(() => {
    if (mapMode.value !== 'market') return null;

    if (marketPhase.value === 'loading') return t('market.movement.loading');

    if (marketPhase.value !== 'ready') return null;

    if (marketBelowZoom.value) return t('map.market.zoom_hint');

    const data = marketData.value;

    if (data !== null && !data.available && data.reason !== null) {
        return t(`market.movement.reasons.${data.reason}`);
    }

    return null;
});

/* ---------------------------------------------- area comparison (Phase 6) */

/*
 * Compare mode: 2–3 areas side by side, every figure composed server-side
 * by /map/compare from the existing authorities. ONE canonical compared
 * set — the slot chips, the map outlines and the panel columns all derive
 * from `comparedAreas`; a lightweight `focusedCompared` marks the column
 * under inspection (§33) without resurrecting the Phase 3 card. The
 * Phase 5 search doubles as the picker: in Compare mode an area result
 * ADDS a slot instead of selecting, and the dropdown offers areas only.
 */

interface ComparedArea {
    slug: string;
    name: string;
    type: string;
    lat: number | null;
    lng: number | null;
    bounds: MapBounds | null;
}

const comparedAreas = ref<ComparedArea[]>([]);
const focusedCompared = ref<string | null>(null);
const compareData = ref<CompareResponse | null>(null);
const comparePhase = ref<'idle' | 'loading' | 'ready' | 'error'>('idle');

/** Transient picker feedback (duplicate / full) — compact, auto-clearing. */
const compareNotice = ref<string | null>(null);
let compareNoticeTimer: ReturnType<typeof setTimeout> | undefined;

let compareAttempt = 0;
let compareAbort: AbortController | null = null;

function showCompareNotice(message: string): void {
    compareNotice.value = message;

    if (compareNoticeTimer !== undefined) clearTimeout(compareNoticeTimer);
    compareNoticeTimer = setTimeout(() => {
        compareNotice.value = null;
    }, 5000);
}

async function fetchCompare(): Promise<void> {
    if (mapMode.value !== 'compare' || comparedAreas.value.length < 2) return;

    const attempt = ++compareAttempt;

    compareAbort?.abort();
    const controller = new AbortController();
    compareAbort = controller;

    comparePhase.value = 'loading';

    try {
        const params = new URLSearchParams();
        comparedAreas.value.forEach((area) => params.append('areas[]', area.slug));
        params.set('transaction', marketTransaction.value);
        params.set('period', marketWindow.value);
        if (marketPropertyType.value !== null) {
            params.set('property_type', marketPropertyType.value);
        }

        const response = await fetch(localized(`/map/compare?${params.toString()}`), {
            headers: { Accept: 'application/json' },
            signal: controller.signal,
        });

        if (attempt !== compareAttempt) return;

        if (!response.ok) {
            comparePhase.value = 'error';

            return;
        }

        const payload = (await response.json()) as CompareResponse;

        if (attempt !== compareAttempt) return;

        compareData.value = payload;
        comparePhase.value = 'ready';
    } catch (error) {
        if ((error as { name?: string }).name === 'AbortError' || attempt !== compareAttempt) return;

        // §53: the failure is stated in the panel with a retry; the slots
        // and the map stay exactly as they were.
        comparePhase.value = 'error';
    }
}

function retryCompare(): void {
    void fetchCompare();
}

/**
 * Re-derive everything from the canonical set: outlines, data, camera.
 * The camera fits ONLY here — on add/remove — and on the explicit
 * Show-all action, never while the visitor pans (§32).
 */
function syncCompare(): void {
    adapter.value?.setComparedBoundaries?.(
        comparedAreas.value.length > 0
            ? comparedAreas.value.map((area) => area.slug)
            : null,
    );

    // Every add/remove that leaves a non-empty set re-frames it — the very
    // FIRST pick included, so the visitor sees the area they just chose.
    if (comparedAreas.value.length > 0) {
        fitComparedAreas();
    }

    if (comparedAreas.value.length >= 2) {
        void fetchCompare();

        return;
    }

    compareAttempt += 1;
    compareAbort?.abort();
    compareAbort = null;
    compareData.value = null;
    comparePhase.value = 'idle';
}

function addComparedArea(row: SearchAreaRow): void {
    if (comparedAreas.value.some((area) => area.slug === row.slug)) {
        showCompareNotice(t('map.compare.duplicate'));

        return;
    }

    if (comparedAreas.value.length >= 3) {
        showCompareNotice(t('map.compare.full'));

        return;
    }

    comparedAreas.value = [...comparedAreas.value, {
        slug: row.slug,
        name: row.name,
        type: row.type,
        lat: row.lat,
        lng: row.lng,
        bounds: row.bounds,
    }];
    syncCompare();
}

function removeComparedArea(slug: string): void {
    comparedAreas.value = comparedAreas.value.filter((area) => area.slug !== slug);

    if (focusedCompared.value === slug) {
        focusedCompared.value = null;
    }

    syncCompare();
}

function focusComparedColumn(slug: string): void {
    focusedCompared.value = focusedCompared.value === slug ? null : slug;
}

/** Focus the Phase 5 search input — the compare picker IS that search. */
function requestCompareAddition(): void {
    searchInput.value?.focus();
}

/**
 * Fit the camera to every compared area at once, from the cached bboxes
 * (centroid fallback) — never a geometry fetch (§32).
 */
function fitComparedAreas(): void {
    const map = adapter.value;

    if (!map?.fitBounds) return;

    let north = -90;
    let south = 90;
    let east = -180;
    let west = 180;
    let any = false;

    for (const area of comparedAreas.value) {
        const box = area.bounds
            ?? (area.lat !== null && area.lng !== null
                ? { north: area.lat, south: area.lat, east: area.lng, west: area.lng }
                : null);

        if (box === null) continue;

        any = true;
        north = Math.max(north, box.north);
        south = Math.min(south, box.south);
        east = Math.max(east, box.east);
        west = Math.min(west, box.west);
    }

    if (!any) return;

    const padding = isDesktop.value
        ? { top: 56, bottom: 56, left: 56, right: 56 }
        : { top: 48, bottom: 120, left: 24, right: 24 };

    map.fitBounds({ north, south, east, west }, { padding, maxZoom: 14 });
}

/**
 * Window chips disable under each mode's OWN evidence map, with the pulse
 * panel's shared convention: the selected window stays operable.
 */
function windowChipDisabled(key: string): boolean {
    if (mapMode.value === 'compare') {
        return comparePhase.value === 'ready'
            && key !== marketWindow.value
            && !(compareData.value?.windows?.[key] ?? false);
    }

    return marketWindowDisabled(key);
}

/** The category vocabulary from whichever mode's response is live. */
const filterPropertyTypes = computed<string[]>(() =>
    (mapMode.value === 'compare'
        ? compareData.value?.property_types ?? []
        : marketPropertyTypes.value));

/**
 * §51: with the movement feature off the filters would govern nothing the
 * comparison can show — hide them rather than render dead controls; the
 * panel states the unavailability in words.
 */
const compareFiltersHidden = computed(() =>
    mapMode.value === 'compare'
    && compareData.value !== null
    && compareData.value.movement.reason === 'feature_disabled');

const flat = computed<Feature[]>(() =>
    availableKeys.value.flatMap((key) => (active.value.includes(key) ? features.value[key] : [])),
);

const hasResults = computed(() => flat.value.length > 0);
const distanceApplied = computed(() => centre.value !== null);

/* ------------------------------------------------------------- data load */

let debounce: ReturnType<typeof setTimeout> | undefined;

function scheduleLoad(): void {
    if (debounce !== undefined) {
        clearTimeout(debounce);
    }
    debounce = setTimeout(() => {
        void load();

        // Market heat follows the viewport under the same debounce; its
        // own generation token keeps overlapping fetches honest.
        if (mapMode.value === 'market') {
            void fetchMarketHeat();
        }
    }, 250);
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
    if (active.value.length === 0) {
        // Claim the generation too: a fetch still in flight must not
        // resurrect layers the visitor just switched off — and since its
        // stale finally-block may no longer touch state, the loading flag
        // is settled here.
        loadAttempt += 1;
        features.value = { ...empty };
        loading.value = false;
        return;
    }

    const attempt = ++loadAttempt;

    const bounds = adapter.value?.getBounds() ?? null;

    // With no map (provider failure, or offline before first render) the list
    // cannot be populated by viewport. Erbil's bounding box is used so the
    // list still has content rather than sitting permanently empty — the map
    // failing must not take the data with it.
    const box = bounds ?? { north: 36.35, south: 36.05, east: 44.15, west: 43.85 };

    const params = new URLSearchParams();
    params.set('north', String(box.north));
    params.set('south', String(box.south));
    params.set('east', String(box.east));
    params.set('west', String(box.west));
    active.value.forEach((layer) => params.append('layers[]', layer));

    // Zoom gates the boundary payload server-side. Sent even when the map
    // failed, in which case it is absent and no polygons come back — correct,
    // because there is nothing to draw them on.
    const zoom = adapter.value?.getZoom();
    if (zoom !== null && zoom !== undefined) {
        params.set('zoom', String(zoom));
    }
    activeCategories.value.forEach((category) => params.append('categories[]', category));

    if (centre.value && radiusKm.value !== null) {
        params.set('center_lat', String(centre.value.lat));
        params.set('center_lng', String(centre.value.lng));
        params.set('radius_km', String(radiusKm.value));
    } else if (centre.value) {
        params.set('center_lat', String(centre.value.lat));
        params.set('center_lng', String(centre.value.lng));
    }

    if (ring.value.length >= 3) {
        ring.value.forEach((point, index) => {
            params.append(`polygon[${index}][lat]`, String(point.lat));
            params.append(`polygon[${index}][lng]`, String(point.lng));
        });
    }

    loading.value = true;
    loadError.value = false;

    try {
        const response = await fetch(localized(`/map/features?${params.toString()}`), {
            headers: { Accept: 'application/json' },
        });

        if (!response.ok) {
            throw new Error(String(response.status));
        }

        const data = await response.json();

        if (attempt !== loadAttempt) {
            return;
        }

        features.value = {
            projects: data.projects ?? [],
            areas: data.areas ?? [],
            places: data.places ?? [],
            offers: data.offers ?? [],
            companies: data.companies ?? [],
            prices: data.prices ?? [],
        };
        boundaries.value = data.boundaries ?? emptyBoundaries;
        truncated.value = Boolean(data.truncated);

        // The server states its own boundary zoom gate; the market mode's
        // "zoom in" hint reads it from here rather than hardcoding 11 twice.
        const threshold = Number(data.boundary_zoom_threshold);
        if (Number.isFinite(threshold)) {
            boundaryZoomThreshold.value = threshold;
        }
    } catch {
        // The previously loaded features are kept deliberately. Blanking the
        // list on a failed refresh punishes a visitor for a dropped request by
        // taking away data they already had.
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

    /*
     * Places and record markers are separate visual languages (Map Phase 2):
     * projects/offers/companies/prices stay on the clustered point source,
     * while places ride the Phase 1 POI overlay — subdued, zoom-gated dots
     * BENEATH every record marker, so ambient context never competes with
     * the records the visitor is actually here for. The LIST keeps showing
     * place rows either way; only the map rendering splits.
     */
    adapter.value?.setPoints(
        availableKeys.value
            .flatMap((key) => (key !== 'places' && active.value.includes(key) ? features.value[key] : []))
            .map((feature) => ({
                lat: feature.lat,
                lng: feature.lng,
                title: feature.name ?? feature.title ?? '',
                colour: feature.colour ?? '#1f6feb',
            })),
    );

    // Optional capability, feature-tested like setPin — the Google adapter
    // may not implement it, and the layer degrades to list-only there.
    adapter.value?.setPois?.(
        active.value.includes('places')
            ? features.value.places.map((feature) => ({
                lat: feature.lat,
                lng: feature.lng,
                title: feature.name ?? feature.title ?? '',
                category: poiCategoryFor(feature.category),
            }))
            : [],
    );

    adapter.value?.setBoundaries(boundaries.value);
}

/**
 * Recover from a provider failure that happens once the map is already live.
 *
 * Google is torn down and MapLibre built in its place. Guarded by
 * `renderedProvider` so a MapLibre failure does not loop trying to replace
 * MapLibre with MapLibre — at that point there is nowhere left to go and the
 * list view is the answer.
 */
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
        armBoundaryInteraction();
        mapFailed.value = false;
        void load();

        // A Market mode entered while the map was still constructing was
        // held at "loading"; real bounds exist now, so complete it.
        if (mapMode.value === 'market') {
            void fetchMarketHeat();
        }
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

/** Adapter construction options, shared by the initial build and the fallback. */
function adapterOptions() {
    return {
        container: container.value as HTMLElement,
        styleUrl: props.style_url,
        googleKey: props.google_key,
        centre: { lat: 36.19, lng: 44.009 },
        zoom: 11,
        // MULK-drawn text and pins tuned for the dark public basemap.
        labelScheme: 'dark' as const,
        events: {
            onMoveEnd: () => {
                if (!pickingCentre.value && !drawing.value) {
                    scheduleLoad();
                }
            },
            onClick: (point: { lat: number; lng: number }) => {
                if (pickingCentre.value) {
                    centre.value = point;
                    pickingCentre.value = false;
                    void load();
                    return;
                }

                if (drawing.value) {
                    ring.value = [...ring.value, point];

                    return;
                }

                /*
                 * Empty-map click (§24). Cluster and polygon hits were
                 * already claimed inside the adapter's priority order, so a
                 * click arriving here landed on bare map — it dismisses the
                 * area selection and NOTHING else: layers, filters and the
                 * drawn ring all stay. (A click on an inert record marker
                 * also falls through to here by adapter design; dismissing
                 * on it reads as the ordinary "tap elsewhere closes".)
                 */
                if (selectedArea.value) {
                    clearArea();
                }
            },
            /*
             * Polygon selection (Phase 3). The adapter enforces the click
             * priority — project marker > POI > polygon > empty map — and
             * only emits for a click no record layer claimed, so wiring
             * this cannot change what marker or cluster clicks do.
             */
            onBoundarySelect: (identity: BoundaryIdentity) => {
                /*
                 * Compare mode (§33): clicking a COMPARED polygon focuses
                 * its panel column; any other polygon does nothing — the
                 * Phase 3 card never opens over the comparison. Outside
                 * Compare mode the canonical selection is untouched.
                 */
                if (mapMode.value === 'compare') {
                    if (comparedAreas.value.some((area) => area.slug === identity.slug)) {
                        focusComparedColumn(identity.slug);
                    }

                    return;
                }

                selectArea(identity);
            },
            onError: () => {
                // A Google failure AFTER construction — a revoked key, a tile
                // 403, billing disabled mid-session — must still fall back,
                // not just leave a grey canvas. Catching only constructor
                // exceptions covered the first case and none of the later ones.
                void handleRuntimeFailure();
            },
        },
    };
}

async function initialiseMap(): Promise<void> {
    if (!container.value) {
        // Parity with the invest page: without a container the map cannot
        // exist, so state it as a failure and still fetch the list — a bare
        // return left "!mapReady && !mapFailed" true and the loading veil
        // up forever.
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
        clientFallbackReason.value = result.fallbackReason;

        await result.adapter.ready();

        if (disposed || attempt !== mapAttempt) {
            return;
        }

        mapReady.value = true;
        armBoundaryInteraction();
        void load();

        // Same self-correction as the fallback path: a Market mode entered
        // before construction finished completes now that bounds exist.
        if (mapMode.value === 'market') {
            void fetchMarketHeat();
        }
    } catch {
        // Both providers are gone. The list keeps working, which is why it is
        // rendered as a peer of the map rather than as a degraded mode. The
        // adapter installed just above must not idle behind the failure
        // message: destroy it now — after disposal the unmount hook already
        // did, and no state may change.
        if (!disposed && attempt === mapAttempt) {
            adapter.value?.destroy();
            adapter.value = null;
            mapFailed.value = true;
            void load();
        }
    } finally {
        if (!disposed && attempt === mapAttempt) {
            mapBuilding.value = false;
        }
    }
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

/* ------------------------------------------------------------- controls */

function toggleLayer(key: LayerKey): void {
    active.value = active.value.includes(key)
        ? active.value.filter((entry) => entry !== key)
        : [...active.value, key];
    void load();
}

function toggleCategory(key: string): void {
    activeCategories.value = activeCategories.value.includes(key)
        ? activeCategories.value.filter((entry) => entry !== key)
        : [...activeCategories.value, key];
    void load();
}

function clearFilters(): void {
    activeCategories.value = [];
    centre.value = null;
    radiusKm.value = null;
    ring.value = [];
    drawing.value = false;
    pickingCentre.value = false;
    void load();
}

function finishDrawing(): void {
    drawing.value = false;
    if (ring.value.length >= 3) {
        void load();
    }
}

function clearDrawing(): void {
    ring.value = [];
    drawing.value = false;
    void load();
}

/* --------------------------------------------- live location (Phase 3) */

/*
 * Geolocation discipline, ported from the homepage location card (§6–§7):
 * the browser API is touched ONLY inside this click handler, and the fix is
 * used transiently — it becomes the distance-search centre exactly as it
 * has since this button shipped (the same /map/features refresh a manually
 * tapped centre triggers), centres the camera, and goes ONCE to the
 * existing /location/resolve to name the containing Area. It is never
 * written to storage, analytics or logs, and never leaves those two
 * same-origin MULK endpoints; from then on the visitor is an Area
 * identity, not a coordinate pair.
 */
const GEOLOCATION_TIMEOUT_MS = 10_000;

/*
 * The permission prompt pauses the geolocation timeout clock in Chromium,
 * so a prompt dismissed without an answer can leave the button "locating"
 * forever unless a wall-clock watchdog stands behind it.
 */
const GEOLOCATION_WATCHDOG_MS = 15_000;

const locating = ref(false);
let locateAttempt = 0;
let locateWatchdog: ReturnType<typeof setTimeout> | undefined;

/** Transient location-outcome toast (§8) — compact, auto-clearing, never a blocker. */
const locationNotice = ref<string | null>(null);
let locationNoticeTimer: ReturnType<typeof setTimeout> | undefined;

function showLocationNotice(message: string): void {
    locationNotice.value = message;

    if (locationNoticeTimer !== undefined) clearTimeout(locationNoticeTimer);
    locationNoticeTimer = setTimeout(() => {
        locationNotice.value = null;
    }, 6000);
}

/*
 * A locate that claimed the selection generation aborted whatever fetch a
 * still-rendered selection had in flight. When the locate then produces no
 * selection of its own (denied, unavailable, outside coverage, failed),
 * the card must not sit on a permanent spinner — re-issue the visible
 * selection's fetch. Callers only invoke this while their claim is still
 * the current generation, so a newer selection's own fetch is never
 * duplicated.
 */
function recoverPendingSelection(): void {
    if (selectedArea.value && areaIntelPhase.value === 'loading') {
        void fetchAreaIntel(selectedArea.value.slug);
    }
}

function useMyLocation(): void {
    if (typeof navigator === 'undefined' || !navigator.geolocation) {
        permissionDenied.value = true;
        return;
    }

    if (locating.value) {
        return;
    }

    const attempt = ++locateAttempt;

    /*
     * My Location IS a selection action (§23), so it claims the shared
     * selection generation NOW, at the click — not when the GPS finally
     * answers. A polygon or list selection made while the fix is still
     * acquiring mints a newer generation and wins: the stale fix then
     * touches neither the camera nor the card. Without this, a slow
     * acquisition would land AFTER a faster explicit selection and
     * silently replace it.
     */
    const claim = ++areaAttempt;

    areaAbort?.abort();
    areaAbort = null;

    locating.value = true;
    permissionDenied.value = false;
    locationNotice.value = null;

    if (locateWatchdog !== undefined) clearTimeout(locateWatchdog);
    locateWatchdog = setTimeout(() => {
        if (attempt === locateAttempt && locating.value) {
            // The watchdog is the deadline: strand the attempt so a fix
            // arriving after "unavailable" was announced changes nothing.
            locateAttempt += 1;
            locating.value = false;
            showLocationNotice(t('home.location.unavailable'));

            if (claim === areaAttempt) recoverPendingSelection();
        }
    }, GEOLOCATION_WATCHDOG_MS);

    navigator.geolocation.getCurrentPosition(
        (position) => {
            if (attempt !== locateAttempt) return;

            clearTimeout(locateWatchdog);
            locating.value = false;

            // A newer explicit selection superseded this locate while the
            // browser was acquiring: drop the fix entirely — no camera
            // yank, no centre change, no resolve.
            if (claim !== areaAttempt) return;

            const point = { lat: position.coords.latitude, lng: position.coords.longitude };

            // Exactly the pre-Phase-3 behaviour first: the fix becomes the
            // distance centre and the camera goes there…
            centre.value = point;
            adapter.value?.flyTo(point, 13);
            void load();

            // …then the SAME resolver every other entry path uses turns the
            // point into an Area selection, under the claim made above.
            void resolveMyLocation(point, claim);
        },
        (error) => {
            if (attempt !== locateAttempt) return;

            clearTimeout(locateWatchdog);
            locating.value = false;

            if (error.code === error.PERMISSION_DENIED) {
                // Denial is expected and recoverable: the centre can still be
                // set by tapping the map, so this is a notice rather than an
                // error — and the map stays fully usable manually.
                permissionDenied.value = true;
            } else {
                showLocationNotice(t('home.location.unavailable'));
            }

            if (claim === areaAttempt) recoverPendingSelection();
        },
        // A cached fix younger than a minute is plenty for area resolution
        // and skips a second GPS spin-up.
        { timeout: GEOLOCATION_TIMEOUT_MS, maximumAge: 60_000 },
    );
}

/**
 * Live-location → Area (§6): the /location/resolve coordinate mode — the
 * SAME endpoint, contract and rate limiter as the homepage card; no second
 * resolution algorithm anywhere. The camera is already at the visitor's
 * position, so adopting the payload skips the refit and a second fetch.
 * Runs under the selection generation useMyLocation claimed at CLICK time
 * (`claim`): any newer explicit selection invalidates every step here.
 */
async function resolveMyLocation(point: { lat: number; lng: number }, claim: number): Promise<void> {
    if (claim !== areaAttempt) return;

    // The click already aborted any older request when it claimed the
    // generation; this registers the locate fetch as the one a NEWER
    // selection must abort.
    const controller = new AbortController();
    areaAbort = controller;

    try {
        const response = await fetch(
            localized(`/location/resolve?lat=${point.lat}&lng=${point.lng}`),
            { headers: { Accept: 'application/json' }, signal: controller.signal },
        );

        if (claim !== areaAttempt) return;

        if (response.status === 429) {
            showLocationNotice(t('home.location.rate_limited'));
            recoverPendingSelection();

            return;
        }

        if (!response.ok) {
            showLocationNotice(t('home.location.error'));
            recoverPendingSelection();

            return;
        }

        const payload = (await response.json()) as AreaIntel;

        if (claim !== areaAttempt) return;

        if (payload.state === 'outside_coverage' || payload.area === null) {
            // §19: outside coverage is stated honestly — no nearest-area
            // guess — the map stays exactly where the visitor is, and a
            // previously selected area keeps its card.
            showLocationNotice(t('home.location.outside'));
            recoverPendingSelection();

            return;
        }

        // `no_data` still SELECTS the area (§19): the card itself renders
        // the honest no-price line. Only the identity is kept — never the
        // coordinates that produced it.
        selectedArea.value = { slug: payload.area.slug, name: payload.area.name, type: payload.area.type };
        areaSheetOpen.value = true;
        adapter.value?.setSelectedBoundary?.(payload.area.slug);
        areaIntel.value = payload;
        areaIntelPhase.value = 'ready';
    } catch (error) {
        if ((error as { name?: string }).name === 'AbortError' || claim !== areaAttempt) return;

        showLocationNotice(t('home.location.error'));
        recoverPendingSelection();
    }
}

function hrefFor(feature: Feature, layer: LayerKey): string | null {
    if (layer === 'projects' && feature.slug) return localized(`/projects/${feature.slug}`);
    if (layer === 'places' && feature.slug) return localized(`/places/${feature.slug}`);
    if (layer === 'areas' && feature.slug) return localized(`/areas/${feature.slug}`);
    if (layer === 'offers' && feature.public_id) return localized(`/offers/${feature.public_id}`);
    if (layer === 'companies' && feature.company_slug) return localized(`/companies/${feature.company_slug}`);
    if (layer === 'prices' && feature.area_slug) return localized(`/areas/${feature.area_slug}`);
    return null;
}

function labelFor(feature: Feature): string {
    return feature.name ?? feature.title ?? feature.area ?? '—';
}

/* ------------------------------------------------------------ lifecycle */

function goOnline(): void {
    offline.value = false;
    void load();
}
function goOffline(): void {
    offline.value = true;
}

onMounted(() => {
    active.value = availableKeys.value.includes('projects')
        ? ['projects']
        : availableKeys.value.slice(0, 1);

    window.addEventListener('online', goOnline);
    window.addEventListener('offline', goOffline);

    void initialiseMap();
});

onBeforeUnmount(() => {
    disposed = true;
    window.removeEventListener('online', goOnline);
    window.removeEventListener('offline', goOffline);
    if (debounce !== undefined) clearTimeout(debounce);
    // Strand the location/selection async work the same way `disposed`
    // strands map builds: bump the tokens, abort the in-flight resolve,
    // stop the timers. A geolocation callback landing after this touches
    // nothing.
    locateAttempt += 1;
    areaAttempt += 1;
    areaAbort?.abort();
    marketAttempt += 1;
    marketAbort?.abort();
    searchAttempt += 1;
    searchAbort?.abort();
    compareAttempt += 1;
    compareAbort?.abort();
    if (searchDebounce !== undefined) clearTimeout(searchDebounce);
    if (compareNoticeTimer !== undefined) clearTimeout(compareNoticeTimer);
    if (locateWatchdog !== undefined) clearTimeout(locateWatchdog);
    if (locationNoticeTimer !== undefined) clearTimeout(locationNoticeTimer);
    adapter.value?.destroy();
});

watch(flat, () => syncSource());
</script>

<template>
    <Head :title="t('nav.public.map')" />

    <PublicLayout>
        <h1 class="mb-4 font-display text-2xl font-bold text-ink">{{ t('nav.public.map') }}</h1>

        <!-- Feature-disabled is enforced server-side (404). This covers the
             narrower case of a deployment with every layer switched off. -->
        <AppAlert
            v-if="layers.length === 0"
            variant="info"
            :message="`${t('map.states.no_layers')} — ${t('map.states.disabled_hint')}`"
        />

        <template v-else>
            <!-- Provider fallback is stated, not silent. -->
            <!-- Two distinct fallbacks, both stated. The server's (no key
                 configured) and the browser's (key present but rejected,
                 script blocked, or timed out). Reporting only the first left
                 the second invisible. -->
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

            <!-- Provider failure carries its own recovery: Retry rebuilds the
                 map in place — no full page reload required. -->
            <AppAlert v-else-if="mapFailed" class="mb-3" variant="warning">
                {{ t('map.states.provider_failed') }} — {{ t('map.states.provider_failed_hint') }}
                <button
                    type="button"
                    data-testid="map-retry"
                    class="mh-touch-target ms-3 rounded-card border border-line px-3 py-1 text-sm text-ink
                           transition-colors hover:bg-surface-sunken disabled:cursor-not-allowed disabled:opacity-50
                           focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-accent"
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
                    class="mh-touch-target ms-3 rounded-card border border-line px-3 py-1 text-sm text-ink
                           transition-colors hover:bg-surface-sunken disabled:cursor-not-allowed disabled:opacity-50
                           focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-accent"
                    :disabled="loading"
                    @click="load"
                >
                    {{ t('map.states.retry') }}
                </button>
            </AppAlert>

            <AppAlert
                v-if="permissionDenied" class="mb-3" variant="info"
                :message="`${t('map.states.permission_denied')} — ${t('map.states.permission_denied_hint')}`"
            />

            <AppAlert v-if="truncated" class="mb-3" variant="warning" :message="t('map.zoom_in_notice')" />

            <!-- ------------------------------------------------- controls -->
            <div class="mb-4 space-y-3">
                <!-- Unified search (Phase 5): MULK's own areas, projects and
                     places, trilingual, in the invest search's glass voice —
                     compact above the map, never a page-wide panel. A real
                     combobox: debounced, race-guarded, keyboard-complete. -->
                <div class="relative max-w-md">
                    <label class="mh-label mb-1.5 block" for="map-search">{{ t('map.discovery.label') }}</label>
                    <input
                        id="map-search"
                        ref="searchInput"
                        v-model="searchQuery"
                        type="search"
                        role="combobox"
                        class="mh-invest-search mh-touch-target w-full rounded-card px-3.5 py-2.5 text-sm text-ink"
                        :placeholder="t('map.discovery.placeholder')"
                        autocomplete="off"
                        data-testid="map-search-input"
                        :aria-expanded="searchOpen"
                        aria-controls="map-search-listbox"
                        aria-autocomplete="list"
                        :aria-activedescendant="searchActiveId"
                        @input="onSearchInput"
                        @focus="onSearchFocus"
                        @blur="closeSearch"
                        @keydown="onSearchKeydown"
                    >

                    <!-- @mousedown.prevent keeps the input focused through a
                         click inside the dropdown, so blur-to-close can never
                         race the option's own click handler. -->
                    <div
                        v-if="searchOpen"
                        id="map-search-listbox"
                        role="listbox"
                        :aria-label="t('map.discovery.label')"
                        data-testid="map-search-results"
                        class="mh-invest-glass absolute inset-x-0 top-full z-30 mt-1 max-h-[min(60vh,420px)]
                               overflow-y-auto rounded-card"
                        @mousedown.prevent
                    >
                        <p
                            v-if="searchPhase === 'loading' && searchFlat.length === 0"
                            class="px-3.5 py-2.5 text-sm text-ink-muted"
                        >
                            {{ t('map.discovery.searching') }}
                        </p>
                        <p
                            v-else-if="searchPhase === 'rate_limited'"
                            data-testid="map-search-error"
                            class="px-3.5 py-2.5 text-sm text-ink-muted"
                        >
                            {{ t('map.discovery.rate_limited') }}
                        </p>
                        <div
                            v-else-if="searchPhase === 'error'"
                            data-testid="map-search-error"
                            class="flex items-center justify-between gap-3 px-3.5 py-2.5"
                        >
                            <span class="text-sm text-ink-muted">{{ t('map.discovery.error') }}</span>
                            <button
                                type="button"
                                class="mh-touch-target shrink-0 rounded-card border border-line px-3 py-1 text-xs text-ink
                                       transition-colors hover:bg-surface-sunken
                                       focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-accent"
                                @click="retrySearch"
                            >
                                {{ t('map.states.retry') }}
                            </button>
                        </div>
                        <p
                            v-else-if="searchFlat.length === 0"
                            data-testid="map-search-empty"
                            class="px-3.5 py-2.5 text-sm text-ink-muted"
                        >
                            {{ t('map.discovery.empty') }}
                        </p>
                        <template v-else>
                            <div
                                v-for="group in searchGrouped"
                                :key="group.key"
                                role="group"
                                :aria-labelledby="`map-search-group-${group.key}`"
                            >
                                <p :id="`map-search-group-${group.key}`" class="mh-label px-3.5 pb-1 pt-2.5">
                                    {{ t(`map.layers.${group.key}`) }}
                                </p>
                                <button
                                    v-for="{ row, index } in group.rows"
                                    :id="`map-search-option-${index}`"
                                    :key="`${row.kind}-${row.slug}`"
                                    type="button"
                                    role="option"
                                    :aria-selected="index === searchActive"
                                    :data-testid="`map-search-option-${row.kind}`"
                                    :data-slug="row.slug"
                                    class="mh-touch-target block w-full px-3.5 py-2.5 text-start text-sm text-ink
                                           transition-colors hover:bg-surface-sunken focus-visible:outline-none"
                                    :class="index === searchActive ? 'bg-surface-sunken' : ''"
                                    @click="chooseResult(row)"
                                >
                                    <span class="block truncate">{{ row.name }}</span>
                                    <span
                                        v-if="searchRowMeta(row) !== ''"
                                        class="mt-0.5 block truncate text-xs text-ink-muted"
                                    >
                                        {{ searchRowMeta(row) }}
                                    </span>
                                </button>
                            </div>
                        </template>
                    </div>
                </div>

                <!-- Map mode (Phase 4 + 6): Explore is the map as it has
                     always been; Market wears the movement engine's answer
                     on the area polygons (only where that feature exists);
                     Compare puts 2–3 areas side by side. -->
                <div
                    role="group"
                    :aria-label="t('map.market.mode_label')"
                    class="flex w-fit rounded-card border border-line p-0.5"
                >
                    <button
                        v-for="mode in mapModes"
                        :key="mode"
                        type="button"
                        class="mh-touch-target rounded-card px-4 py-1.5 text-sm font-medium transition-colors
                               focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-accent"
                        :class="mapMode === mode ? 'bg-accent/15 text-ink' : 'text-ink-muted hover:text-ink'"
                        :aria-pressed="mapMode === mode"
                        :data-testid="`map-mode-${mode}`"
                        @click="setMapMode(mode)"
                    >
                        {{ t(`map.market.${mode}`) }}
                    </button>
                </div>

                <!-- Market filters (Phase 4, shared by Compare in Phase 6):
                     the movement product's own vocabularies — sale XOR rent,
                     its window list with the same honest disabling under
                     whichever mode's evidence map is live, the PropertyType
                     enum served by the endpoint. One polygon paints one
                     claim, so the category control is single-select and
                     "all" means the spanning all-categories index, never a
                     blend. Hidden in Compare when movement is feature-off —
                     dead controls explain nothing. -->
                <div
                    v-if="(mapMode === 'market' || mapMode === 'compare') && !compareFiltersHidden"
                    class="space-y-2"
                    data-testid="market-controls"
                >
                    <div class="flex flex-wrap items-center gap-x-3 gap-y-2">
                        <div
                            role="group"
                            :aria-label="t('market.movement.transaction_label')"
                            class="flex rounded-card border border-line p-0.5"
                        >
                            <button
                                v-for="mode in MARKET_TRANSACTIONS"
                                :key="mode"
                                type="button"
                                class="mh-touch-target rounded-card px-3 py-1 text-xs font-medium transition-colors
                                       focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-accent"
                                :class="marketTransaction === mode ? 'bg-accent/15 text-ink' : 'text-ink-muted hover:text-ink'"
                                :aria-pressed="marketTransaction === mode"
                                :data-testid="`market-transaction-${mode}`"
                                @click="pickMarketTransaction(mode)"
                            >
                                {{ t(`market.movement.transaction.${mode}`) }}
                            </button>
                        </div>

                        <div
                            role="group"
                            :aria-label="t('market.movement.period_label')"
                            class="flex flex-wrap gap-1.5"
                        >
                            <button
                                v-for="key in MARKET_WINDOWS"
                                :key="key"
                                type="button"
                                class="mh-invest-chip mh-touch-target !text-xs
                                       disabled:cursor-not-allowed disabled:opacity-45
                                       focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-accent"
                                :aria-pressed="marketWindow === key"
                                :disabled="windowChipDisabled(key)"
                                :title="windowChipDisabled(key) ? t('market.movement.period_unavailable') : undefined"
                                :data-testid="`market-period-${key}`"
                                @click="pickMarketWindow(key)"
                            >
                                <span class="numeral">{{ t(`market.movement.periods.${key}`) }}</span>
                            </button>
                        </div>
                    </div>

                    <div
                        role="group"
                        :aria-label="t('market.movement.categories_label')"
                        class="flex flex-wrap gap-1.5"
                    >
                        <button
                            type="button"
                            class="mh-invest-chip mh-touch-target !text-xs
                                   focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-accent"
                            :aria-pressed="marketPropertyType === null"
                            data-testid="market-type-all"
                            @click="pickMarketPropertyType(null)"
                        >
                            {{ t('market.movement.all_categories') }}
                        </button>
                        <button
                            v-for="value in filterPropertyTypes"
                            :key="value"
                            type="button"
                            class="mh-invest-chip mh-touch-target !text-xs
                                   focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-accent"
                            :aria-pressed="marketPropertyType === value"
                            :data-testid="`market-type-${value}`"
                            @click="pickMarketPropertyType(value)"
                        >
                            {{ t(`market.property_types.${value}`) }}
                        </button>
                    </div>

                    <!-- Legend: swatch AND word, never colour alone. The
                         swatches come from trend.ts — the same single source
                         the marker icons speak. Market's own vocabulary, so
                         it renders in Market mode only. -->
                    <div
                        v-if="mapMode === 'market'"
                        class="flex flex-wrap items-center gap-x-3.5 gap-y-1 text-xs text-ink-muted"
                        data-testid="market-legend"
                    >
                        <span class="mh-label">{{ t('map.market.legend') }}</span>
                        <span
                            v-for="direction in (['up', 'down', 'flat'] as const)"
                            :key="direction"
                            class="flex items-center gap-1.5"
                        >
                            <span
                                class="h-2.5 w-2.5 shrink-0 rounded-sm"
                                :style="{ backgroundColor: trendColour(direction) }"
                                aria-hidden="true"
                            />
                            {{ t(`market.movement.direction.${direction}`) }}
                        </span>
                        <span class="flex items-center gap-1.5">
                            <span class="h-2.5 w-2.5 shrink-0 rounded-sm border border-line-strong" aria-hidden="true" />
                            {{ t('map.market.unknown') }}
                        </span>
                    </div>
                </div>

                <div>
                    <p class="mh-label mb-1.5">{{ t('map.layers_title') }}</p>
                    <div class="flex flex-wrap gap-2">
                        <button
                            v-for="layer in layers"
                            :key="layer.key"
                            type="button"
                            class="mh-invest-chip mh-touch-target
                                   focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-accent"
                            :aria-pressed="active.includes(layer.key as LayerKey)"
                            @click="toggleLayer(layer.key as LayerKey)"
                        >
                            {{ t(`map.layers.${layer.key}`) }}
                        </button>
                    </div>
                </div>

                <div v-if="categories.length > 0 && active.includes('places')">
                    <p class="mh-label mb-1.5">{{ t('map.categories_title') }}</p>
                    <div class="flex flex-wrap gap-2">
                        <button
                            v-for="category in categories"
                            :key="category.key"
                            type="button"
                            class="mh-invest-chip mh-touch-target !text-xs
                                   focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-accent"
                            :aria-pressed="activeCategories.includes(category.key)"
                            @click="toggleCategory(category.key)"
                        >
                            {{ category.name }}
                        </button>
                    </div>
                </div>

                <!-- Radius and draw. Both set a spatial filter the server
                     applies; neither is computed client-side. -->
                <div class="flex flex-wrap items-end gap-3">
                    <button
                        type="button"
                        class="mh-touch-target rounded-card border border-line px-3 py-1.5 text-sm text-ink-muted
                               transition-colors hover:bg-surface-sunken
                               focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-accent"
                        :class="pickingCentre ? '!border-accent-strong/60 !text-accent-strong' : ''"
                        @click="pickingCentre = !pickingCentre; drawing = false"
                    >
                        {{ centre ? t('map.search.centre_set') : t('map.search.set_centre') }}
                    </button>

                    <button
                        type="button"
                        class="mh-touch-target rounded-card border border-line px-3 py-1.5 text-sm text-ink-muted
                               transition-colors hover:bg-surface-sunken disabled:cursor-not-allowed disabled:opacity-50
                               focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-accent"
                        data-testid="use-my-location"
                        :disabled="locating"
                        @click="useMyLocation"
                    >
                        {{ locating ? t('home.location.locating') : t('map.use_my_location') }}
                    </button>

                    <label class="flex items-center gap-2 text-sm text-ink-muted">
                        {{ t('map.search.radius_km') }}
                        <input
                            v-model.number="radiusKm"
                            type="number"
                            min="0.1"
                            :max="limits.max_radius_km"
                            step="0.5"
                            class="numeral mh-touch-target w-20 rounded-card border border-line px-2 py-1 text-sm"
                            dir="ltr"
                            @change="load"
                        >
                    </label>

                    <button
                        type="button"
                        class="mh-touch-target rounded-card border border-line px-3 py-1.5 text-sm text-ink-muted
                               transition-colors hover:bg-surface-sunken
                               focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-accent"
                        :class="drawing ? '!border-accent-strong/60 !text-accent-strong' : ''"
                        @click="drawing = !drawing; pickingCentre = false"
                    >
                        {{ t('map.search.draw') }}
                    </button>

                    <template v-if="drawing || ring.length > 0">
                        <span class="numeral text-xs text-ink-faint" dir="ltr">
                            {{ t('map.search.draw_points', { count: ring.length }) }}
                        </span>
                        <button
                            type="button"
                            class="mh-touch-target rounded-card border border-line px-3 py-1.5 text-sm text-ink-muted
                                   focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-accent"
                            @click="finishDrawing"
                        >
                            {{ t('map.search.draw_finish') }}
                        </button>
                        <button
                            type="button"
                            class="mh-touch-target rounded-card border border-line px-3 py-1.5 text-sm text-ink-muted
                                   focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-accent"
                            @click="clearDrawing"
                        >
                            {{ t('map.search.draw_clear') }}
                        </button>
                    </template>

                    <button
                        type="button"
                        class="mh-touch-target rounded-card px-3 py-1.5 text-sm text-ink-faint underline
                               focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-accent"
                        @click="clearFilters"
                    >
                        {{ t('map.clear_filters') }}
                    </button>
                </div>

                <p v-if="drawing" class="text-xs text-ink-faint">{{ t('map.search.draw_hint') }}</p>
                <p v-if="pickingCentre" class="text-xs text-ink-faint">{{ t('map.search.radius_hint') }}</p>
            </div>

            <!-- Mobile view switch. The list is a peer of the map, not a
                 fallback, so on a phone it is one tap away rather than below
                 a full-height canvas. -->
            <div class="mb-3 flex gap-2 md:hidden" role="tablist">
                <button
                    type="button" role="tab" :aria-selected="mobileView === 'map'"
                    class="mh-invest-chip mh-touch-target flex-1 justify-center !rounded-card"
                    @click="mobileView = 'map'"
                >
                    {{ t('map.map_view') }}
                </button>
                <button
                    type="button" role="tab" :aria-selected="mobileView === 'list'"
                    class="mh-invest-chip mh-touch-target flex-1 justify-center !rounded-card"
                    @click="mobileView = 'list'"
                >
                    {{ t('map.list_view') }}
                </button>
            </div>

            <div class="grid gap-4 lg:grid-cols-[3fr_2fr]">
                <!-- ------------------------------------------------- map -->
                <div
                    class="mh-lux-panel mh-lux-gilded relative overflow-hidden"
                    :class="mobileView === 'list' ? 'hidden md:block' : ''"
                >
                    <div
                        ref="container" class="mh-map-ground h-[420px] w-full lg:h-[560px]" role="application"
                        :aria-label="t('nav.public.map')"
                    />
                    <div class="mh-invest-vignette" aria-hidden="true" />

                    <!-- Desktop Area Intelligence (Phase 3 §9): a compact
                         glass float on the START side — the map stays the
                         hero, pannable beside it; never a page-wide modal.
                         Below lg the same content rides the bottom sheet. -->
                    <div
                        v-if="selectedArea"
                        data-testid="area-card-float"
                        class="mh-invest-glass absolute start-3 top-3 z-20 hidden max-h-[calc(100%-1.5rem)]
                               w-[340px] max-w-[calc(100%-1.5rem)] rounded-card p-4 lg:flex"
                    >
                        <AreaIntelligenceCard
                            :identity="selectedArea"
                            :intel="areaIntel"
                            :phase="areaIntelPhase"
                            @close="clearArea"
                            @retry="retryAreaIntel"
                            @toggle-service="toggleServiceGroup"
                        />
                    </div>

                    <!-- Searched project/place context (Phase 5): a compact
                         glass strip naming what the camera just flew to,
                         with the real profile route. Mutually exclusive
                         with the Area card (§25), horizontally inset clear
                         of the corner controls at every width. -->
                    <div
                        v-if="searchContext"
                        data-testid="map-search-context"
                        class="pointer-events-none absolute inset-x-16 top-3 z-20 flex justify-center"
                    >
                        <div class="mh-invest-glass pointer-events-auto flex max-w-full items-center gap-2.5 rounded-card px-3.5 py-2">
                            <span class="min-w-0">
                                <span class="block truncate text-sm text-ink">{{ searchContext.name }}</span>
                                <span
                                    v-if="searchContext.area_name"
                                    class="block truncate text-xs text-ink-muted"
                                >{{ searchContext.area_name }}</span>
                            </span>
                            <Link
                                v-if="searchContextHref"
                                :href="searchContextHref"
                                data-testid="map-search-context-view"
                                class="mh-touch-target shrink-0 rounded-card border border-line px-3 py-1 text-xs text-ink
                                       transition-colors hover:bg-surface-sunken
                                       focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-accent"
                            >
                                {{ searchContext.kind === 'project' ? t('map.discovery.view_project') : t('map.discovery.view_place') }}
                            </Link>
                            <button
                                type="button"
                                data-testid="map-search-context-dismiss"
                                class="mh-touch-target shrink-0 rounded-card px-2 py-1 text-xs text-ink-muted
                                       transition-colors hover:text-ink
                                       focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-accent"
                                :aria-label="t('map.discovery.dismiss')"
                                @click="searchContext = null"
                            >
                                ✕
                            </button>
                        </div>
                    </div>

                    <!-- Location outcome notice (Phase 3 §8): the compact
                         toast language, floated clear of the data pills so
                         a locate that also triggers a refetch never stacks
                         two toasts on one pixel. Auto-clears; the map stays
                         fully usable behind it. -->
                    <div
                        v-if="locationNotice"
                        data-testid="location-notice"
                        class="pointer-events-none absolute inset-x-0 bottom-36 z-10 flex justify-center px-4 lg:bottom-16"
                        aria-live="polite"
                    >
                        <p class="mh-map-toast">{{ locationNotice }}</p>
                    </div>

                    <!-- Market-mode status (Phase 4): loading, the zoom
                         gate, or the movement engine's honest empty reason
                         — the same compact toast voice, yielding its slot
                         to a live location notice. -->
                    <div
                        v-if="marketNotice && !locationNotice"
                        data-testid="market-notice"
                        class="pointer-events-none absolute inset-x-0 bottom-36 z-10 flex justify-center px-4 lg:bottom-16"
                        aria-live="polite"
                    >
                        <p class="mh-map-toast">{{ marketNotice }}</p>
                    </div>

                    <!-- Compare picker feedback (Phase 6): duplicate or
                         full-set taps answered in the compact toast voice,
                         yielding its slot to the location notice. -->
                    <div
                        v-if="compareNotice && !locationNotice"
                        data-testid="compare-notice"
                        class="pointer-events-none absolute inset-x-0 bottom-36 z-10 flex justify-center px-4 lg:bottom-16"
                        aria-live="polite"
                    >
                        <p class="mh-map-toast">{{ compareNotice }}</p>
                    </div>

                    <!-- A dropped heat fetch: stated where the visitor is
                         looking, with a data-only retry — the map itself
                         never rebuilds for market data. -->
                    <div
                        v-if="mapMode === 'market' && marketPhase === 'error'"
                        data-testid="market-error"
                        class="absolute inset-x-0 bottom-36 z-10 flex justify-center px-4 lg:bottom-16"
                    >
                        <div class="mh-map-toast mh-map-toast--error">
                            <span class="text-xs text-ink">{{ t('market.movement.error') }}</span>
                            <button
                                type="button"
                                data-testid="market-retry"
                                class="mh-touch-target rounded-card border border-line px-3 py-1 text-xs text-ink
                                       transition-colors hover:bg-surface-sunken
                                       focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-accent"
                                @click="fetchMarketHeat"
                            >
                                {{ t('market.movement.retry') }}
                            </button>
                        </div>
                    </div>

                    <!-- Loading is a compact status over the dark ground,
                         not a veil across the whole surface (Map Phase 1):
                         the map area stays visually the map. -->
                    <div
                        v-if="!mapReady && !mapFailed"
                        class="pointer-events-none absolute inset-x-0 bottom-24 z-10 flex justify-center px-4 lg:bottom-4"
                        aria-live="polite"
                    >
                        <p class="mh-map-toast">{{ t('map.states.loading') }}</p>
                    </div>

                    <!-- Zero features is a STATE, not a failure: the basemap
                         stays live and pannable, with a floating notice
                         rather than a blank surface. Yields its spot to the
                         refetch pill and the refresh-failed chip below. -->
                    <!-- All three status overlays sit at bottom-24 below lg:
                         the fixed bottom navigation (z-40, ~60px) paints
                         over the map's bottom edge whenever that edge meets
                         the viewport bottom, so an overlay at bottom-4 is
                         exactly where the nav hides it and swallows its
                         taps. Measured at 360×800 with the map fully in
                         view: a bottom-4 chip overlaps the nav band and
                         elementFromPoint at its centre returns the nav. -->
                    <div
                        v-if="mapReady && !loading && !loadError && !hasResults"
                        class="pointer-events-none absolute inset-x-0 bottom-24 z-10 flex justify-center px-4 lg:bottom-4"
                        aria-live="polite"
                    >
                        <p class="mh-map-toast">
                            {{ t('map.states.map_empty_overlay') }}
                        </p>
                    </div>

                    <!-- NEW-REFETCH: after the first load, a viewport or
                         filter refetch was invisible on the mobile map tab —
                         the only signal lived in the list pane the map tab
                         hides. This pill states it politely, without veiling
                         the live map or the stale markers. -->
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

                    <!-- A dropped refresh, stated where the visitor is
                         looking: the stale data stays (the hint says so) and
                         Retry re-runs the DATA fetch only — the live map is
                         never rebuilt for a failed refresh. -->
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
                                class="mh-touch-target rounded-card border border-line px-3 py-1 text-xs text-ink
                                       transition-colors hover:bg-surface-sunken disabled:cursor-not-allowed
                                       disabled:opacity-50 focus-visible:outline-none focus-visible:ring-2
                                       focus-visible:ring-accent"
                                :disabled="loading"
                                @click="load"
                            >
                                {{ t('map.states.retry') }}
                            </button>
                        </div>
                    </div>

                    <div
                        v-if="mapFailed"
                        class="absolute inset-0 grid place-items-center bg-surface-sunken p-6 text-center text-sm text-ink-muted"
                    >
                        <div>
                            <p>{{ t('map.states.provider_failed_hint') }}</p>
                            <button
                                type="button"
                                data-testid="map-retry-overlay"
                                class="mh-touch-target mt-3 rounded-card border border-line px-4 py-1.5 text-sm text-ink
                                       transition-colors hover:bg-surface-raised disabled:cursor-not-allowed
                                       disabled:opacity-50 focus-visible:outline-none focus-visible:ring-2
                                       focus-visible:ring-accent"
                                :disabled="mapBuilding"
                                @click="retryMap"
                            >
                                {{ t('map.states.retry') }}
                            </button>
                        </div>
                    </div>
                </div>

                <!-- ------------------------------------------------ list -->
                <div :class="mobileView === 'map' ? 'hidden md:block' : ''">
                    <!-- Compare mode (Phase 6): the data pane IS the
                         comparison — the map stays the hero beside/above
                         it, one tap away on phones (§36). Leaving the mode
                         hands the ordinary results list straight back. -->
                    <div v-if="mapMode === 'compare'" class="mh-invest-glass rounded-card p-4">
                        <CompareAreasPanel
                            :selection="comparedAreas"
                            :data="compareData"
                            :phase="comparePhase"
                            :focused="focusedCompared"
                            @remove="removeComparedArea"
                            @focus="focusComparedColumn"
                            @retry="retryCompare"
                            @add-request="requestCompareAddition"
                            @show-all="fitComparedAreas"
                        />
                    </div>

                    <template v-else>
                        <div class="mb-2 flex items-center justify-between">
                            <p class="mh-label">{{ t('map.results', { count: flat.length }) }}</p>
                            <span v-if="loading" class="text-xs text-ink-faint">
                                {{ t('map.states.loading_features') }}
                            </span>
                        </div>

                        <!-- §10.5. Stated once, above the results, rather than
                             repeated on every row. -->
                        <p v-if="distanceApplied" class="mb-2 text-xs text-ink-faint">
                            {{ t('map.distance.straight_line_notice') }}
                        </p>
                        <p v-if="distanceApplied && !distance.travel_time_available" class="mb-2 text-xs text-ink-faint">
                            {{ t('map.distance.travel_time_unavailable') }}
                        </p>

                        <div
                            v-if="!hasResults && !loading"
                            class="rounded-card border border-dashed border-line p-6 text-center"
                        >
                            <p class="text-sm text-ink-muted">{{ t('map.states.empty') }}</p>
                            <p class="mt-1 text-xs text-ink-faint">{{ t('map.states.empty_hint') }}</p>
                        </div>

                        <ul v-else class="max-h-[520px] space-y-2 overflow-y-auto pe-1">
                            <template v-for="layer in availableKeys" :key="layer">
                                <li v-for="feature in (active.includes(layer) ? features[layer] : [])" :key="`${layer}-${feature.id}`">
                                    <!-- Area rows select IN PLACE (Phase 3 §13):
                                         the same canonical selection a polygon
                                         click sets, so list and map can never
                                         disagree. The profile link lives on in
                                         the card's "view full area" action. -->
                                    <component
                                        :is="layer === 'areas' && feature.slug ? 'button' : (hrefFor(feature, layer) ? Link : 'div')"
                                        :href="layer === 'areas' && feature.slug ? undefined : (hrefFor(feature, layer) ?? undefined)"
                                        :type="layer === 'areas' && feature.slug ? 'button' : undefined"
                                        :aria-pressed="layer === 'areas' && feature.slug ? selectedArea?.slug === feature.slug : undefined"
                                        :data-testid="layer === 'areas' ? 'area-row' : undefined"
                                        class="mh-touch-target block w-full rounded-card border border-line px-3 py-2 text-start
                                               text-sm transition-colors hover:bg-surface-sunken focus-visible:outline-none
                                               focus-visible:ring-2 focus-visible:ring-accent"
                                        :class="layer === 'areas' && selectedArea?.slug === feature.slug
                                            ? '!border-accent-strong/60 bg-surface-sunken'
                                            : ''"
                                        @click="layer === 'areas' && feature.slug ? selectAreaFromRow(feature) : undefined"
                                    >
                                        <span class="flex items-start justify-between gap-3">
                                            <span>
                                                <span class="text-ink">{{ labelFor(feature) }}</span>
                                                <span class="ms-2 text-xs text-ink-faint">
                                                    {{ t(`map.layers.${layer}`) }}
                                                </span>
                                                <!-- §12.2: a paid placement is
                                                     labelled wherever it appears. -->
                                                <span v-if="feature.is_sponsored" class="ms-2 text-xs text-caution">
                                                    {{ feature.disclosure }}
                                                </span>
                                            </span>

                                            <span
                                                v-if="feature.distance_km !== null"
                                                class="numeral shrink-0 text-xs text-ink-faint"
                                                dir="ltr"
                                                :title="t('map.distance.straight_line')"
                                            >{{ t('map.distance.km', { distance: formatNumber(feature.distance_km, 2) }) }}</span>
                                        </span>

                                        <span v-if="feature.area" class="mt-0.5 block text-xs text-ink-muted">
                                            {{ feature.area }}
                                        </span>

                                        <!-- Price rows carry the qualifier, sample
                                             size and period with the figure. -->
                                        <span v-if="layer === 'prices'" class="mt-1 block text-xs text-ink-muted">
                                            <span class="numeral" dir="ltr">{{ feature.value }}</span>
                                            {{ feature.currency }}
                                            <span v-if="feature.period" class="ms-2">{{ feature.period }}</span>
                                            <span v-if="feature.sample_size !== null" class="numeral ms-2" dir="ltr">
                                                n={{ feature.sample_size }}
                                            </span>
                                            <span v-if="feature.requires_qualifier" class="ms-2 text-caution">
                                                {{ t('market.public.qualifier.sale_asking') }}
                                            </span>
                                        </span>
                                    </component>
                                </li>
                            </template>
                        </ul>
                    </template>
                </div>
            </div>

            <!-- Selected area below lg: the same card as a bottom sheet,
                 teleported over either mobile tab. The gate rides the
                 sheet's `open` prop, not CSS alone — the sheet scroll-locks
                 the body from a watcher on `open` (Invest's rule), so a
                 logically-open sheet on desktop would lock the page with no
                 visible dialog. The sheet header owns the name + close; the
                 card's `sheet` variant drops its own copies. -->
            <MobileBottomSheet
                :open="selectedArea !== null && areaSheetOpen && !isDesktop"
                :title="selectedArea?.name || t('home.location.chosen_area')"
                @close="clearArea"
            >
                <AreaIntelligenceCard
                    v-if="selectedArea"
                    variant="sheet"
                    :identity="selectedArea"
                    :intel="areaIntel"
                    :phase="areaIntelPhase"
                    @close="clearArea"
                    @retry="retryAreaIntel"
                    @toggle-service="toggleServiceGroup"
                />
            </MobileBottomSheet>
        </template>
    </PublicLayout>
</template>
