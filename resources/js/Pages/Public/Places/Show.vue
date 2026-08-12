<script setup lang="ts">
import ErbilMapPreview from '@/Components/Public/ErbilMapPreview.vue';
import { Head, Link } from '@inertiajs/vue3';
import PublicLayout from '@/Layouts/PublicLayout.vue';
import AppEmptyState from '@/Components/ui/AppEmptyState.vue';
import { t } from '@/lib/i18n';
import { useLocale } from '@/Composables/useLocale';

/*
 * A single public place profile (spec 10.3, 12.2).
 *
 * There is no phone number on this page and there is no prop carrying one.
 * `Place::$hidden` excludes `phone_encrypted` and the controller never calls
 * `phone()` — spec 32.2: a surveyed place phone in Erbil is frequently
 * somebody's personal mobile, given for a survey and not for publication.
 *
 * Provenance is rendered beside the data rather than in a footnote, for the
 * same reason a market index carries its sample size: an unverified record
 * and a verified one must not read identically.
 */
const { localized } = useLocale();

interface Nearby {
    slug: string;
    name: string;
    category: string | null;
    area: string | null;
    operational_status: string | null;
}

defineProps<{
    place: {
        slug: string;
        name: string;
        name_is_fallback: boolean;
        category: string | null;
        category_key: string | null;
        subcategory: string | null;
        address: string | null;
        website: string | null;
        opening_hours: Record<string, string> | null;
        accessibility_notes: string | null;
        operational_status: string | null;
        coordinates: { latitude: number; longitude: number } | null;
        source: string | null;
        verified_at: string | null;
        tags: string[];
    };
    area: { slug: string; name: string } | null;
    nearby: Nearby[];
    seo: { title: string; canonical: string; alternates: Record<string, string> };
}>();
</script>

<template>
    <Head>
        <title>{{ seo.title }}</title>
        <link rel="canonical" :href="seo.canonical">
        <link
            v-for="(href, hreflang) in seo.alternates"
            :key="hreflang"
            rel="alternate"
            :hreflang="hreflang"
            :href="href"
        >
    </Head>

    <PublicLayout>
        <nav class="mb-3 flex flex-wrap items-center gap-1.5 text-sm text-ink-muted">
            <Link
                :href="localized('/places')"
                class="hover:text-brand focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-accent"
            >
                {{ t('geography.public.places_title') }}
            </Link>
        </nav>

        <header>
            <p v-if="place.category" class="mh-label">{{ place.category }}</p>
            <h1 class="mt-1 font-display text-2xl font-bold text-ink">{{ place.name }}</h1>

            <p v-if="place.name_is_fallback" class="mt-1 text-xs text-ink-faint">
                {{ t('geography.public.name_fallback_notice') }}
            </p>

            <!--
                A closed or under-construction place is stated prominently.
                Rendering it identically to an operating one sends somebody
                across Erbil to a locked door.
            -->
            <p
                v-if="place.operational_status && place.operational_status !== 'operating'"
                class="mt-3 rounded-card bg-caution/10 px-3 py-2 text-sm text-caution"
            >
                {{ t(`geography.operational.${place.operational_status}`) }}
            </p>

            <!-- Linked only when the area and its whole ancestry are published. -->
            <p v-if="area" class="mt-3 text-sm text-ink-muted">
                <Link
                    :href="localized(`/areas/${area.slug}`)"
                    class="hover:text-brand focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-accent"
                >
                    {{ t('geography.public.in_area', { area: area.name }) }}
                </Link>
            </p>
            <p v-else class="mt-3 text-xs text-ink-faint">{{ t('geography.public.area_withheld') }}</p>
        </header>

        <!-- The place on the map, from its EXISTING coordinates only
             (redesign §12.1); silent degradation on provider failure. -->
        <ErbilMapPreview
            v-if="place.coordinates"
            :lat="place.coordinates.latitude"
            :lng="place.coordinates.longitude"
            :label="place.name"
            :zoom="15"
            class="mt-6"
        />

        <!-- --------------------------------------------------------- detail -->
        <dl class="mt-8 grid gap-4 sm:grid-cols-2">
            <div>
                <dt class="mh-label">{{ t('geography.public.place_address') }}</dt>
                <dd class="mt-1 text-sm text-ink">
                    {{ place.address ?? t('geography.public.no_address') }}
                </dd>
            </div>

            <div v-if="place.website">
                <dt class="mh-label">{{ t('geography.public.place_website') }}</dt>
                <dd class="mt-1 text-sm">
                    <!-- rel="noopener" and nofollow: an imported URL is not an
                         endorsement and must not pass authority. -->
                    <a
                        :href="place.website"
                        target="_blank"
                        rel="noopener noreferrer nofollow"
                        class="text-brand underline focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-accent"
                        dir="ltr"
                    >{{ place.website }}</a>
                </dd>
            </div>

            <div v-if="place.accessibility_notes">
                <dt class="mh-label">{{ t('geography.public.accessibility') }}</dt>
                <dd class="mt-1 text-sm text-ink">{{ place.accessibility_notes }}</dd>
            </div>

            <div>
                <dt class="mh-label">{{ t('geography.public.opening_hours') }}</dt>
                <dd class="mt-1 text-sm text-ink">
                    <ul v-if="place.opening_hours">
                        <li v-for="(hours, day) in place.opening_hours" :key="day" class="flex gap-3">
                            <span class="text-ink-muted">{{ day }}</span>
                            <span class="numeral" dir="ltr">{{ hours }}</span>
                        </li>
                    </ul>
                    <span v-else class="text-ink-faint">{{ t('geography.public.no_opening_hours') }}</span>
                </dd>
            </div>
        </dl>

        <!-- ----------------------------------------------------- provenance -->
        <p class="mt-6 text-xs text-ink-faint">
            <span v-if="place.source">{{ t('geography.public.place_source') }}: {{ place.source }}</span>
            <span v-if="place.verified_at" class="ms-3">
                {{ t('geography.public.verified_on', { date: place.verified_at }) }}
            </span>
            <span v-else class="ms-3">{{ t('geography.public.not_verified') }}</span>
        </p>

        <!-- --------------------------------------------------------- nearby -->
        <section class="mt-10">
            <h2 class="mb-3 font-display text-lg font-semibold text-ink">
                {{ t('geography.public.nearby_in_area') }}
            </h2>

            <AppEmptyState v-if="nearby.length === 0" :title="t('geography.public.no_nearby')" />

            <ul v-else class="grid gap-2 sm:grid-cols-2 lg:grid-cols-3">
                <li v-for="other in nearby" :key="other.slug">
                    <Link
                        :href="localized(`/places/${other.slug}`)"
                        class="block rounded-card border border-line px-3 py-2 text-sm transition-colors
                               hover:bg-surface-sunken focus-visible:outline-none focus-visible:ring-2
                               focus-visible:ring-accent"
                    >
                        <span class="text-ink">{{ other.name }}</span>
                        <span v-if="other.category" class="ms-2 text-xs text-ink-faint">{{ other.category }}</span>
                    </Link>
                </li>
            </ul>
        </section>
    </PublicLayout>
</template>
