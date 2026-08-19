<script setup lang="ts">
import { onBeforeUnmount, onMounted, ref } from 'vue';
import { Head, router } from '@inertiajs/vue3';
import AppCard from '@/Components/ui/AppCard.vue';
import AppAlert from '@/Components/ui/AppAlert.vue';
import { t } from '@/lib/i18n';

/*
 * Telegram sign-in (File one §7).
 *
 * Verification happens out-of-band: the person leaves this page, presses Share
 * Contact in Telegram, and comes back. So the page polls rather than waits on a
 * form submission.
 *
 * The polling is deliberately dumb — it asks "has MY session been
 * authenticated", never "has intent N been redeemed". A client that could name
 * an intent could watch somebody else's sign-in and learn when they were
 * online. The server answers only about the session asking.
 */
const props = defineProps<{
    deep_link: string | null;
    code: string;
    expires_in_seconds: number;
    bot_configured: boolean;
}>();

const waiting = ref(false);
let timer: ReturnType<typeof setInterval> | undefined;

async function poll(): Promise<void> {
    try {
        const response = await fetch('/login/telegram/poll', {
            headers: { Accept: 'application/json' },
        });

        if (!response.ok) return;

        const body = (await response.json()) as { authenticated?: boolean };

        if (body.authenticated === true) {
            clearInterval(timer);
            // A full visit rather than a client-side push: the session was
            // rotated server-side, so the page must be re-fetched with the new
            // session rather than reusing anything from before sign-in.
            router.visit('/');
        }
    } catch {
        // A failed poll is not worth surfacing; the next one is two seconds away.
    }
}

onMounted(() => {
    if (!props.bot_configured) return;

    waiting.value = true;
    // Two seconds: fast enough to feel immediate on return from Telegram,
    // slow enough not to be a cost on Erbil mobile data.
    timer = setInterval(poll, 2000);
});

onBeforeUnmount(() => clearInterval(timer));
</script>

<template>
    <Head :title="t('identity.telegram.title')" />

    <main class="mx-auto flex min-h-screen max-w-md flex-col justify-center px-4 py-10">
        <h1 class="mb-6 text-center font-display text-2xl font-bold text-ink">
            {{ t('identity.telegram.title') }}
        </h1>

        <AppAlert v-if="!bot_configured" variant="warning">
            {{ t('identity.telegram.not_configured') }}
        </AppAlert>

        <AppCard v-else>
            <p class="text-sm leading-relaxed text-ink">
                {{ t('identity.telegram.instruction') }}
            </p>

            <div class="mt-5 text-center">
                <p class="mh-label mb-1">{{ t('identity.telegram.code') }}</p>
                <p class="numeral font-mono text-2xl font-semibold tracking-widest text-ink" dir="ltr">
                    {{ code }}
                </p>
                <p class="mt-1 text-xs text-ink-faint">{{ t('identity.telegram.expires') }}</p>
            </div>

            <a
                v-if="deep_link"
                :href="deep_link"
                rel="noopener"
                class="mt-6 block rounded-card bg-brand px-4 py-2.5 text-center text-sm font-medium text-white
                       transition-opacity hover:opacity-90
                       focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-accent"
            >{{ t('identity.telegram.open') }}</a>

            <p v-if="waiting" class="mt-5 text-center text-sm text-ink-muted" role="status">
                {{ t('identity.telegram.waiting') }}
            </p>

            <!-- Spec 32.2: say what happens to the number, at the moment it is
                 requested. Consent that is not informed is not consent. -->
            <p class="mt-5 border-t border-line pt-4 text-xs leading-relaxed text-ink-faint">
                {{ t('identity.telegram.why_share') }}
            </p>
        </AppCard>
    </main>
</template>
