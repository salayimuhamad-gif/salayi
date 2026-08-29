<script setup lang="ts">
import { computed } from 'vue';
import { Link } from '@inertiajs/vue3';
import { t } from '@/lib/i18n';
import { useLocale } from '@/Composables/useLocale';
import { COMPARE_IDENTITIES } from '@/lib/map';
import type { MapBounds } from '@/lib/map';

/*
 * The area comparison panel (Map Phase 6): a PRESENTATION of the
 * /map/compare payload — every figure, difference and refusal in it was
 * computed server-side by the existing market authorities; nothing here
 * calculates, ranks or scores. Two layouts from one payload: aligned
 * metric rows with one column per area on desktop, stacked A/B/C cards
 * below lg — never a squeezed three-column table on a phone.
 */

export interface CompareServiceGroup {
    key: string;
    label: string;
    count: number;
}

export interface CompareIndexRow {
    key: string;
    name: string;
    price_type: string;
    basis: string;
    value: string;
    change_percent: string | null;
    period: string;
    currency: string;
    sample_size: number | null;
    confidence: string;
    is_limited: boolean;
    requires_qualifier: boolean;
}

export interface CompareMovementRow {
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
    methodology_version: string;
    period_current: string;
    period_previous: string;
    sample_size: number | null;
    confidence: string;
}

export interface CompareAreaColumn {
    slug: string;
    name: string;
    type: string;
    type_label: string;
    breadcrumb: Array<{ name: string }>;
    lat: number | null;
    lng: number | null;
    bounds: MapBounds | null;
    services: CompareServiceGroup[] | null;
    services_reason: string | null;
    prices: { available: boolean; reason: string | null; area_name: string | null; indices: CompareIndexRow[] };
    movement: CompareMovementRow | null;
}

export interface CompareFact {
    key: string;
    params: Record<string, string | null>;
}

export interface CompareResponse {
    filters: { transaction: string; window: string; property_type: string | null };
    windows: Record<string, boolean>;
    property_types: string[];
    movement: { available: boolean; reason: string | null };
    areas: CompareAreaColumn[];
    market_comparison: { comparable: boolean; reason: string | null; signature: Record<string, string | null> | null };
    facts: CompareFact[];
}

const props = defineProps<{
    selection: Array<{ slug: string; name: string }>;
    data: CompareResponse | null;
    phase: 'idle' | 'loading' | 'ready' | 'error' | 'rate_limited';
    focused: string | null;
}>();

const emit = defineEmits<{
    remove: [slug: string];
    focus: [slug: string];
    retry: [];
    addRequest: [];
    showAll: [];
}>();

const { localized } = useLocale();

const columns = computed<CompareAreaColumn[]>(() => props.data?.areas ?? []);

function identity(position: number): { colour: string; letter: string } {
    return COMPARE_IDENTITIES[position] ?? { colour: '#60a5fa', letter: '?' };
}

/**
 * Group rows unioned across every column IN the product's own order (each
 * column arrives ordered; unseen groups append as first encountered). A
 * group one area lacks renders "0 recorded" — a count of qualifying MULK
 * records, never a claim that the service does not exist in reality (§23).
 */
const serviceGroups = computed<Array<{ key: string; label: string }>>(() => {
    const seen = new Map<string, string>();

    for (const column of columns.value) {
        for (const group of column.services ?? []) {
            if (!seen.has(group.key)) {
                seen.set(group.key, group.label);
            }
        }
    }

    return [...seen.entries()].map(([key, label]) => ({ key, label }));
});

const servicesUnavailable = computed(() =>
    columns.value.length > 0 && columns.value.every((column) => column.services === null));

function serviceCount(column: CompareAreaColumn, key: string): number | null {
    if (column.services === null) return null;

    return column.services.find((group) => group.key === key)?.count ?? 0;
}

/** The one movement-state line above the grid, when there is one to state. */
const movementNote = computed<string | null>(() => {
    const data = props.data;

    if (!data) return null;

    if (!data.movement.available) {
        return data.movement.reason === 'feature_disabled'
            ? t('map.compare.unavailable_feature')
            : t(`market.movement.reasons.${data.movement.reason}`);
    }

    if (!data.market_comparison.comparable && data.market_comparison.reason !== null
        && data.market_comparison.reason !== 'insufficient_claims') {
        return t('map.compare.not_comparable', {
            reason: t(`map.compare.reasons.${data.market_comparison.reason}`),
        });
    }

    return null;
});

function movementPercent(row: CompareMovementRow): string {
    const percent = row.change_percent;

    if (percent === null) return '';

    return percent.startsWith('-') ? `${percent}%` : `+${percent}%`;
}

function areaName(slug: string | null): string {
    if (slug === null) return '';

    return columns.value.find((column) => column.slug === slug)?.name
        ?? props.selection.find((slot) => slot.slug === slug)?.name
        ?? slug;
}

