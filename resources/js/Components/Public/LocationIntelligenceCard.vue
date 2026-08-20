<script setup lang="ts">
import { computed, ref } from 'vue';
import { Link, router, usePage } from '@inertiajs/vue3';
import AppIcon from '@/Components/Icons/AppIcon.vue';
import MarketMetricCard from '@/Components/Public/MarketMetricCard.vue';
import { t } from '@/lib/i18n';
import { useLocale } from '@/Composables/useLocale';

/*
 * "Know the Price Where You Are" (Wave 3) — the homepage's location
 * intelligence card, directly below the Pricing Intelligence Map.
 *
 * THE ONE RULE THAT SHAPES EVERYTHING HERE: browser geolocation is requested
 * ONLY inside the click handler of "Use My Location". Nothing on mount, on
 * hydration, on navigation or on language switch touches
 * `navigator.geolocation` — the permission prompt is the visitor's decision,
 * never the page's. The E2E suite counts calls to prove it.
 *
 * The card renders exactly what GET /location/resolve answers, and that
 * endpoint reuses the existing AreaResolver (published areas, most specific
 * match, NO nearest fallback) and the map price layer's latest-reliable index
 * selection. So every state below is an honest server state, plus the four
 * browser-side ones (permission denied, geolocation unavailable, rate
 * limited, transport error) — and none of them is a trap: the manual
 * published-area fallback stays reachable from all of them.
 *
 * Prices render through the SAME MarketMetricCard the KPI strip uses — the
 * figure keeps its price family, basis, period, sample and confidence, and a
 * missing figure renders as the no-data state, never as zero.
 */
defineProps<{
    /** Whether the portfolio flag is on — decides if the valuation CTA exists at all. */
    portfolioEnabled: boolean;
    /** Destination of the valuation CTA — the existing portfolio flow. */
    portfolioHref: string;
}>();

const { localized } = useLocale();
const page = usePage();

interface PriceIndex {
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
    requires_qualifier: boolean;
}

interface ResolveResponse {
    state: 'resolved' | 'no_data' | 'outside_coverage';
    area: { slug: string; name: string; type: string; type_label: string; breadcrumb: Array<{ name: string }> } | null;
    prices: { area_name: string; indices: PriceIndex[] } | null;
    prices_reason: 'feature_disabled' | 'no_published_values' | null;
}

type Phase = 'idle' | 'locating' | 'result' | 'denied' | 'unavailable' | 'rate_limited' | 'error';

const phase = ref<Phase>('idle');
const result = ref<ResolveResponse | null>(null);
/** Which gesture produced the current result — labels it honestly. */
const resultSource = ref<'geolocation' | 'manual'>('geolocation');

/* --------------------------------------------------- geolocation flow -- */

/**
 * Marks the newest "Use My Location" tap, so a stale callback from an
 * earlier attempt can never overwrite a newer interaction.
 */
let locateAttempt = 0;

/** The API's own acquisition timeout, once permission is granted. */
const GEOLOCATION_TIMEOUT_MS = 10_000;

/**
 * The no-trap watchdog. The Geolocation spec pauses the `timeout` clock
 * while the permission prompt is open, so a prompt nobody answers would
 * otherwise leave the card "locating" forever with its buttons disabled.
 */
const GEOLOCATION_WATCHDOG_MS = 15_000;

function useMyLocation(): void {
    if (typeof navigator === 'undefined' || !navigator.geolocation) {
        phase.value = 'unavailable';
        return;
    }

    const attempt = ++locateAttempt;

    phase.value = 'locating';

    const watchdog = window.setTimeout(() => {
        if (attempt === locateAttempt && phase.value === 'locating') {
            phase.value = 'unavailable';
        }
    }, GEOLOCATION_WATCHDOG_MS);

    navigator.geolocation.getCurrentPosition(
        (position) => {
            window.clearTimeout(watchdog);

            // A late answer is welcome only while this attempt still owns the
            // card — never over a newer attempt or a manual selection.
            if (attempt !== locateAttempt || (phase.value !== 'locating' && phase.value !== 'unavailable')) {
                return;
            }

            void resolvePoint(position.coords.latitude, position.coords.longitude);
        },
        (failure) => {
            window.clearTimeout(watchdog);

            if (attempt !== locateAttempt || (phase.value !== 'locating' && phase.value !== 'unavailable')) {
                return;
            }

            // Denial is a legitimate answer, not an error — and it is
            // recoverable right here through the manual fallback.
            phase.value = failure.code === failure.PERMISSION_DENIED ? 'denied' : 'unavailable';
        },
        { timeout: GEOLOCATION_TIMEOUT_MS, maximumAge: 60_000 },
    );
}

async function resolvePoint(lat: number, lng: number): Promise<void> {
    resultSource.value = 'geolocation';
    await requestResolve(`lat=${encodeURIComponent(lat)}&lng=${encodeURIComponent(lng)}`);
}

