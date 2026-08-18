<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { t, formatNumber } from '@/lib/i18n';
import { useLocale } from '@/Composables/useLocale';

/*
 * Bare public paths are the *default-locale* (Sorani) URLs under
 * `prefix_except_default`. Emitting them from an Arabic or English page threw
 * the visitor back into Sorani, so each one is wrapped by `localized()`.
 */
const { localized } = useLocale();

defineProps<{
    offer: {
        public_id: string;
        title: string;
        offer_type: string;
        property_type: string;
        price: string | null;
        currency: string;
        size_sqm: string | null;
        rooms: number | null;
        area: string | null;
        project: string | null;
        company: string | null;
        company_verified: boolean;
        location_precision: string;
        published_at: string | null;
        images?: Array<{ url: string; alt: string | null }>;
    };
    disclosureLabel?: string | null;
}>();
</script>

<template>
    <Link
        :href="localized(`/offers/${offer.public_id}`)"
        class="mh-card block p-5 transition-shadow hover:shadow-raised
               focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-accent"
    >
        <!-- On a sponsored card the label sits first. Placed below, a reader
             has already judged the listing as an organic result. -->
        <!-- Only approved images reach this component; the query filters
             them server-side rather than the template hiding them. -->
        <img
            v-if="offer.images && offer.images.length > 0"
            :src="offer.images[0].url"
            :alt="offer.images[0].alt ?? ''"
            class="mb-3 aspect-video w-full rounded-card object-cover"
            loading="lazy"
        >

        <p v-if="disclosureLabel" class="mb-2 inline-block rounded-full bg-caution/10 px-2.5 py-0.5 text-xs text-caution">
            {{ disclosureLabel }}
        </p>

        <p v-if="offer.area" class="mh-label">{{ offer.area }}</p>
        <h3 class="mt-1 font-display text-base font-semibold text-ink">{{ offer.title }}</h3>

        <p v-if="offer.price" class="numeral mt-2 font-display text-lg font-bold text-brand" dir="ltr">
            {{ formatNumber(Number(offer.price)) }} {{ offer.currency }}
        </p>

        <div class="mt-3 flex flex-wrap items-center gap-x-3 gap-y-1 text-xs text-ink-muted">
            <span>{{ t(`market.property_types.${offer.property_type}`) }}</span>
            <span v-if="offer.rooms" class="numeral">{{ offer.rooms }} {{ t('marketplace.fields.rooms') }}</span>
            <span v-if="offer.size_sqm" class="numeral" dir="ltr">{{ offer.size_sqm }} m²</span>
        </div>

        <div class="mt-3 flex flex-wrap items-center gap-2">
            <span v-if="offer.company" class="text-xs text-ink-muted">{{ offer.company }}</span>
            <span
                v-if="offer.company_verified"
                class="rounded-full bg-positive/10 px-2 py-0.5 text-xs text-positive"
            >
                {{ t('companies.verification.verified') }}
            </span>
            <!-- An approximate location says so rather than implying a pin. -->
            <span v-if="offer.location_precision !== 'exact'" class="text-xs text-ink-faint">
                {{ t(`marketplace.precision.${offer.location_precision}`) }}
            </span>
        </div>
    </Link>
</template>
