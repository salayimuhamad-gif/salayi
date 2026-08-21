<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';
import AppIcon from '@/Components/Icons/AppIcon.vue';
import MarketTrendChart from '@/Components/Public/MarketTrendChart.vue';
import { t, formatNumber } from '@/lib/i18n';
import { useLocale } from '@/Composables/useLocale';

/*
 * Market pulse (Wave 4) — gainers and decliners DERIVED, never stored and
 * never invented, from the published index series through the calculator's
 * own comparability rules.
 *
 * Everything this panel renders arrives from /market/movement, which
 * recomputes on every call. The panel adds no arithmetic of its own: when
 * the server says a pair is incomparable there is no mover, and when it
 * says a window has no honest pair the option renders disabled with the
 * reason — a missing movement is NEVER a 0%.
 *
 * Direction is icon AND word, not colour alone (§13): "↑ +4.2% Rising"
 * survives red-green colour blindness; green text does not. Percentages and
 * prices sit in `.numeral` spans with `dir="ltr"` so a signed figure keeps
 * its order inside RTL copy, the same convention every market card uses.
 * The trend chart itself is never mirrored (§8.4).
 */

interface MoverEntity {
    slug: string;
    type: string;
    name: string | null;
}

interface Mover {
    entity: MoverEntity;
    index_key: string;
    property_type: string | null;
    transaction: string;
    price_type: string;
    family: string;
    requires_qualifier: boolean;
    basis: string;
    currency: string;
    current: { period: string; value: string; sample_size: number | null; confidence: string };
    previous: { period: string; value: string };
    change_percent: string;
    direction: 'up' | 'down' | 'flat';
    sparkline: Array<{ period: string; value: string; is_limited: boolean }> | null;
}

interface MovementResponse {
    available: boolean;
    reason: string | null;
    transaction: string;
    window: string;
    windows: Record<string, boolean>;
    property_types: string[];
    gainers: Mover[];
    losers: Mover[];
    flat: Mover[];
}

withDefaults(defineProps<{
    /** 'lux' wears the Midnight Amber glass (homepage); 'card' sits flat on /market. */
    variant?: 'lux' | 'card';
}>(), { variant: 'card' });

const { localized } = useLocale();

const TRANSACTIONS = ['sale', 'rent'] as const;
const WINDOWS = ['7d', '30d', '1m', '3m', '6m', '1y', 'all'] as const;
const BUCKETS = ['gainers', 'losers'] as const;

const transaction = ref<string>('sale');
const window_ = ref<string>('all');
const categories = ref<string[]>([]);

const phase = ref<'loading' | 'ready' | 'error'>('loading');
const data = ref<MovementResponse | null>(null);
const root = ref<HTMLElement | null>(null);

let controller: AbortController | null = null;
let observer: IntersectionObserver | null = null;
let requested = false;

async function load(): Promise<void> {
    controller?.abort();
    controller = new AbortController();
    phase.value = 'loading';

    const query = new URLSearchParams();
    query.set('transaction', transaction.value);
    query.set('period', window_.value);
    for (const category of categories.value) {
        query.append('property_types[]', category);
    }

    try {
        const response = await fetch(localized(`/market/movement?${query.toString()}`), {
            headers: { Accept: 'application/json' },
            signal: controller.signal,
        });

        if (!response.ok) {
            phase.value = 'error';
            return;
        }

        data.value = (await response.json()) as MovementResponse;
        phase.value = 'ready';
    } catch (failure) {
        if ((failure as { name?: string }).name === 'AbortError') return;
        phase.value = 'error';
    }
}

/*
 * The first request waits for the panel to actually be seen. On the
 * homepage this section sits below the fold, and fetching it on every
 * page load would spend its rate budget for visitors who never scroll —
 * the exact shared-budget mistake the Wave 3 E2E repair documents. The
 * same intersection trigger AnimatedCounter already uses.
 */
onMounted(() => {
    if (typeof IntersectionObserver === 'undefined' || root.value === null) {
        requested = true;
        void load();
        return;
    }

    observer = new IntersectionObserver((entries) => {
        if (requested || !entries.some((entry) => entry.isIntersecting)) return;
        requested = true;
        observer?.disconnect();
        void load();
    }, { rootMargin: '120px' });

    observer.observe(root.value);
});

