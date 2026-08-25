<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import PublicLayout from '@/Layouts/PublicLayout.vue';
import AppCard from '@/Components/ui/AppCard.vue';
import AppAlert from '@/Components/ui/AppAlert.vue';
import { t } from '@/lib/i18n';
import { useLocale } from '@/Composables/useLocale';

/*
 * Bare public paths are the *default-locale* (Sorani) URLs under
 * `prefix_except_default`. Emitting them from an Arabic or English page threw
 * the visitor back into Sorani, so each one is wrapped by `localized()`.
 */
const { localized } = useLocale();

/*
 * Public company profile (File two §11).
 *
 * No phone number appears anywhere on this page, and that is a decision rather
 * than an omission: numbers are encrypted at rest under spec 32.2, and
 * releasing one is a consented lead event (Phase 10), not a field a scraper can
 * harvest. The page offers a route to contact, not the contact itself.
 */
defineProps<{
    company: {
        slug: string;
        name: string;
        brand_name: string | null;
        description: string | null;
        logo_path: string | null;
        website: string | null;
        specialties: string[];
        languages: string[];
        is_verified: boolean;
        verified_at: string | null;
        license_number: string | null;
        license_authority: string | null;
        telegram_username: string | null;
        social_links: Record<string, string>;
    };
    branches: Array<{
        id: number;
        name: string;
        address: string | null;
        latitude: number | null;
        longitude: number | null;
        hours: Record<string, string>;
        languages: string[];
        has_contact: boolean;
    }>;
    projects: Array<{
        slug: string;
        name: string;
        role: string;
        is_sponsored: boolean;
        disclosure_label: string | null;
    }>;
}>();
</script>

<template>
    <Head :title="company.name" />

    <PublicLayout>
        <article class="mx-auto max-w-3xl space-y-6">
            <header>
                <h1 class="font-display text-2xl font-bold text-ink">{{ company.name }}</h1>
                <p v-if="company.is_verified" class="numeral mt-1 text-sm text-positive">
                    {{ t('companies.public.verified') }}
                    <span v-if="company.verified_at" class="text-ink-faint" dir="ltr">
                        · {{ company.verified_at }}
                    </span>
                </p>
            </header>

            <AppCard v-if="company.description">
                <p class="whitespace-pre-line text-sm leading-relaxed text-ink">{{ company.description }}</p>
            </AppCard>

            <AppCard>
                <dl class="grid gap-3 sm:grid-cols-2">
                    <div v-if="company.license_number">
                        <dt class="mh-label">{{ t('companies.public.license') }}</dt>
                        <dd class="numeral text-sm text-ink" dir="ltr">
                            {{ company.license_number }}
                            <span v-if="company.license_authority" class="text-ink-faint">
                                · {{ company.license_authority }}
                            </span>
                        </dd>
                    </div>

                    <div v-if="company.website">
                        <dt class="mh-label">{{ t('companies.public.website') }}</dt>
                        <dd class="text-sm">
                            <a
                                :href="company.website"
                                rel="noopener nofollow"
                                dir="ltr"
                                class="text-brand underline-offset-2 hover:underline"
                            >{{ company.website }}</a>
                        </dd>
                    </div>

                    <div v-if="company.specialties.length">
                        <dt class="mh-label">{{ t('companies.public.specialties') }}</dt>
                        <dd class="text-sm text-ink">{{ company.specialties.join(' · ') }}</dd>
                    </div>

                    <div v-if="company.languages.length">
                        <dt class="mh-label">{{ t('companies.public.languages') }}</dt>
                        <dd class="text-sm text-ink" dir="ltr">{{ company.languages.join(' · ') }}</dd>
                    </div>
                </dl>
            </AppCard>

            <AppCard v-if="branches.length" :title="t('companies.public.branches')">
                <ul class="divide-y divide-line">
                    <li v-for="branch in branches" :key="branch.id" class="py-3">
                        <p class="text-sm font-medium text-ink">{{ branch.name }}</p>
                        <p v-if="branch.address" class="text-xs text-ink-muted">{{ branch.address }}</p>
                        <p v-if="branch.languages.length" class="mt-1 text-xs text-ink-faint" dir="ltr">
                            {{ branch.languages.join(' · ') }}
                        </p>
                    </li>
                </ul>

                <!-- Spec 32.2: the number is released through a consented
                     request, never printed for collection. -->
                <AppAlert variant="info" class="mt-4">
                    {{ t('companies.public.contact_via') }}
                </AppAlert>
            </AppCard>

            <AppCard v-if="projects.length" :title="t('companies.public.projects')">
                <ul class="divide-y divide-line">
                    <li
                        v-for="project in projects"
                        :key="project.slug"
                        class="flex flex-wrap items-center gap-2 py-2.5"
                    >
                        <Link
                            :href="localized(`/projects/${project.slug}`)"
                            class="min-w-0 flex-1 text-sm text-ink underline-offset-2 hover:underline"
                        >
                            {{ project.name }}
                        </Link>

                        <span class="text-xs text-ink-muted">{{ project.role }}</span>

                        <!-- §12.2: a paid association is labelled wherever it
                             appears, including on the company's own page. -->
                        <span
                            v-if="project.is_sponsored"
                            class="rounded-card bg-caution/10 px-2 py-0.5 text-xs text-caution"
                        >{{ project.disclosure_label ?? t('advertising.disclosure.sponsored') }}</span>
                    </li>
                </ul>
            </AppCard>
        </article>
    </PublicLayout>
</template>
