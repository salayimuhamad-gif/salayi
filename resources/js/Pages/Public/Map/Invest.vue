<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref, shallowRef, watch } from 'vue';
import { Head, Link } from '@inertiajs/vue3';
import PublicLayout from '@/Layouts/PublicLayout.vue';
import AppAlert from '@/Components/ui/AppAlert.vue';
import AppIcon from '@/Components/Icons/AppIcon.vue';
import { t } from '@/lib/i18n';
import { useLocale } from '@/Composables/useLocale';
import { createMapAdapter, type BoundaryCollection, type MapAdapter } from '@/lib/map';

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
}

const props = defineProps<{
    style_url: string | null;
    provider: string;
    provider_requested: string;
    provider_fallback_reason: string | null;
    google_key: string | null;
    layers: Array<{ key: string; flag: string | null }>;
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

const projects = ref<ProjectFeature[]>([]);
const selected = ref<ProjectFeature | null>(null);
const showBoundaries = ref(false);

const mapFailed = ref(false);
const mapReady = ref(false);
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

async function load(): Promise<void> {
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

        projects.value = data.projects ?? [];
        boundaries.value = showBoundaries.value ? (data.boundaries ?? emptyBoundaries) : emptyBoundaries;
        truncated.value = Boolean(data.truncated);
    } catch {
        // Kept deliberately: blanking the list on a failed refresh punishes
        // the visitor for a dropped request by taking away data they had.
        loadError.value = true;
    } finally {
        loading.value = false;
        syncSource();
    }
}

/* --------------------------------------------------------- map rendering */

function syncSource(): void {
    if (!mapReady.value) {
        return;
    }

    adapter.value?.setPoints(
        projects.value.map((project) => ({
            lat: project.lat,
            lng: project.lng,
            title: project.name ?? '',
            // The brand accent's default value — the gold the reference
            // design uses — while still reading as "the accent" beside the
            // rest of the page.
            colour: '#c9a227',
        })),
    );

    adapter.value?.setBoundaries(boundaries.value);
}

function select(project: ProjectFeature): void {
    selected.value = project;
    adapter.value?.flyTo({ lat: project.lat, lng: project.lng }, 15);
    mobileView.value = 'map';
}

/** Recover a live Google failure by rebuilding MapLibre, once. */
async function handleRuntimeFailure(): Promise<void> {
    if (renderedProvider.value !== 'google' || fallingBack) {
        mapFailed.value = true;
        return;
    }

    fallingBack = true;
    mapReady.value = false;

    adapter.value?.destroy();
    adapter.value = null;

    try {
        const result = await createMapAdapter('maplibre', adapterOptions());

        adapter.value = result.adapter;
        renderedProvider.value = result.adapter.provider;
        clientFallbackReason.value = 'google-maps-runtime-failure';

        await result.adapter.ready();

        mapReady.value = true;
        mapFailed.value = false;
        void load();
    } catch {
        mapFailed.value = true;
    } finally {
        fallingBack = false;
    }
}

let fallingBack = false;

function adapterOptions() {
    return {
        container: container.value as HTMLElement,
        styleUrl: props.style_url,
        googleKey: props.google_key,
        centre: { lat: 36.19, lng: 44.009 },
        zoom: 12,
        events: {
            onMoveEnd: () => scheduleLoad(),
            onClick: () => {
                selected.value = null;
            },
            onError: () => void handleRuntimeFailure(),
        },
    };
}

async function initialiseMap(): Promise<void> {
    if (!container.value) {
        mapFailed.value = true;
        void load();
        return;
    }

    try {
        const result = await createMapAdapter(props.provider, adapterOptions());

        adapter.value = result.adapter;
        renderedProvider.value = result.adapter.provider;
        clientFallbackReason.value = result.fallbackReason ?? null;

        await result.adapter.ready();

        mapReady.value = true;
    } catch {
        mapFailed.value = true;
    }

    void load();
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
        <AppAlert
            v-else-if="mapFailed" class="mb-3" variant="warning"
            :message="`${t('map.states.provider_failed')} — ${t('map.states.provider_failed_hint')}`"
        />
        <AppAlert
            v-if="loadError" class="mb-3" variant="danger"
            :message="`${t('map.states.error')} — ${t('map.states.error_hint')}`"
        />
        <AppAlert v-if="truncated" class="mb-3" variant="warning" :message="t('map.zoom_in_notice')" />

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
                    class="h-[420px] w-full lg:h-[560px]"
                    role="application"
                    :aria-label="t('map.invest.title')"
                />
                <div class="mh-invest-vignette" aria-hidden="true" />

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

                <!-- Selected project, glass card floating over the map. -->
                <div
                    v-if="selected"
                    class="mh-invest-glass absolute bottom-3 start-3 max-w-[calc(100%-1.5rem)] rounded-card p-4 sm:max-w-sm"
                    role="status"
                >
                    <p class="mh-lux-eyebrow mb-1">{{ t('map.invest.selected') }}</p>
                    <p class="font-semibold text-ink">{{ selected.name }}</p>
                    <p v-if="selected.area" class="mt-0.5 text-sm text-ink-muted">{{ selected.area }}</p>
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
                <div class="mb-2 flex items-baseline justify-between">
                    <p class="mh-label">{{ t('map.invest.projects_label') }}</p>
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
                                class="block w-full text-start focus-visible:outline-none"
                                @click="select(project)"
                            >
                                <p class="text-sm font-semibold text-ink">{{ project.name }}</p>
                                <p v-if="project.area" class="mt-0.5 text-xs text-ink-muted">
                                    {{ project.area }}
                                </p>
                            </button>
                            <Link
                                :href="localized(`/projects/${project.slug}`)"
                                class="mt-2 inline-flex items-center gap-1.5 text-xs font-medium text-accent"
                            >
                                {{ t('map.invest.view_project') }}
                                <AppIcon name="arrow-end" class="h-3.5 w-3.5 rtl:-scale-x-100" />
                            </Link>
                        </div>
                    </li>
                </ul>
            </section>
        </div>
    </PublicLayout>
</template>
