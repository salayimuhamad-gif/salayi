<script setup lang="ts">
import { computed, ref } from 'vue';
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import PublicLayout from '@/Layouts/PublicLayout.vue';
import AppCard from '@/Components/ui/AppCard.vue';
import AppButton from '@/Components/ui/AppButton.vue';
import AppAlert from '@/Components/ui/AppAlert.vue';
import MarketTrendChart from '@/Components/Public/MarketTrendChart.vue';
import PropertyForm from '@/Components/Portfolio/PropertyForm.vue';
import { t } from '@/lib/i18n';
import { useLocale } from '@/Composables/useLocale';

const { localized } = useLocale();

/*
 * One property (spec §11): the facts, an edit form behind a fold, the
 * valuation trigger, and the full history.
 *
 * The valuation section carries three honesty devices at once — the
 * estimate-not-guarantee sentence sits next to the button rather than in a
 * footer; a refusal renders as a translated REASON instead of an empty
 * number; and the history keeps every event, refusals included, because
 * "we could not value this in March" is part of the record.
 */
/*
 * Wave 6: an adjustment as SNAPSHOTTED on the valuation row — labels and
 * a derived direction, independent of whatever the live rule tables say
 * today. The exact percent stays server-side (snapshot + admin builder);
 * the owner sees which factors moved the estimate and which way.
 */
interface AdjustmentRow {
    question_key: string;
    question_ckb: string;
    question_ar: string;
    question_en: string;
    option_ckb: string;
    option_ar: string;
    option_en: string;
    direction: 'positive' | 'negative' | 'neutral';
    position: number;
}

interface HistoryRow {
    midpoint: string | null;
    low: string | null;
    high: string | null;
    base_midpoint: string | null;
    adjustment_total_percent: string | null;
    adjustments: AdjustmentRow[];
    currency: string;
    confidence: string;
    match_level: number | null;
    match_label: string | null;
    methodology: string | null;
    comparison_count: number | null;
    no_valuation_reason: string | null;
    created_at: string | null;
}

interface NamedOption { id: number; name_ckb: string; name_ar: string | null; name_en: string | null }

interface RuleOption { id: number; key: string; label_ckb: string; label_ar: string; label_en: string }
interface RuleQuestion {
    id: number;
    key: string;
    question_type: string;
    label_ckb: string;
    label_ar: string;
    label_en: string;
    help_ckb: string | null;
    help_ar: string | null;
    help_en: string | null;
    options: RuleOption[];
}

const props = defineProps<{
    property: {
        id: number;
        label: string | null;
        property_type: string;
        unit_type: string | null;
        rooms: number | null;
        floor: number | null;
        project_id: number | null;
        area_id: number | null;
        size_sqm: string | null;
        purchase_price: string | null;
        purchase_date: string | null;
        ownership_percent: string | null;
        occupancy_status: string | null;
        currency: string;
        consent_valuation: boolean;
        has_location: boolean;
        location_precision: string;
        valuation: {
            midpoint: string | null;
            low: string | null;
            high: string | null;
            base_midpoint: string | null;
            base_low: string | null;
            base_high: string | null;
            adjustment_total_percent: string | null;
            confidence: string;
            no_valuation_reason: string | null;
            calculated_at: string | null;
        } | null;
    };
    history: HistoryRow[];
    valuation_rules: {
        questions: RuleQuestion[];
        answers: Record<number, number>;
        stale: number;
    } | null;
    valuation_adjustments: AdjustmentRow[];
    adjustment_warning_threshold: string;
    media: Array<{
        id: number;
        kind: 'image' | 'document';
        name: string;
        mime: string;
        size_bytes: number;
        created_at: string | null;
    }>;
    projects: NamedOption[];
    areas: NamedOption[];
}>();

const editing = ref(false);
const valuing = ref(false);

const page = usePage();

function requestValuation(): void {
    valuing.value = true;
    router.post(localized(`/account/portfolio/${props.property.id}/valuation`), {}, {
        preserveScroll: true,
        onFinish: () => { valuing.value = false; },
    });
}

/*
 * Media (correction §6.8). The page holds labels and sizes only; every byte
 * travels through the authorised streaming route, so the download href IS
 * the authorisation check. Uploads go as multipart through Inertia and the
 * server answers with a redirect back into this page, errors keyed `file`.
 */
const mediaInput = ref<HTMLInputElement | null>(null);
const mediaBusy = ref(false);