/**
 * Server-computed facts, localized: name-bearing params resolve slug →
 * display name, reason params resolve through the reasons table, numbers
 * pass through as the server formatted them.
 */
const factLines = computed<string[]>(() => {
    const data = props.data;

    if (!data) return [];

    const named = new Set(['a', 'b', 'higher', 'lower', 'stronger', 'rising', 'falling']);

    return data.facts.map((fact) => {
        const params: Record<string, string> = {};

        for (const [key, value] of Object.entries(fact.params)) {
            if (value === null) continue;

            if (named.has(key)) {
                params[key] = areaName(value);
            } else if (key === 'reason') {
                params[key] = t(`map.compare.reasons.${value}`);
            } else {
                params[key] = value;
            }
        }

        return t(`map.compare.facts.${fact.key}`, params);
    });
});

function priceUnavailable(column: CompareAreaColumn): string {
    return column.prices.reason === 'feature_disabled'
        ? t('map.compare.unavailable_feature')
        : t('map.compare.no_price');
}
</script>

<template>
    <section :aria-label="t('map.compare.title')" data-testid="compare-panel" class="space-y-3">
        <div class="flex items-center justify-between gap-2">
            <h2 class="mh-label">{{ t('map.compare.title') }}</h2>
            <button
                v-if="selection.length >= 2"
                type="button"
                data-testid="compare-show-all"
                class="mh-touch-target rounded-card border border-line px-3 py-1 text-xs text-ink-muted
                       transition-colors hover:text-ink
                       focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-accent"
                @click="emit('showAll')"
            >
                {{ t('map.compare.show_all') }}
            </button>
        </div>

        <!-- The A/B/C slots — ONE canonical set, mirrored by the map outlines. -->
        <ul class="flex flex-wrap items-center gap-2" data-testid="compare-slots">
            <li v-for="(slot, position) in selection" :key="slot.slug">
                <span
                    class="flex items-center gap-2 rounded-card border border-line px-2.5 py-1.5 text-sm text-ink"
                    :data-testid="`compare-slot-${identity(position).letter}`"
                >
                    <span
                        class="flex h-5 w-5 shrink-0 items-center justify-center rounded-full text-[10px] font-bold text-white"
                        :style="{ backgroundColor: identity(position).colour }"
                        aria-hidden="true"
                    >{{ identity(position).letter }}</span>
                    <span class="max-w-[9rem] truncate">{{ slot.name }}</span>
                    <button
                        type="button"
                        class="mh-touch-target -me-1 rounded-card px-1.5 text-ink-muted transition-colors hover:text-ink
                               focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-accent"
                        :aria-label="t('map.compare.remove', { name: slot.name })"
                        :data-testid="`compare-remove-${slot.slug}`"
                        @click="emit('remove', slot.slug)"
                    >
                        ✕
                    </button>
                </span>
            </li>
            <li v-if="selection.length < 3">
                <button
                    type="button"
                    data-testid="compare-add"
                    class="mh-touch-target rounded-card border border-dashed border-line px-3 py-1.5 text-sm text-ink-muted
                           transition-colors hover:text-ink
                           focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-accent"
                    @click="emit('addRequest')"
                >
                    + {{ t('map.compare.add') }}
                </button>
            </li>
        </ul>

        <!-- F-2: a throttled refresh says "wait" while the selected slots
             AND the last comparison below stand exactly as they were —
             never the error state, never a blank. -->
        <p
            v-if="phase === 'rate_limited'"
            data-testid="compare-rate-limited"
            class="text-xs text-caution"
            aria-live="polite"
        >
            {{ t('map.compare.rate_limited') }}
        </p>

        <p v-if="selection.length < 2" class="text-sm text-ink-muted" data-testid="compare-hint">
            {{ t('map.compare.need_two') }}
        </p>

        <p v-else-if="phase === 'loading' && !data" class="text-sm text-ink-muted">
            {{ t('map.compare.loading') }}
        </p>

        <!-- §53: a dropped comparison keeps the slots and offers retry —
             the visitor never re-selects areas over a network blip. -->
        <div
            v-else-if="phase === 'error'"
            data-testid="compare-error"
            class="flex items-center justify-between gap-3 rounded-card border border-line p-3"
        >
            <span class="text-sm text-ink-muted">{{ t('map.compare.error') }}</span>
            <button
                type="button"
                data-testid="compare-retry"
                class="mh-touch-target shrink-0 rounded-card border border-line px-3 py-1 text-xs text-ink
                       transition-colors hover:bg-surface-sunken
                       focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-accent"
                @click="emit('retry')"
            >
                {{ t('map.states.retry') }}
            </button>
        </div>

        <template v-else-if="data && columns.length >= 2">
            <p v-if="movementNote" data-testid="compare-movement-note" class="text-xs text-caution">
                {{ movementNote }}
            </p>

            <!-- ------------------------------------------- desktop: grid -->
            <div class="hidden space-y-1 lg:block" data-testid="compare-grid">
                <div
                    class="grid items-end gap-2 border-b border-line pb-2"
                    :style="{ gridTemplateColumns: `minmax(5rem, 0.7fr) repeat(${columns.length}, minmax(0, 1fr))` }"
                >
                    <span aria-hidden="true" />
                    <button
                        v-for="(column, position) in columns"
                        :key="column.slug"
                        type="button"
                        class="rounded-card p-1 text-start transition-colors hover:bg-surface-sunken
                               focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-accent"
                        :class="focused === column.slug ? 'bg-surface-sunken' : ''"
                        :aria-pressed="focused === column.slug"
                        :data-testid="`compare-column-${column.slug}`"
                        @click="emit('focus', column.slug)"
                    >
                        <span class="flex items-center gap-1.5">
                            <span
                                class="flex h-4 w-4 shrink-0 items-center justify-center rounded-full text-[9px] font-bold text-white"
                                :style="{ backgroundColor: identity(position).colour }"
                                aria-hidden="true"
                            >{{ identity(position).letter }}</span>
                            <span class="truncate text-sm font-medium text-ink">{{ column.name }}</span>
                        </span>
                        <span class="mt-0.5 block truncate text-xs text-ink-faint">{{ column.type_label }}</span>
                    </button>
                </div>

                <!-- movement -->
                <div
                    class="grid gap-2 border-b border-line py-2"
                    :style="{ gridTemplateColumns: `minmax(5rem, 0.7fr) repeat(${columns.length}, minmax(0, 1fr))` }"
                >
                    <span class="text-xs text-ink-faint">{{ t('map.compare.rows.movement') }}</span>
                    <span v-for="column in columns" :key="column.slug" class="min-w-0 text-xs">
                        <template v-if="column.movement">
                            <span class="text-ink">{{ t(`market.movement.direction.${column.movement.direction}`) }}</span>
                            <span class="numeral ms-1 text-ink" dir="ltr">{{ movementPercent(column.movement) }}</span>
                            <span v-if="column.movement.requires_qualifier" class="ms-1 text-caution" :title="t('market.public.qualifier.sale_asking')">*</span>
                        </template>
                        <span v-else class="text-ink-faint">{{ t('map.market.unknown') }}</span>
                    </span>
                </div>

                <!-- current price evidence, every index its own row -->
                <div
                    class="grid gap-2 border-b border-line py-2"
                    :style="{ gridTemplateColumns: `minmax(5rem, 0.7fr) repeat(${columns.length}, minmax(0, 1fr))` }"
                >
                    <span class="text-xs text-ink-faint">{{ t('map.compare.rows.prices') }}</span>
                    <span v-for="column in columns" :key="column.slug" class="min-w-0 space-y-1.5 text-xs">
                        <template v-if="column.prices.available">
                            <span v-for="row in column.prices.indices" :key="row.key" class="block">
                                <span class="block truncate text-ink-muted">{{ row.name }}</span>
                                <span class="numeral text-ink" dir="ltr">{{ row.value }} {{ row.currency }}</span>
                                <span v-if="row.requires_qualifier" class="ms-1 text-caution" :title="t('market.public.qualifier.sale_asking')">*</span>
                            </span>
                            <span
                                v-if="column.prices.area_name && column.prices.area_name !== column.name"
                                class="block truncate text-ink-faint"
                            >{{ t('map.compare.from_area', { name: column.prices.area_name }) }}</span>
                        </template>
                        <span v-else class="text-ink-faint">{{ priceUnavailable(column) }}</span>
                    </span>
                </div>

                <!-- services: raw recorded counts, group by group -->
                <template v-if="!servicesUnavailable">
                    <div
                        v-for="group in serviceGroups"
                        :key="group.key"
                        class="grid gap-2 border-b border-line py-1.5"
                        :style="{ gridTemplateColumns: `minmax(5rem, 0.7fr) repeat(${columns.length}, minmax(0, 1fr))` }"
                        :data-testid="`compare-service-${group.key}`"
                    >
                        <span class="truncate text-xs text-ink-faint">{{ group.label }}</span>
                        <span v-for="column in columns" :key="column.slug" class="text-xs">
                            <span v-if="serviceCount(column, group.key) === 0" class="text-ink-faint">
                                {{ t('map.compare.zero_recorded') }}
                            </span>
                            <span v-else class="numeral text-ink" dir="ltr">{{ serviceCount(column, group.key) }}</span>
                        </span>
                    </div>
                </template>
                <p v-else class="py-1.5 text-xs text-ink-faint" data-testid="compare-services-unavailable">
                    {{ t('map.compare.unavailable_feature') }}
                </p>

                <div
                    class="grid gap-2 pt-2"
                    :style="{ gridTemplateColumns: `minmax(5rem, 0.7fr) repeat(${columns.length}, minmax(0, 1fr))` }"
                >
                    <span aria-hidden="true" />
                    <span v-for="column in columns" :key="column.slug">
                        <Link
                            :href="localized(`/areas/${column.slug}`)"
                            class="text-xs text-ink underline underline-offset-2 hover:text-accent-strong
                                   focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-accent"
                            :data-testid="`compare-view-${column.slug}`"
                        >
                            {{ t('map.compare.view_area') }}
                        </Link>
                    </span>
                </div>
            </div>

            <!-- ------------------------------------ phones/tablets: stack -->
            <div class="space-y-3 lg:hidden" data-testid="compare-stack">
                <article
                    v-for="(column, position) in columns"
                    :key="column.slug"
                    class="rounded-card border border-line p-3"
                    :class="focused === column.slug ? 'border-accent-strong/60' : ''"
                >
                    <h3 class="flex items-center gap-2 text-sm font-medium text-ink">
                        <span
                            class="flex h-5 w-5 shrink-0 items-center justify-center rounded-full text-[10px] font-bold text-white"
                            :style="{ backgroundColor: identity(position).colour }"
                            aria-hidden="true"
                        >{{ identity(position).letter }}</span>
                        <span class="truncate">{{ column.name }}</span>
                        <span class="ms-auto shrink-0 text-xs font-normal text-ink-faint">{{ column.type_label }}</span>
                    </h3>

                    <dl class="mt-2 space-y-2 text-xs">
                        <div>
                            <dt class="text-ink-faint">{{ t('map.compare.rows.movement') }}</dt>
                            <dd v-if="column.movement" class="mt-0.5 text-ink">
                                {{ t(`market.movement.direction.${column.movement.direction}`) }}
                                <span class="numeral" dir="ltr">{{ movementPercent(column.movement) }}</span>
                                <span v-if="column.movement.requires_qualifier" class="text-caution" :title="t('market.public.qualifier.sale_asking')">*</span>
                            </dd>
                            <dd v-else class="mt-0.5 text-ink-faint">{{ t('map.market.unknown') }}</dd>
                        </div>

                        <div>
                            <dt class="text-ink-faint">{{ t('map.compare.rows.prices') }}</dt>
                            <template v-if="column.prices.available">
                                <dd v-for="row in column.prices.indices" :key="row.key" class="mt-0.5">
                                    <span class="block truncate text-ink-muted">{{ row.name }}</span>
                                    <span class="numeral text-ink" dir="ltr">{{ row.value }} {{ row.currency }}</span>
                                    <span v-if="row.requires_qualifier" class="ms-1 text-caution">*</span>
                                </dd>
                            </template>
                            <dd v-else class="mt-0.5 text-ink-faint">{{ priceUnavailable(column) }}</dd>
                        </div>

                        <div v-if="column.services !== null">
                            <dt class="text-ink-faint">{{ t('map.compare.rows.services') }}</dt>
                            <dd v-for="group in serviceGroups" :key="group.key" class="mt-0.5 flex justify-between gap-2">
                                <span class="truncate text-ink-muted">{{ group.label }}</span>
                                <span v-if="serviceCount(column, group.key) === 0" class="shrink-0 text-ink-faint">
                                    {{ t('map.compare.zero_recorded') }}
                                </span>
                                <span v-else class="numeral shrink-0 text-ink" dir="ltr">{{ serviceCount(column, group.key) }}</span>
                            </dd>
                        </div>
                        <div v-else>
                            <dt class="text-ink-faint">{{ t('map.compare.rows.services') }}</dt>
                            <dd class="mt-0.5 text-ink-faint">{{ t('map.compare.unavailable_feature') }}</dd>
                        </div>
                    </dl>

                    <Link
                        :href="localized(`/areas/${column.slug}`)"
                        class="mt-2 inline-block text-xs text-ink underline underline-offset-2 hover:text-accent-strong
                               focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-accent"
                    >
                        {{ t('map.compare.view_area') }}
                    </Link>
                </article>
            </div>

            <!-- Key differences — strictly factual, server-computed (§46). -->
            <div v-if="factLines.length > 0" data-testid="compare-facts">
                <p class="mh-label mb-1.5">{{ t('map.compare.facts_title') }}</p>
                <ul class="space-y-1">
                    <li v-for="(line, index) in factLines" :key="index" class="text-xs text-ink-muted" dir="auto">
                        {{ line }}
                    </li>
                </ul>
            </div>
        </template>
    </section>
</template>