async function requestResolve(query: string): Promise<void> {
    phase.value = 'locating';

    try {
        const response = await fetch(localized(`/location/resolve?${query}`), {
            headers: { Accept: 'application/json' },
        });

        if (response.status === 429) {
            phase.value = 'rate_limited';
            return;
        }

        if (!response.ok) {
            phase.value = 'error';
            return;
        }

        result.value = (await response.json()) as ResolveResponse;
        phase.value = 'result';
    } catch {
        phase.value = 'error';
    }
}

/* ------------------------------------------------------ manual fallback -- */

const manualOpen = ref(false);
const areasLoading = ref(false);
const areasFailed = ref(false);
const selectedSlug = ref('');

/** Published areas (with published ancestry), served on demand by the homepage. */
const manualAreas = computed(
    () => (page.props as { location_areas?: Array<{ slug: string; name: string; type: string }> }).location_areas ?? null,
);

/** Grouped for <optgroup>, coarsest level first (the server orders by depth). */
const groupedAreas = computed(() => {
    const groups: Array<{ type: string; areas: Array<{ slug: string; name: string }> }> = [];

    for (const area of manualAreas.value ?? []) {
        const group = groups.find((candidate) => candidate.type === area.type);

        if (group) {
            group.areas.push(area);
        } else {
            groups.push({ type: area.type, areas: [area] });
        }
    }

    return groups;
});

function openManual(): void {
    manualOpen.value = true;

    if (manualAreas.value !== null || areasLoading.value) {
        return;
    }

    loadAreas();
}

function loadAreas(): void {
    areasLoading.value = true;
    areasFailed.value = false;

    // An Inertia partial reload of the homepage's optional prop — the same
    // route, no second endpoint, fetched only because the visitor asked.
    router.reload({
        only: ['location_areas'],
        onError: () => {
            areasFailed.value = true;
        },
        onFinish: () => {
            areasLoading.value = false;
            areasFailed.value = areasFailed.value || manualAreas.value === null;
        },
    });
}

function chooseArea(): void {
    if (selectedSlug.value === '') {
        return;
    }

    resultSource.value = 'manual';
    void requestResolve(`area=${encodeURIComponent(selectedSlug.value)}`);
}

/* -------------------------------------------------------- presentation -- */

const busy = computed(() => phase.value === 'locating');

const failureMessage = computed<string | null>(() => {
    switch (phase.value) {
        case 'denied':
            return t('home.location.denied');
        case 'unavailable':
            return t('home.location.unavailable');
        case 'rate_limited':
            return t('home.location.rate_limited');
        case 'error':
            return t('home.location.error');
        default:
            return null;
    }
});

const resolvedArea = computed(() => (phase.value === 'result' ? (result.value?.area ?? null) : null));

const resolvedPrices = computed(() => (phase.value === 'result' ? (result.value?.prices ?? null) : null));

const outside = computed(() => phase.value === 'result' && result.value?.state === 'outside_coverage');

const breadcrumb = computed(() =>
    (resolvedArea.value?.breadcrumb ?? []).map((ancestor) => ancestor.name).join(' · '),
);

/** The honest no-price message — a disabled module is not "no data". */
const noPriceMessage = computed(() =>
    result.value?.prices_reason === 'feature_disabled' ? t('home.feature_off') : t('home.location.no_price_data'),
);

const priceHeading = computed(() => {
    const prices = resolvedPrices.value;

    if (!prices || !resolvedArea.value) return null;

    // When the figures belong to a containing parent area, say so — the
    // label names the area the intelligence actually describes.
    return prices.area_name === resolvedArea.value.name
        ? null
        : t('home.location.prices_for', { area: prices.area_name });
});
</script>

