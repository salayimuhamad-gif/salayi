<script setup lang="ts">
import { ref } from 'vue';
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import AppCard from '@/Components/ui/AppCard.vue';
import AppButton from '@/Components/ui/AppButton.vue';
import AppAlert from '@/Components/ui/AppAlert.vue';
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
interface HistoryRow {
    midpoint: string | null;
    low: string | null;
    high: string | null;
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
            confidence: string;
            no_valuation_reason: string | null;
            calculated_at: string | null;
        } | null;
    };
    history: HistoryRow[];
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

    <main class="mx-auto max-w-3xl px-4 py-8">
        <Link
            :href="localized('/account/portfolio')"
            class="mb-5 inline-block text-sm text-ink-muted transition-colors hover:text-ink
                   focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-accent"
        >
            {{ t('app.actions.back') }}
        </Link>

        <header class="mb-5 flex flex-wrap items-center justify-between gap-3">
            <h1 class="font-display text-2xl font-bold text-ink">{{ property.label ?? '—' }}</h1>
            <AppButton variant="secondary" size="sm" data-testid="edit-property" :aria-expanded="editing" @click="editing = !editing">
                {{ editing ? t('app.actions.cancel') : t('portfolio.form.edit') }}
            </AppButton>
        </header>

        <!-- ============================ edit ============================= -->
        <AppCard v-if="editing" class="mb-5">
            <PropertyForm
                :projects="projects"
                :areas="areas"
                :property="property"
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
            <p v-if="history.length === 0" class="text-sm text-ink-muted">
                {{ t('portfolio.no_valuation') }}
            </p>

            <ul v-else class="divide-y divide-line">
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
</template>
