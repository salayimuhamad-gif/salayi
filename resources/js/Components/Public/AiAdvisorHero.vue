<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import AppIcon from '@/Components/Icons/AppIcon.vue';
import AiAvatar from '@/Components/Public/AiAvatar.vue';
import { t } from '@/lib/i18n';

/*
 * The homepage's primary section (§8.1).
 *
 * The brief for this block is one sentence in §8.1: **AI is central to the
 * product, but it does not invent information.** Both halves are structural
 * here, not tonal.
 *
 * CENTRAL — it is the first thing on the page, it is the only place gold is
 * used as a fill, and it carries the only illustration on the homepage.
 *
 * DOES NOT INVENT — the example questions are exactly that: examples. They are
 * rendered as links to the real advisor, never as answered messages, never with
 * a result attached, and they do not auto-submit. §8.1 forbids all three, and
 * the reason is that a prewritten exchange on a marketing surface is
 * indistinguishable from a transcript to the person reading it.
 *
 * When `enabled` is false the section stays, the call to action does not. §3.4
 * rules out a dead button, and a disabled-looking control that goes nowhere is
 * the same broken promise with worse affordance. The unavailable message is
 * shown instead, and the example questions become plain text rather than links
 * to a route that would 404 behind its feature middleware.
 */
defineProps<{
    enabled: boolean;
    href: string;
}>();

/*
 * Written out as three literal `t('…')` calls in the template rather than
 * looped over an array. scripts/lang-usage.php only resolves literal keys, so
 * the loop would have hidden a typo here from the one check that catches a key
 * defined in no locale at all — and the failure mode is the raw key string
 * rendered to a visitor, not an error anybody sees in CI.
 */
</script>

<template>
    <section class="mh-lux-panel mh-lux-field mh-lux-gilded overflow-hidden">
        <div class="flex flex-col gap-8 p-6 sm:p-8 lg:flex-row lg:items-center lg:gap-10">
            <div class="min-w-0 flex-1">
                <p class="mh-lux-eyebrow flex items-center gap-2">
                    <AppIcon name="spark" class="h-3.5 w-3.5 mh-lux-gold" />
                    {{ t('advisor.chat.title') }}
                </p>

                <h1 class="mt-3 font-display text-3xl font-bold leading-tight text-ink sm:text-4xl">
                    {{ t('home.hero_title') }}
                </h1>

                <p class="mt-3 max-w-prose text-ink-muted">{{ t('home.hero_sub') }}</p>

                <!-- The honesty statement §8.1 asks for, in the visitor's own
                     language rather than as a footnote. -->
                <p class="mt-2 max-w-prose text-sm text-ink-faint">{{ t('home.advisor_lede') }}</p>

                <div class="mt-6">
                    <Link
                        v-if="enabled"
                        :href="href"
                        class="mh-lux-btn mh-lux-btn-primary focus-visible:outline-none focus-visible:ring-2
                               focus-visible:ring-accent focus-visible:ring-offset-2 focus-visible:ring-offset-surface"
                    >
                        {{ t('home.advisor_cta') }}
                        <AppIcon name="arrow-end" mirror class="h-4 w-4" />
                    </Link>

                    <p
                        v-else
                        class="mh-ai-notice"
                        role="status"
                    >
                        <span aria-hidden="true">!</span>
                        <span>{{ t('home.advisor_disabled') }}</span>
                    </p>
                </div>

                <div class="mt-6">
                    <p class="mh-lux-eyebrow">{{ t('home.examples') }}</p>

                    <ul class="mt-3 flex flex-wrap gap-2">
                        <li>
                            <Link v-if="enabled" :href="href" class="mh-lux-example">
                                {{ t('home.example_budget') }}
                            </Link>
                            <span v-else class="mh-lux-chip">{{ t('home.example_budget') }}</span>
                        </li>
                        <li>
                            <Link v-if="enabled" :href="href" class="mh-lux-example">
                                {{ t('home.example_compare') }}
                            </Link>
                            <span v-else class="mh-lux-chip">{{ t('home.example_compare') }}</span>
                        </li>
                        <li>
                            <Link v-if="enabled" :href="href" class="mh-lux-example">
                                {{ t('home.example_organise') }}
                            </Link>
                            <span v-else class="mh-lux-chip">{{ t('home.example_organise') }}</span>
                        </li>
                    </ul>
                </div>
            </div>

            <div class="flex shrink-0 justify-center lg:justify-end">
                <AiAvatar class="h-40 w-40 sm:h-48 sm:w-48" />
            </div>
        </div>
    </section>
</template>