function uploadMedia(): void {
    const file = mediaInput.value?.files?.[0];
    if (!file) return;

    mediaBusy.value = true;
    router.post(
        localized(`/account/portfolio/${props.property.id}/media`),
        { file },
        {
            forceFormData: true,
            preserveScroll: true,
            onFinish: () => {
                mediaBusy.value = false;
                if (mediaInput.value) mediaInput.value.value = '';
            },
        },
    );
}

function deleteMedia(id: number, name: string): void {
    if (!window.confirm(`${t('portfolio.media.delete_confirm')} — ${name}`)) return;

    router.delete(localized(`/account/portfolio/${props.property.id}/media/${id}`), {
        preserveScroll: true,
    });
}

function mediaHref(id: number): string {
    return localized(`/account/portfolio/${props.property.id}/media/${id}`);
}

function formatSize(bytes: number): string {
    if (bytes >= 1024 * 1024) return `${(bytes / (1024 * 1024)).toFixed(1)} MB`;

    return `${Math.max(1, Math.round(bytes / 1024))} KB`;
}

/*
 * The repository's client-side name fallback (the same rule Profile has always
 * used): the page's language first, Sorani as the floor. Real stored identity
 * only — a property with neither reference simply shows none.
 */
function entityName(option: NamedOption | undefined): string | null {
    if (!option) return null;

    const locale = document.documentElement.lang || 'ckb';

    return (locale === 'ar' ? option.name_ar : locale === 'en' ? option.name_en : option.name_ckb)
        ?? option.name_ckb;
}

/*
 * Wave 6 breakdown helpers. Labels come from the SNAPSHOT rows in the
 * page's language (Sorani floor). Per-factor rows show a direction, not
 * the configured weight — the exact percent never reaches this page. The
 * signed percent stays for the TOTAL only, rendered verbatim — exact
 * stored scale, no reformatting — with an explicit plus so a positive
 * total cannot read as a stray number.
 */
function adjustmentLabel(row: AdjustmentRow, part: 'question' | 'option'): string {
    const locale = document.documentElement.lang || 'ckb';
    const suffix = locale === 'ar' ? 'ar' : locale === 'en' ? 'en' : 'ckb';

    return row[`${part}_${suffix}` as keyof AdjustmentRow] as string;
}

function signedPercent(value: string): string {
    return value.startsWith('-') ? `${value}%` : `+${value}%`;
}

function directionGlyph(direction: AdjustmentRow['direction']): string {
    return direction === 'positive' ? '▲' : direction === 'negative' ? '▼' : '—';
}

function directionLabel(direction: AdjustmentRow['direction']): string {
    return direction === 'positive'
        ? t('portfolio.valuation_breakdown.raised')
        : direction === 'negative'
          ? t('portfolio.valuation_breakdown.lowered')
          : t('portfolio.valuation_breakdown.no_change');
}

function directionClass(direction: AdjustmentRow['direction']): string {
    return direction === 'positive'
        ? 'text-positive'
        : direction === 'negative'
          ? 'text-negative'
          : 'text-ink-muted';
}

/** Display-only: whether |total| crosses the server's warning threshold. */
function totalWarns(total: string | null): boolean {
    if (total === null) return false;

    return Math.abs(Number.parseFloat(total)) >= Number.parseFloat(props.adjustment_warning_threshold);
}

const identity = computed<string | null>(() => {
    const parts = [
        entityName(props.property.project_id === null ? undefined : props.projects.find((p) => p.id === props.property.project_id)),
        entityName(props.property.area_id === null ? undefined : props.areas.find((a) => a.id === props.property.area_id)),
    ].filter((name): name is string => name !== null);

    return parts.length === 0 ? null : parts.join(' · ');
});

/*
 * Wave 5 §8: a chart drawn ONLY from real recorded valuations — and only the
 * ones sharing the current figure's currency, because two currencies on one
 * line is a unit error wearing a trend's clothes. Refusal rows are honesty,
 * not points; nothing is interpolated between dates. Fewer than two genuine
 * points means the list carries the history alone, with no decorative line.
 */
const chartCurrency = computed<string | null>(
    () => props.history.find((row) => row.midpoint !== null)?.currency ?? null,
);

const trendSeries = computed<Array<{ period: string; value: string; is_limited: boolean }>>(() => {
    if (chartCurrency.value === null) return [];

    return props.history
        .filter((row): row is HistoryRow & { midpoint: string; created_at: string } =>
            row.midpoint !== null && row.created_at !== null && row.currency === chartCurrency.value)
        .map((row) => ({ period: row.created_at, value: row.midpoint, is_limited: false }))
        .reverse();
});