onBeforeUnmount(() => {
    observer?.disconnect();
    controller?.abort();
});

function pickTransaction(mode: string): void {
    if (transaction.value === mode) return;
    transaction.value = mode;
    void load();
}

function pickWindow(key: string): void {
    if (window_.value === key) return;
    window_.value = key;
    void load();
}

function toggleCategory(value: string | null): void {
    if (value === null) {
        if (categories.value.length === 0) return;
        categories.value = [];
    } else if (categories.value.includes(value)) {
        categories.value = categories.value.filter((c) => c !== value);
    } else {
        categories.value = [...categories.value, value];
    }
    void load();
}

/* The category vocabulary comes from the server's enum exposure — a case
 * added to PropertyType appears here without touching this component. */
const propertyTypes = computed<string[]>(() => data.value?.property_types ?? []);

const windowEnabled = (key: string): boolean => data.value?.windows?.[key] ?? false;

/* The selected window itself stays operable even when it currently has no
 * pair — its emptiness is explained in the body — but every OTHER window
 * that has no honest pair is disabled with the reason on the control. */
const windowDisabled = (key: string): boolean =>
    phase.value === 'ready' && key !== window_.value && !windowEnabled(key);

const movers = computed(() => ({
    gainers: data.value?.gainers ?? [],
    losers: data.value?.losers ?? [],
    flat: data.value?.flat ?? [],
}));

const isEmpty = computed(
    () => phase.value === 'ready' && (data.value === null || !data.value.available),
);

const signed = (mover: Mover): string =>
    (mover.direction === 'up' ? '+' : '') + mover.change_percent;

const directionIcon = (mover: Mover): 'trend-up' | 'trend-down' | 'trend-flat' =>
    mover.direction === 'up' ? 'trend-up' : mover.direction === 'down' ? 'trend-down' : 'trend-flat';

const directionTone = (mover: Mover): string =>
    mover.direction === 'up' ? 'text-positive' : mover.direction === 'down' ? 'text-negative' : 'text-ink-muted';

const formatValue = (value: string): string => {
    const parsed = Number(value);
    return Number.isFinite(parsed) ? formatNumber(parsed, Number.isInteger(parsed) ? 0 : 2) : value;
};
</script>

