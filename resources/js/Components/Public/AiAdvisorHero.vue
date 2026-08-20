<script setup lang="ts">
import { computed } from 'vue';
import { t } from '@/lib/i18n';

/*
 * The homepage hero, recomposed to the approved reference (Wave 2B §5A):
 * centred editorial typography floating directly over the night atmosphere —
 * no boxed panel, no decorative orb. Eyebrow with its hairline, one large
 * display heading whose final word carries the amber-gradient accent, and
 * the refined muted subtitle. The search entry and quick actions live in
 * Home.vue beside it; this component keeps the two things that are its
 * contract:
 *
 *   - the page's ONLY h1;
 *   - the honest advisor state: when the advisor flag is off, the
 *     role="status" notice renders here (and the page renders no advisor
 *     link anywhere), exactly as the acceptance suite expects.
 *
 * The gradient accent is paint only, applied to whole words split on
 * spaces — Arabic-script joining is never broken because no split happens
 * inside a word, and one-word titles simply take no accent.
 */
const props = defineProps<{
    enabled: boolean;
}>();

const title = computed(() => t('home.hero_title'));

const titleParts = computed<{ lead: string; accent: string | null }>(() => {
    const whole = title.value.trim();
    const cut = whole.lastIndexOf(' ');

    if (cut <= 0) {
        return { lead: whole, accent: null };
    }

    return { lead: whole.slice(0, cut + 1), accent: whole.slice(cut + 1) };
});

const enabled = computed(() => props.enabled);
</script>

<template>
    <section class="pt-14 text-center sm:pt-16 lg:pt-20">
        <p class="mh-eyebrow flex items-center justify-center">
            <span class="inline-block h-px w-6 bg-accent me-3" aria-hidden="true"></span>
            {{ t('home.eyebrow') }}
        </p>

        <h1
            class="mh-hero-title mx-auto mt-4 max-w-3xl font-display text-3xl font-medium leading-[1.14]
                   text-ink sm:text-4xl lg:text-[3.375rem]"
        >
            {{ titleParts.lead }}<span v-if="titleParts.accent" class="mh-hero-accent">{{ titleParts.accent }}</span>
        </h1>

        <p class="mx-auto mt-4 max-w-2xl text-base font-light text-ink-muted">
            {{ t('home.hero_sub') }}
        </p>

        <p v-if="!enabled" class="mh-ai-notice mx-auto mt-6 max-w-md text-start" role="status">
            <span aria-hidden="true">!</span>
            <span>{{ t('home.advisor_disabled') }}</span>
        </p>
    </section>
</template>
