<script setup lang="ts">
import { computed } from 'vue';
import { Link } from '@inertiajs/vue3';
import AppIcon from '@/Components/Icons/AppIcon.vue';
import MarketMetricCard from '@/Components/Public/MarketMetricCard.vue';
import { t, formatNumber } from '@/lib/i18n';
import { serviceIcon } from '@/lib/serviceIcons';
import { useLocale } from '@/Composables/useLocale';

/*
 * The Area Intelligence card (Map Phase 3): the ONE selected-area surface
 * every entry path renders — polygon click, area list, live location. Pure
 * content: the Explorer decides whether this rides a desktop glass float or
 * the mobile bottom sheet, and everything shown is the authoritative
 * /location/resolve payload — the SAME contract the homepage location card
 * consumes, prices through the SAME MarketMetricCard, services through the
 * SAME grouped counts the Area profile renders. Nothing here calculates,
 * combines or substitutes; a missing figure is an honest absence, never a
 * zero.
 */

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

interface ServiceGroup {
    key: string;
    label: string;
    count: number;
    categories: Array<{ key: string; label: string; count: number }>;
}

export interface AreaIntel {
    state: 'resolved' | 'no_data' | 'outside_coverage';
    area: {
        slug: string;
        name: string;
        type: string;
        type_label: string;
        breadcrumb: Array<{ name: string }>;
        services: ServiceGroup[];
    } | null;
    prices: { area_name: string; indices: PriceIndex[] } | null;
    prices_reason: 'feature_disabled' | 'no_published_values' | null;
}

const props = withDefaults(defineProps<{
    /** Stable identity from the selection itself — renders instantly. */
    identity: { slug: string; name: string; type: string };
    /** The resolve payload once fetched; null while loading/failed. */
    intel: AreaIntel | null;
    phase: 'loading' | 'ready' | 'error' | 'rate_limited';
    /**
     * 'float' renders the full header — name heading and close button — for
     * the desktop glass card; 'sheet' drops both, because MobileBottomSheet
     * already provides the name as the dialog heading and its own close.
     */
    variant?: 'float' | 'sheet';
}>(), { variant: 'float' });

const emit = defineEmits<{
    close: [];
    retry: [];
    /** The user activated one service group — the Explorer enables its POIs. */
    'toggle-service': [group: ServiceGroup];
}>();

const { localized } = useLocale();

const area = computed(() => props.intel?.area ?? null);

/** Identity to render NOW: the fetched, locale-fresh one wins over the click's. */
const name = computed(() => area.value?.name ?? props.identity.name);
const typeLabel = computed(() => area.value?.type_label ?? null);

const breadcrumb = computed(() =>
    (area.value?.breadcrumb ?? []).map((ancestor) => ancestor.name).join(' · '));

const prices = computed(() => (props.phase === 'ready' ? (props.intel?.prices ?? null) : null));

const services = computed(() => area.value?.services ?? []);

/** The honest no-price line — a disabled module is not "no data". */
const noPriceMessage = computed(() =>
    props.intel?.prices_reason === 'feature_disabled'
        ? t('home.feature_off')
        : t('home.location.no_price_data'));

const priceHeading = computed(() => {
    if (!prices.value || !area.value) return null;

    // When the figures belong to a containing parent, say whose they are.
    return prices.value.area_name === area.value.name
        ? null
        : t('home.location.prices_for', { area: prices.value.area_name });
});
</script>