<template>
    <section
        ref="root"
        :class="variant === 'lux' ? 'mh-lux-panel !rounded-glass p-4 sm:p-5' : 'rounded-xl border border-line bg-surface p-4 sm:p-5'"
        :aria-label="t('market.movement.title')"
        data-testid="market-movement"
    >
        <header class="flex flex-wrap items-baseline justify-between gap-x-6 gap-y-1">
            <div>
                <h2 :class="variant === 'lux' ? 'mh-microlabel' : 'text-sm font-semibold text-ink'">
                    {{ t('market.movement.title') }}
                </h2>
                <p class="mt-0.5 text-xs text-ink-faint">{{ t('market.movement.subtitle') }}</p>
            </div>

            <!-- Sale / Rent. One or the other, never both — the server refuses
                 to let the two bases meet, and this control only picks which
                 one is asked for. -->
            <div
                role="group"
                :aria-label="t('market.movement.transaction_label')"
                class="flex rounded-lg border border-line p-0.5"
            >
                <button
                    v-for="mode in TRANSACTIONS"
                    :key="mode"
                    type="button"
                    class="rounded-md px-3 py-1 text-xs font-medium transition-colors focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-accent"
                    :class="transaction === mode ? 'bg-accent/15 text-ink' : 'text-ink-muted hover:text-ink'"
                    :aria-pressed="transaction === mode"
                    :data-testid="`movement-transaction-${mode}`"
                    @click="pickTransaction(mode)"
                >
                    {{ t(`market.movement.transaction.${mode}`) }}
                </button>
            </div>
        </header>

        <!-- Period chips: honestly disabled when the stored evidence holds no
             comparable pair for that window, with the reason on the control. -->
        <div
            role="group"
            :aria-label="t('market.movement.period_label')"
            class="mt-3 flex flex-wrap gap-1.5"
        >
            <button
                v-for="key in WINDOWS"
                :key="key"
                type="button"
                class="rounded-full border px-2.5 py-1 text-[11px] font-medium transition-colors focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-accent disabled:cursor-not-allowed disabled:opacity-45"
                :class="window_ === key ? 'border-accent/60 bg-accent/15 text-ink' : 'border-line text-ink-muted hover:text-ink'"
                :aria-pressed="window_ === key"
                :disabled="windowDisabled(key)"
                :title="windowDisabled(key) ? t('market.movement.period_unavailable') : undefined"
                :data-testid="`movement-period-${key}`"
                @click="pickWindow(key)"
            >
                <span class="numeral">{{ t(`market.movement.periods.${key}`) }}</span>
            </button>
        </div>

        <!-- Categories, straight from the PropertyType enum via the server. -->
        <div
            role="group"
            :aria-label="t('market.movement.categories_label')"
            class="mt-2 flex flex-wrap gap-1.5"
        >
            <button
                type="button"
                class="rounded-full border px-2.5 py-1 text-[11px] transition-colors focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-accent"
                :class="categories.length === 0 ? 'border-accent/60 bg-accent/15 text-ink' : 'border-line text-ink-muted hover:text-ink'"
                :aria-pressed="categories.length === 0"
                data-testid="movement-category-all"
                @click="toggleCategory(null)"
            >
                {{ t('market.movement.all_categories') }}
            </button>
            <button
                v-for="value in propertyTypes"
                :key="value"
                type="button"
                class="rounded-full border px-2.5 py-1 text-[11px] transition-colors focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-accent"
                :class="categories.includes(value) ? 'border-accent/60 bg-accent/15 text-ink' : 'border-line text-ink-muted hover:text-ink'"
                :aria-pressed="categories.includes(value)"
                :data-testid="`movement-category-${value}`"
                @click="toggleCategory(value)"
            >
                {{ t(`market.property_types.${value}`) }}
            </button>
        </div>

        <!-- States: loading / failed / honestly empty / movers. -->
        <p v-if="phase === 'loading'" role="status" class="mt-5 text-sm text-ink-muted">
            {{ t('market.movement.loading') }}
        </p>

        <div v-else-if="phase === 'error'" class="mt-5" data-testid="movement-error">
            <p class="text-sm text-ink-muted">{{ t('market.movement.error') }}</p>
            <button
                type="button"
                class="mt-2 rounded-md border border-line px-3 py-1 text-xs text-ink hover:border-accent/60 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-accent"
                @click="load"
            >
                {{ t('market.movement.retry') }}
            </button>
        </div>

        <div v-else-if="isEmpty" class="mt-5" data-testid="movement-empty">
            <p class="text-sm text-ink-muted" data-testid="movement-reason">
                {{ data?.reason ? t(`market.movement.reasons.${data.reason}`) : t('market.movement.reasons.insufficient_history') }}
            </p>
        </div>

        <div v-else class="mt-5 grid gap-4 lg:grid-cols-2">
            <div
                v-for="bucket in BUCKETS"
                :key="bucket"
                :data-testid="`movement-${bucket}`"
            >
                <h3 class="mh-microlabel mb-2">{{ t(`market.movement.${bucket}`) }}</h3>

                <p v-if="movers[bucket].length === 0" class="text-xs text-ink-faint">
                    {{ t('market.movement.reasons.no_compatible_pair') }}
                </p>

                <ul v-else class="space-y-2.5">
                    <li
                        v-for="mover in movers[bucket]"
                        :key="`${mover.index_key}|${mover.current.period}`"
                        :class="variant === 'lux' ? 'mh-lux-card !rounded-glass px-4 py-3' : 'rounded-lg border border-line px-4 py-3'"
                        data-testid="movement-card"
                    >
                        <div class="flex items-start justify-between gap-3">
                            <div class="min-w-0">
                                <p class="truncate text-sm font-medium text-ink">{{ mover.entity.name }}</p>
                                <p class="mt-0.5 text-[11px] text-ink-faint">
                                    {{ t(`market.movement.entity_types.${mover.entity.type}`) }}
                                    <template v-if="mover.property_type">
                                        · {{ t(`market.property_types.${mover.property_type}`) }}
                                    </template>
                                </p>
                            </div>

                            <!-- Direction: arrow + signed figure + WORD. -->
                            <p
                                class="flex shrink-0 items-center gap-1 text-sm font-medium"
                                :class="directionTone(mover)"
                                data-testid="movement-direction"
                            >
                                <AppIcon :name="directionIcon(mover)" class="h-4 w-4 shrink-0" aria-hidden="true" />
                                <span class="numeral" dir="ltr">{{ signed(mover) }}%</span>
                                <span>{{ t(`market.movement.direction.${mover.direction}`) }}</span>
                            </p>
                        </div>

                        <div class="mt-2 flex flex-wrap items-baseline gap-x-3 gap-y-1">
                            <span class="text-[11px] text-ink-faint">{{ t('market.movement.current_price') }}</span>
                            <span class="numeral text-base font-medium text-ink" dir="ltr">
                                {{ formatValue(mover.current.value) }}
                                <span class="text-xs font-normal text-ink-muted">{{ mover.currency }}</span>
                            </span>
                            <span class="text-[11px] text-ink-faint">
                                {{ t('market.movement.previous') }}
                                <span class="numeral" dir="ltr">{{ mover.previous.period }}</span>
                            </span>
                        </div>

                        <!-- The basis and provenance labels every figure keeps
                             (§14.1/§15.3): an unlabelled asking movement reads
                             as a transaction movement. -->
                        <div class="mt-2 flex flex-wrap gap-1.5">
                            <span :class="variant === 'lux' ? 'mh-lux-chip !py-0.5 text-[11px]' : 'rounded-full border border-line px-2 py-0.5 text-[11px] text-ink-muted'">
                                {{ t(`market.price_types.${mover.price_type}`) }}
                            </span>
                            <span :class="variant === 'lux' ? 'mh-lux-chip !py-0.5 text-[11px]' : 'rounded-full border border-line px-2 py-0.5 text-[11px] text-ink-muted'">
                                {{ t(`market.basis.${mover.basis}`) }}
                            </span>
                        </div>

                        <MarketTrendChart
                            v-if="mover.sparkline && mover.sparkline.length > 1"
                            :series="mover.sparkline"
                            :height="26"
                            class="mt-2.5"
                            :aria-label="t('market.movement.trend')"
                            data-testid="movement-sparkline"
                        />

                        <dl class="mt-2.5 flex flex-wrap gap-x-3.5 gap-y-1 border-t border-line pt-2 text-[11px]">
                            <div class="flex items-center gap-1.5">
                                <dt class="text-ink-faint">{{ t('market.movement.as_of') }}</dt>
                                <dd class="numeral text-ink-muted" dir="ltr">{{ mover.current.period }}</dd>
                            </div>
                            <div v-if="mover.current.sample_size" class="flex items-center gap-1.5">
                                <dt class="text-ink-faint">{{ t('market.explanation.sample') }}</dt>
                                <dd class="numeral text-ink-muted">{{ formatNumber(mover.current.sample_size) }}</dd>
                            </div>
                            <div class="flex items-center gap-1.5">
                                <dt class="text-ink-faint">{{ t('market.explanation.confidence') }}</dt>
                                <dd class="text-ink-muted">{{ t(`market.confidence.${mover.current.confidence}`) }}</dd>
                            </div>
                        </dl>

                        <p v-if="mover.requires_qualifier" class="mt-2 text-[11px] text-ink-faint">
                            {{ t(`market.public.qualifier.${mover.price_type}`) }}
                        </p>
                    </li>
                </ul>
            </div>
        </div>

        <!-- Genuine 0.00% pairs — real comparisons, listed apart so "steady"
             is a statement and never a stand-in for missing data. -->
        <div v-if="phase === 'ready' && !isEmpty && movers.flat.length > 0" class="mt-4" data-testid="movement-flat">
            <h3 class="mh-microlabel mb-2">{{ t('market.movement.flat') }}</h3>
            <ul class="flex flex-wrap gap-2">
                <li
                    v-for="mover in movers.flat"
                    :key="`${mover.index_key}|flat`"
                    class="flex items-center gap-1.5 rounded-full border border-line px-2.5 py-1 text-[11px] text-ink-muted"
                >
                    <AppIcon name="trend-flat" class="h-3.5 w-3.5 shrink-0" aria-hidden="true" />
                    <span class="truncate">{{ mover.entity.name }}</span>
                    <span class="numeral" dir="ltr">0%</span>
                    <span>{{ t('market.movement.direction.flat') }}</span>
                </li>
            </ul>
        </div>
    </section>
</template>
