<script setup lang="ts">
import AppIcon from '@/Components/Icons/AppIcon.vue';
import AppButton from '@/Components/ui/AppButton.vue';
import AiOrb from '@/Components/Public/AiOrb.vue';
import { t } from '@/lib/i18n';
import contourUrl from '@/Assets/erbil-contour.svg?url';

/*
 * The homepage hero, re-architected COMPACT (homepage re-architecture §5/§6).
 *
 * The previous hero was a full gilded panel — search form, example prompts,
 * avatar column, insight card — and it pushed the map out of the first
 * viewport. The re-architecture inverts that hierarchy: the MAP is the
 * homepage's dominant element, so this hero is now a short strip that says
 * what the platform is and offers exactly ONE call to action, with the AI
 * orb as the identity mark. The search field and example prompts were
 * removed WITH the section's size: their destinations (/projects?q=, the
 * advisor) remain one click away in the primary navigation and the CTA.
 *
 * What §8.1 required still holds structurally:
 *   - AI central: first section, the orb, and the single CTA into the real
 *     advisor route;
 *   - AI does not invent: nothing here renders as an answered message, and
 *     when `enabled` is false the CTA is REPLACED by the unavailable notice
 *     (role="status" — the acceptance suite pins the CTA/notice exclusivity),
 *     never a link into a route the server 404s.
 *
 * Only the h1 on the page lives here. The contour texture stays (identity,
 * CSS background from the inline asset — never <img>).
 */
defineProps<{
    enabled: boolean;
    href: string;
}>();
</script>

<template>
    <section
        class="mh-contour-bg relative"
        :style="{ '--mh-contour-url': `url('${contourUrl}')` }"
    >
        <div class="flex items-center justify-between gap-6 py-8 sm:py-10 lg:gap-10 lg:py-12">
            <div class="min-w-0 max-w-2xl">
                <p class="mh-eyebrow flex items-center">
                    <span class="inline-block h-px w-6 bg-accent me-3" aria-hidden="true"></span>
                    {{ t('home.eyebrow') }}
                </p>

                <h1 class="mt-3 font-display text-2xl font-bold leading-tight text-ink sm:text-3xl lg:text-4xl">
                    {{ t('home.hero_title') }}
                </h1>

                <p class="mt-2 max-w-prose text-sm text-ink-muted sm:text-base">{{ t('home.hero_sub') }}</p>

                <div class="mt-5">
                    <AppButton v-if="enabled" :href="href" variant="ai" size="lg" class="w-full sm:w-auto">
                        {{ t('home.advisor_cta') }}
                        <AppIcon name="arrow-end" mirror class="h-4 w-4" aria-hidden="true" />
                    </AppButton>

                    <p v-else class="mh-ai-notice" role="status">
                        <span aria-hidden="true">!</span>
                        <span>{{ t('home.advisor_disabled') }}</span>
                    </p>
                </div>
            </div>

            <AiOrb class="h-24 w-24 shrink-0 sm:h-32 sm:w-32 lg:h-40 lg:w-40" />
        </div>
    </section>
</template>