<template>
    <section
        class="flex max-h-full min-h-0 w-full flex-col"
        :aria-label="t('home.location.chosen_area')"
        data-testid="area-intelligence-card"
    >
        <!-- Identity: rendered from the selection instantly, refreshed by
             the resolve payload; the close button is always reachable. In
             the sheet variant the name heading and close live on the sheet
             chrome instead, so only the context line renders here. -->
        <header class="flex items-start gap-3">
            <div class="min-w-0 flex-1">
                <div class="flex flex-wrap items-baseline gap-x-2.5 gap-y-1">
                    <span class="mh-microlabel">{{ t('home.location.chosen_area') }}</span>
                    <span v-if="typeLabel" class="mh-lux-chip !py-0.5 text-[11px]">{{ typeLabel }}</span>
                </div>
                <h2
                    v-if="variant === 'float'"
                    class="mt-1 truncate font-display text-lg font-semibold text-ink"
                    data-testid="area-card-name"
                >
                    {{ name }}
                </h2>
                <p v-if="breadcrumb" class="mt-0.5 truncate text-xs text-ink-faint">{{ breadcrumb }}</p>
            </div>

            <button
                v-if="variant === 'float'"
                type="button"
                class="mh-touch-target -me-1 -mt-1 flex shrink-0 items-center justify-center rounded-card
                       text-ink-muted transition-colors hover:bg-surface-sunken hover:text-ink
                       focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-accent"
                :aria-label="t('app.actions.close')"
                data-testid="area-card-close"
                @click="emit('close')"
            >
                <AppIcon name="close" class="h-4 w-4" aria-hidden="true" />
            </button>
        </header>

        <div class="mt-3 min-h-0 flex-1 overflow-y-auto pe-0.5" aria-live="polite">
            <!-- Fetch states: compact, never a dead end. -->
            <p v-if="phase === 'loading'" class="animate-pulse text-sm text-ink-muted" role="status">
                {{ t('map.states.loading_features') }}
            </p>

            <div v-else-if="phase === 'error' || phase === 'rate_limited'" class="flex flex-wrap items-center gap-2">
                <p class="text-sm text-caution">
                    {{ phase === 'rate_limited' ? t('home.location.rate_limited') : t('map.states.error') }}
                </p>
                <button
                    v-if="phase === 'error'"
                    type="button"
                    class="mh-touch-target rounded-card border border-line px-3 py-1 text-xs text-ink
                           transition-colors hover:bg-surface-sunken
                           focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-accent"
                    @click="emit('retry')"
                >
                    {{ t('map.states.retry') }}
                </button>
            </div>

            <template v-else-if="phase === 'ready'">
                <!-- Market intelligence: real published figures or the honest
                     absence — exactly the homepage card's rendering rules. -->
                <template v-if="prices">
                    <p v-if="priceHeading" class="mb-1.5 text-xs text-ink-muted">{{ priceHeading }}</p>
                    <div class="grid gap-2.5" data-testid="area-card-prices">
                        <MarketMetricCard
                            v-for="index in prices.indices"
                            :key="index.key"
                            :index="index"
                        />
                    </div>
                </template>

                <p v-else class="text-sm text-ink-muted" data-testid="area-card-no-price">
                    {{ noPriceMessage }}
                </p>

                <!-- Services: the Phase 2 counts, and each group is a real
                     control — activating it enables that POI family on the
                     map. Zero-count groups never arrive from the server. -->
                <div v-if="services.length > 0" class="mt-4">
                    <p class="mh-label mb-2">{{ t('geography.public.services_title') }}</p>
                    <div class="flex flex-wrap gap-1.5" data-testid="area-card-services">
                        <button
                            v-for="group in services"
                            :key="group.key"
                            type="button"
                            class="mh-invest-chip mh-touch-target !text-xs
                                   focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-accent"
                            :aria-label="`${group.label} — ${formatNumber(group.count)}`"
                            @click="emit('toggle-service', group)"
                        >
                            <AppIcon :name="serviceIcon(group.key)" class="h-3.5 w-3.5 shrink-0" aria-hidden="true" />
                            {{ group.label }}
                            <span class="numeral text-ink-faint" dir="ltr">{{ formatNumber(group.count) }}</span>
                        </button>
                    </div>
                </div>
            </template>
        </div>

        <!-- The one action every state keeps: into the full Area profile. -->
        <footer class="mt-3 border-t border-line pt-3">
            <Link
                :href="localized(`/areas/${identity.slug}`)"
                class="mh-lux-btn mh-lux-btn-secondary w-full !py-2 text-sm"
                data-testid="area-card-view-full"
            >
                {{ t('map.area_card.view_full') }}
                <AppIcon name="arrow-end" class="h-4 w-4 shrink-0 rtl:-scale-x-100" aria-hidden="true" />
            </Link>
        </footer>
    </section>
</template>
