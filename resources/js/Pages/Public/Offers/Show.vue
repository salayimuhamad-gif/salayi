<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import PublicLayout from '@/Layouts/PublicLayout.vue';
import AppCard from '@/Components/ui/AppCard.vue';
import AppAlert from '@/Components/ui/AppAlert.vue';
import MediaGallery from '@/Components/MediaGallery.vue';
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
        public_id: string; title: string; offer_type: string; property_type: string;
        price: string | null; currency: string; size_sqm: string | null; rooms: number | null;
        area: string | null; project: string | null; project_slug: string | null;
        company: string | null; company_verified: boolean;
        location_precision: string; published_at: string | null;
        is_sponsored: boolean; description: string | null; terms: string | null;
        contact_method: string;
        images?: Array<{ url: string; alt: string | null; credit: string | null; width: number | null; height: number | null }>;
    };
    seo: { title: string; canonical: string; alternates: Array<{ hreflang: string; href: string }> };
}>();
</script>

<template>
    <Head>
        <title>{{ seo.title }}</title>
        <link rel="canonical" :href="seo.canonical">
        <link v-for="a in seo.alternates" :key="a.hreflang" rel="alternate" :hreflang="a.hreflang" :href="a.href">
    </Head>

    <PublicLayout>
        <article class="space-y-6">
            <!-- A sponsored listing says so on its own page too. Disclosing it
                 only in the results list would mean anyone arriving from a
                 shared link never sees it. -->
            <AppAlert v-if="offer.is_sponsored" variant="warning">
                {{ t('marketplace.sponsorship.disclosure_notice') }}
            </AppAlert>

            <header>
                <p v-if="offer.area" class="mh-label">{{ offer.area }}</p>
                <h1 class="mt-1 font-display text-2xl font-bold text-ink">{{ offer.title }}</h1>

                <p v-if="offer.price" class="numeral mt-3 font-display text-3xl font-bold text-brand" dir="ltr">
                    {{ formatNumber(Number(offer.price)) }} {{ offer.currency }}
                </p>
            </header>

            <MediaGallery v-if="offer.images && offer.images.length > 0" :media="offer.images" />

            <AppCard v-if="offer.description" :title="t('projects.about')">
                <p class="whitespace-pre-line leading-relaxed text-ink-muted">{{ offer.description }}</p>
            </AppCard>

            <AppCard :title="t('marketplace.fields.details')">
                <dl class="grid grid-cols-2 gap-4 text-sm sm:grid-cols-3">
                    <div>
                        <dt class="text-ink-muted">{{ t('projects.fields.type') }}</dt>
                        <dd class="font-medium text-ink">{{ t(`market.property_types.${offer.property_type}`) }}</dd>
                    </div>
                    <div v-if="offer.rooms">
                        <dt class="text-ink-muted">{{ t('marketplace.fields.rooms') }}</dt>
                        <dd class="numeral font-medium text-ink">{{ offer.rooms }}</dd>
                    </div>
                    <div v-if="offer.size_sqm">
                        <dt class="text-ink-muted">{{ t('projects.fields.land_area') }}</dt>
                        <dd class="numeral font-medium text-ink" dir="ltr">{{ offer.size_sqm }} m²</dd>
                    </div>
                    <div>
                        <dt class="text-ink-muted">{{ t('marketplace.fields.precision') }}</dt>
                        <dd class="font-medium text-ink">{{ t(`marketplace.precision.${offer.location_precision}`) }}</dd>
                    </div>
                </dl>
            </AppCard>

            <AppCard v-if="offer.company" :title="t('nav.companies')">
                <div class="flex flex-wrap items-center gap-2">
                    <span class="font-medium text-ink">{{ offer.company }}</span>
                    <span
                        v-if="offer.company_verified"
                        class="rounded-full bg-positive/10 px-2.5 py-0.5 text-xs text-positive"
                    >
                        {{ t('companies.verification.verified') }}
                    </span>
                </div>
            </AppCard>

            <p v-if="offer.project && offer.project_slug" class="text-sm">
                <Link :href="localized(`/projects/${offer.project_slug}`)" class="text-brand underline-offset-2 hover:underline">
                    {{ offer.project }}
                </Link>
            </p>

            <AppCard v-if="offer.terms" :title="t('marketplace.public.terms')">
                <p class="whitespace-pre-line text-sm text-ink-muted">{{ offer.terms }}</p>
            </AppCard>
        </article>
    </PublicLayout>
</template>