<template>
    <div class="mh-lux-panel mh-lux-gilded !rounded-glass p-5 sm:p-6">
        <!-- Header: the concept, stated once. -->
        <div class="flex items-start gap-3.5">
            <span
                class="mh-lux-gold flex h-11 w-11 shrink-0 items-center justify-center rounded-[13px] border border-line"
                aria-hidden="true"
            >
                <AppIcon name="locate" class="h-5 w-5" />
            </span>
            <div class="min-w-0">
                <h2 class="text-base font-semibold text-ink">{{ t('home.location.title') }}</h2>
                <p class="mt-0.5 text-sm leading-relaxed text-ink-muted">{{ t('home.location.lede') }}</p>
            </div>
        </div>

        <!-- Actions: geolocation is USER-INITIATED here and nowhere else. -->
        <div class="mt-4 flex flex-wrap items-center gap-2.5">
            <button
                type="button"
                class="mh-lux-btn mh-lux-btn-primary"
                :disabled="busy"
                data-testid="use-my-location"
                @click="useMyLocation"
            >
                <AppIcon name="locate" class="h-4 w-4 shrink-0" aria-hidden="true" />
                {{ t('home.location.use_my_location') }}
            </button>

            <button
                type="button"
                class="mh-lux-btn mh-lux-btn-secondary"
                :disabled="busy"
                data-testid="choose-area-manually"
                @click="openManual"
            >
                {{ t('home.location.choose_manually') }}
            </button>
        </div>

        <p class="mt-2 text-xs text-ink-faint">{{ t('home.location.privacy_hint') }}</p>

        <!-- Manual fallback: REAL published areas, resolved through the same
             endpoint and rules as geolocation. -->
        <div v-if="manualOpen" class="mt-4">
            <p v-if="areasLoading" class="text-sm text-ink-muted" role="status">{{ t('home.location.loading_areas') }}</p>

            <div v-else-if="areasFailed" class="flex flex-wrap items-center gap-3">
                <p class="text-sm text-caution">{{ t('home.location.areas_unavailable') }}</p>
                <button type="button" class="mh-lux-btn mh-lux-btn-secondary !py-1.5 text-xs" @click="loadAreas">
                    {{ t('home.location.try_again') }}
                </button>
            </div>

            <p v-else-if="manualAreas !== null && manualAreas.length === 0" class="text-sm text-ink-muted">
                {{ t('home.no_areas') }}
            </p>

            <div v-else-if="manualAreas !== null" class="flex max-w-md flex-col gap-1.5">
                <label class="text-xs text-ink-faint" for="location-area-select">
                    {{ t('home.location.select_area_label') }}
                </label>
                <select
                    id="location-area-select"
                    v-model="selectedSlug"
                    class="min-h-11 w-full rounded-field border border-line-strong bg-surface-raised px-2 text-sm text-ink"
                    data-testid="manual-area-select"
                    :disabled="busy"
                    @change="chooseArea"
                >
                    <option value="" disabled>{{ t('home.location.select_placeholder') }}</option>
                    <optgroup
                        v-for="group in groupedAreas"
                        :key="group.type"
                        :label="t(`geography.public.type.${group.type}`)"
                    >
                        <option v-for="area in group.areas" :key="area.slug" :value="area.slug">
                            {{ area.name }}
                        </option>
                    </optgroup>
                </select>
            </div>
        </div>

        <!-- State region: every outcome is spoken, none is a dead end. -->
        <div aria-live="polite">
            <p v-if="busy" class="mt-4 animate-pulse text-sm text-ink-muted" role="status">
                {{ t('home.location.locating') }}
            </p>

            <p v-else-if="failureMessage" class="mt-4 text-sm text-caution" data-testid="location-failure">
                {{ failureMessage }}
            </p>

            <p v-else-if="outside" class="mt-4 text-sm text-ink-muted" data-testid="outside-coverage">
                {{ t('home.location.outside') }}
            </p>

            <div v-else-if="resolvedArea" class="mt-4" data-testid="resolved-area">
                <div class="flex flex-wrap items-baseline gap-x-3 gap-y-1">
                    <span class="mh-microlabel">
                        {{ resultSource === 'manual' ? t('home.location.chosen_area') : t('home.location.your_area') }}
                    </span>
                    <span class="mh-lux-chip !py-0.5 text-[11px]">{{ resolvedArea.type_label }}</span>
                </div>

                <p class="mt-1 text-xl font-semibold text-ink">{{ resolvedArea.name }}</p>
                <p v-if="breadcrumb" class="mt-0.5 text-xs text-ink-faint">{{ breadcrumb }}</p>

                <!-- Real published price intelligence, or the honest absence. -->
                <template v-if="resolvedPrices">
                    <p v-if="priceHeading" class="mt-3 text-xs text-ink-muted" data-testid="price-context">
                        {{ priceHeading }}
                    </p>
                    <div class="mt-2.5 grid gap-3.5 sm:grid-cols-2" data-testid="location-prices">
                        <MarketMetricCard
                            v-for="index in resolvedPrices.indices"
                            :key="index.key"
                            :index="index"
                        />
                    </div>
                </template>

                <p v-else class="mt-3 text-sm text-ink-muted" data-testid="location-no-price">
                    {{ noPriceMessage }}
                </p>
            </div>
        </div>

        <!-- The SECOND, visually distinct CTA: into the real valuation flow
             (portfolio), exactly as gated everywhere else — absent when the
             flag is off, auth handled by the route itself. -->
        <div
            v-if="portfolioEnabled"
            class="mt-5 flex flex-wrap items-center gap-4 border-t border-line pt-4"
        >
            <p class="min-w-0 flex-1 basis-60 text-sm leading-relaxed text-ink-muted">
                {{ t('home.location.valuation_lede') }}
            </p>
            <Link
                :href="portfolioHref"
                class="mh-lux-btn mh-amber-grad mh-lift-hover w-full shrink-0 !rounded-[13px] font-medium
                       shadow-[0_12px_30px_rgba(221,134,54,0.38),inset_0_1px_0_rgba(255,255,255,0.35)]
                       sm:w-auto"
                data-testid="valuation-cta"
            >
                <AppIcon name="portfolio" class="h-4 w-4 shrink-0" aria-hidden="true" />
                {{ t('home.location.valuation_cta') }}
            </Link>
        </div>
    </div>
</template>