const factRows = (): Array<[string, string | null]> => [
    [t('portfolio.form.property_type'), t(`portfolio.types.${props.property.property_type}`)],
    [t('portfolio.form.unit_type'), props.property.unit_type],
    [t('portfolio.form.size_sqm'), props.property.size_sqm],
    [t('portfolio.form.rooms'), props.property.rooms === null ? null : String(props.property.rooms)],
    [t('portfolio.form.floor'), props.property.floor === null ? null : String(props.property.floor)],
    [t('portfolio.form.purchase_price'),
     props.property.purchase_price === null ? null : `${props.property.purchase_price} ${props.property.currency}`],
    [t('portfolio.form.purchase_date'), props.property.purchase_date],
    [t('portfolio.form.ownership_percent'),
     props.property.ownership_percent === null ? null : `${props.property.ownership_percent}%`],
    [t('portfolio.form.occupancy'),
     props.property.occupancy_status === null ? null : t(`portfolio.occupancy.${props.property.occupancy_status}`)],
];
</script>

<template>
    <Head :title="property.label ?? t('portfolio.title')" />

    <PublicLayout>
    <main class="mx-auto max-w-3xl px-4 py-8 sm:py-10">
        <Link
            :href="localized('/account/portfolio')"
            class="mb-5 inline-block text-sm text-ink-muted transition-colors hover:text-ink
                   focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-accent"
        >
            {{ t('app.actions.back') }}
        </Link>

        <header class="mb-5 flex flex-wrap items-center justify-between gap-3">
            <div class="min-w-0">
                <h1 class="font-display text-2xl font-bold text-ink">{{ property.label ?? '—' }}</h1>
                <!-- Real stored identity only: the published project/area the
                     owner attached, in the page's language — or nothing. -->
                <p v-if="identity" class="mt-1 text-sm text-ink-muted" data-testid="property-entity">
                    {{ identity }}
                </p>
            </div>
            <AppButton variant="secondary" size="md" data-testid="edit-property" :aria-expanded="editing" @click="editing = !editing">
                {{ editing ? t('app.actions.cancel') : t('portfolio.form.edit') }}
            </AppButton>
        </header>

        <!-- ============================ edit ============================= -->
        <AppCard v-if="editing" class="mb-5">
            <PropertyForm
                :projects="projects"
                :areas="areas"
                :property="property"
                :valuation-rules="valuation_rules"
                @saved="editing = false"
            />
        </AppCard>

        <!-- ============================ facts ============================ -->
        <AppCard v-else class="mb-5">
            <dl class="grid gap-x-6 gap-y-2 sm:grid-cols-2">
                <template v-for="[label, value] in factRows()" :key="label">
                    <div v-if="value !== null" class="flex items-baseline justify-between gap-3 sm:block">
                        <dt class="text-xs text-ink-faint">{{ label }}</dt>
                        <dd class="numeral text-sm text-ink" dir="auto">{{ value }}</dd>
                    </div>
                </template>
            </dl>
        </AppCard>

        <!-- ========================== valuation ========================== -->
        <AppCard :title="t('portfolio.valuation_actions.title')" class="mb-5">
            <template v-if="property.valuation && property.valuation.no_valuation_reason === null">
                <p data-testid="valuation-result" class="numeral font-display text-2xl font-semibold text-ink" dir="ltr">
                    {{ property.valuation.midpoint }} {{ property.currency }}
                </p>
                <p class="numeral mt-1 text-sm text-ink-muted" dir="ltr">
                    {{ property.valuation.low }} – {{ property.valuation.high }}
                    · {{ t(`market.confidence.${property.valuation.confidence}`) }}
                </p>
                <p class="mt-1 text-xs text-ink-faint">{{ property.valuation.calculated_at }}</p>
            </template>

            <!-- The honest refusal (spec §11): the reason, not a blank. -->
            <AppAlert
                v-else-if="property.valuation && property.valuation.no_valuation_reason"
                data-testid="valuation-result"
                variant="info"
            >
                {{ t(`portfolio.valuation_actions.reason_${property.valuation.no_valuation_reason}`) }}
            </AppAlert>

            <p v-else class="text-sm text-ink-muted">{{ t('portfolio.no_valuation') }}</p>

            <!--
                Wave 6: the breakdown, rendered ONLY from this valuation's
                own snapshot — evidence baseline, each answered adjustment
                with its signed percent, the exact total, then the headline
                figure (or the refusal above) as the outcome. It renders for
                the adjustments-exceed-basis refusal too, because the
                snapshot is exactly what explains that refusal. A row with
                no base_midpoint predates the rule engine or applied
                nothing, and shows nothing extra: absence is the truth.
            -->
            <template v-if="property.valuation && property.valuation.base_midpoint !== null">
                <div
                    data-testid="valuation-breakdown"
                    class="mt-4 rounded-card border border-line bg-surface-sunken px-4 py-3"
                >
                    <p class="mh-microlabel mb-2">{{ t('portfolio.valuation_breakdown.title') }}</p>

                    <dl class="space-y-1.5 text-sm">
                        <div class="flex items-baseline justify-between gap-3">
                            <dt class="text-ink-muted">{{ t('portfolio.valuation_breakdown.baseline') }}</dt>
                            <dd class="numeral text-ink" dir="ltr">
                                {{ property.valuation.base_midpoint }} {{ property.currency }}
                            </dd>
                        </div>

                        <div
                            v-for="row in valuation_adjustments"
                            :key="row.question_key"
                            class="flex items-baseline justify-between gap-3"
                            data-testid="breakdown-adjustment"
                        >
                            <dt class="min-w-0 text-ink-muted">
                                {{ adjustmentLabel(row, 'question') }}
                                <span class="text-ink-faint">— {{ adjustmentLabel(row, 'option') }}</span>
                            </dt>
                            <dd class="shrink-0" :class="directionClass(row.direction)">
                                <span aria-hidden="true">{{ directionGlyph(row.direction) }}</span>
                                {{ directionLabel(row.direction) }}
                            </dd>
                        </div>

                        <div
                            v-if="property.valuation.adjustment_total_percent !== null"
                            class="flex items-baseline justify-between gap-3 border-t border-line pt-1.5"
                        >
                            <dt class="font-medium text-ink">{{ t('portfolio.valuation_breakdown.total') }}</dt>
                            <dd class="numeral font-medium text-ink" dir="ltr" data-testid="breakdown-total">
                                {{ signedPercent(property.valuation.adjustment_total_percent) }}
                            </dd>
                        </div>
                    </dl>

                    <!-- Uncapped by design; a large total is stated, not hidden. -->
                    <AppAlert
                        v-if="totalWarns(property.valuation.adjustment_total_percent)"
                        data-testid="adjustment-warning"
                        variant="warning"
                        class="mt-3"
                    >
                        {{ t('portfolio.valuation_breakdown.warning') }}
                    </AppAlert>
                </div>
            </template>

            <div class="mt-4 border-t border-line pt-4">
                <p v-if="!property.consent_valuation" class="text-sm text-ink-faint">
                    {{ t('portfolio.valuation_actions.consent_required') }}
                </p>
                <template v-else>
                    <AppButton
                        data-testid="request-valuation"
                        type="button"
                        size="sm"
                        :loading="valuing"
                        @click="requestValuation"
                    >
                        {{ t('portfolio.valuation_actions.request') }}
                    </AppButton>
                    <!-- Estimate, not guarantee — beside the button, not in a footer. -->
                    <p class="mt-2 text-xs leading-relaxed text-ink-faint">
                        {{ t('portfolio.valuation_actions.disclaimer') }}
                    </p>
                </template>
                <p v-if="page.props.errors?.valuation" class="mt-2 text-sm text-negative" role="alert">
                    {{ page.props.errors.valuation }}
                </p>
            </div>
        </AppCard>

        <!-- =========================== history =========================== -->
        <AppCard :title="t('portfolio.history')">
            <!-- §8: the trend line exists only when at least two REAL
                 same-currency valuations exist. No interpolation, no invented
                 intermediate values; refusal rows stay in the list below. -->
            <figure v-if="trendSeries.length >= 2" class="mb-4" data-testid="history-trend">
                <figcaption class="mh-microlabel mb-2">
                    {{ t('portfolio.history_trend') }}
                </figcaption>
                <MarketTrendChart :series="trendSeries" />
                <p class="mt-1.5 text-xs text-ink-faint">
                    {{ t('portfolio.history_trend_hint', { currency: chartCurrency ?? '' }) }}
                </p>
            </figure>

            <p v-if="history.length === 0" class="text-sm text-ink-muted">
                {{ t('portfolio.no_valuation') }}
            </p>

            <ul v-else class="divide-y divide-line" data-testid="history-list">
                <li v-for="(row, index) in history" :key="index" class="py-3">
                    <div class="flex flex-wrap items-baseline justify-between gap-3">
                        <span v-if="row.midpoint" class="numeral font-medium text-ink" dir="ltr">
                            {{ row.midpoint }} {{ row.currency }}
                        </span>
                        <span v-else class="text-sm text-ink-muted">
                            {{ row.no_valuation_reason
                                ? t(`portfolio.valuation_actions.reason_${row.no_valuation_reason}`)
                                : t('portfolio.no_valuation') }}
                        </span>
                        <span class="numeral text-xs text-ink-faint" dir="ltr">{{ row.created_at }}</span>
                    </div>

                    <p v-if="row.midpoint" class="numeral mt-1 text-xs text-ink-muted" dir="ltr">
                        {{ row.low }} – {{ row.high }}
                        · {{ t(`market.confidence.${row.confidence}`) }}
                        <template v-if="row.comparison_count"> · {{ row.comparison_count }}</template>
                        <template v-if="row.match_label"> · {{ row.match_label }}</template>
                    </p>

                    <!--
                        Wave 6: what THIS row applied, from its own snapshot
                        only. An old row shows nothing here because nothing
                        was applied — history is never reinterpreted through
                        today's rules.
                    -->
                    <div
                        v-if="row.adjustments.length > 0"
                        class="mt-1.5 space-y-0.5"
                        data-testid="history-adjustments"
                    >
                        <p v-if="row.base_midpoint" class="numeral text-xs text-ink-faint" dir="ltr">
                            {{ t('portfolio.valuation_breakdown.baseline') }}: {{ row.base_midpoint }} {{ row.currency }}
                        </p>
                        <p
                            v-for="adjustment in row.adjustments"
                            :key="adjustment.question_key"
                            class="text-xs text-ink-faint"
                            data-testid="history-adjustment-row"
                        >
                            {{ adjustmentLabel(adjustment, 'question') }} —
                            {{ adjustmentLabel(adjustment, 'option') }}
                            <span :class="directionClass(adjustment.direction)">
                                <span aria-hidden="true">{{ directionGlyph(adjustment.direction) }}</span>
                                {{ directionLabel(adjustment.direction) }}
                            </span>
                        </p>
                        <p
                            v-if="row.adjustment_total_percent"
                            class="text-xs font-medium text-ink-muted"
                            data-testid="history-total"
                        >
                            {{ t('portfolio.valuation_breakdown.total') }}
                            <span class="numeral" dir="ltr">{{ signedPercent(row.adjustment_total_percent) }}</span>
                        </p>
                    </div>
                </li>
            </ul>
        </AppCard>
        <!-- ============================ media ============================ -->
        <AppCard class="mt-5">
            <div class="flex flex-wrap items-center justify-between gap-3">
                <h2 class="font-display text-lg font-semibold text-ink">{{ t('portfolio.media.title') }}</h2>
                <div class="flex items-center gap-2">
                    <input
                        ref="mediaInput"
                        type="file"
                        class="sr-only"
                        accept="image/jpeg,image/png,image/webp,.pdf,.docx,.xlsx"
                        @change="uploadMedia"
                    >
                    <AppButton size="sm" :disabled="mediaBusy" @click="mediaInput?.click()">
                        {{ mediaBusy ? '…' : t('portfolio.media.add') }}
                    </AppButton>
                </div>
            </div>

            <p class="mt-1 text-xs text-ink-faint">{{ t('portfolio.media.hint') }}</p>

            <AppAlert v-if="page.props.errors.file" variant="danger" class="mt-3">
                {{ page.props.errors.file }}
            </AppAlert>

            <p v-if="media.length === 0" class="mt-4 text-sm text-ink-faint">
                {{ t('portfolio.media.empty') }}
            </p>

            <ul v-else class="mt-4 divide-y divide-line">
                <li
                    v-for="item in media"
                    :key="item.id"
                    data-testid="media-item"
                    class="flex items-center gap-3 py-3"
                >
                    <span
                        class="flex h-9 w-9 shrink-0 items-center justify-center rounded-card border border-line
                               bg-surface-sunken text-xs font-semibold uppercase text-ink-muted"
                        aria-hidden="true"
                    >{{ item.kind === 'image' ? t('portfolio.media.image_badge') : t('portfolio.media.document_badge') }}</span>

                    <span class="min-w-0 flex-1">
                        <a
                            :href="mediaHref(item.id)"
                            class="block truncate text-sm font-medium text-ink underline-offset-2 hover:underline
                                   focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-accent"
                            :download="item.kind === 'document' ? item.name : undefined"
                        >{{ item.name }}</a>
                        <span class="numeral block text-xs text-ink-faint" dir="ltr">
                            {{ formatSize(item.size_bytes) }}<template v-if="item.created_at"> · {{ item.created_at }}</template>
                        </span>
                    </span>

                    <AppButton variant="ghost" size="sm" data-testid="delete-media" @click="deleteMedia(item.id, item.name)">
                        {{ t('app.actions.delete') }}
                    </AppButton>
                </li>
            </ul>
        </AppCard>
    </main>
    </PublicLayout>
</template>
