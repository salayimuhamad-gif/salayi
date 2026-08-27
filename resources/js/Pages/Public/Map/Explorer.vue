<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref, shallowRef, watch } from 'vue';
import { Head, Link } from '@inertiajs/vue3';
import PublicLayout from '@/Layouts/PublicLayout.vue';
import AppAlert from '@/Components/ui/AppAlert.vue';
import { t, formatNumber } from '@/lib/i18n';
import { useLocale } from '@/Composables/useLocale';
import AreaIntelligenceCard, { type AreaIntel } from '@/Components/Public/AreaIntelligenceCard.vue';
import MobileBottomSheet from '@/Components/Public/MobileBottomSheet.vue';
import { useIsDesktop } from '@/Composables/useIsDesktop';
import {
    boundaryBounds,
    createMapAdapter,
    poiCategoryFor,
    type BoundaryCollection,
    type BoundaryIdentity,
    type MapAdapter,
} from '@/lib/map';

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

function selectArea(identity: BoundaryIdentity, focus = true): void {
    // A repeated tap on the already-selected area re-frames and re-opens
    // the sheet, but never burns a second resolve round-trip (and with it
    // the shared rate limit) on data that is already here or in flight.
    const repeat = selectedArea.value?.slug === identity.slug
        && (areaIntelPhase.value === 'ready' || areaIntelPhase.value === 'loading');

    selectedArea.value = identity;
    areaSheetOpen.value = true;
    adapter.value?.setSelectedBoundary?.(identity.slug);

    if (focus) {
        focusArea(identity);
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

/*
 * Camera focus happens ONCE, at the moment of explicit selection (§15) —
 * never from a watcher — with padding reserving room for the desktop card
 * (on the START side, so the physical side follows the locale) or the
 * mobile sheet. A polygon absent from the loaded boundaries (zoomed-out
 * entry from the list) falls back to the area's centroid point.
 */
function focusArea(identity: BoundaryIdentity): void {
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
    const box = feature ? boundaryBounds(feature.geometry) : null;

    if (box && map.fitBounds) {
        map.fitBounds(box, { padding, maxZoom: 15 });

        return;
    }

    const row = features.value.areas.find((area) => area.slug === identity.slug);

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
            onBoundarySelect: (identity: BoundaryIdentity) => selectArea(identity),
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
