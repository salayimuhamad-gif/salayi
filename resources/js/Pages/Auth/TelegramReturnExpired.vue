<script setup lang="ts">
import { computed } from 'vue';
import { Head } from '@inertiajs/vue3';
import AppCard from '@/Components/ui/AppCard.vue';
import AppAlert from '@/Components/ui/AppAlert.vue';
import { t } from '@/lib/i18n';

const props = withDefaults(defineProps<{ variant?: 'expired' | 'return_to_tab' }>(), {
    variant: 'expired',
});

/*
 * One neutral page, two reasons for arriving: the handoff token is spent or
 * expired, or the person tapped the FIRST Telegram button before confirming
 * anything in their browser. Neither state authenticates anybody and neither
 * reveals whether an account exists — only the wording differs.
 */
const strings = computed(() =>
    props.variant === 'return_to_tab'
        ? {
            title: 'identity.link.return_tab_title',
            body: 'identity.link.return_tab_body',
            hint: 'identity.link.return_tab_hint',
        }
        : {
            title: 'identity.link.return_expired_title',
            body: 'identity.link.return_expired',
            hint: 'identity.link.return_expired_hint',
        });

/*
 * Where a spent, expired, replayed or foreign return handoff lands.
 *
 * One page for every failure, on purpose: telling the person WHICH way the
 * token failed would tell whoever is holding the link something about an
 * account that may not be theirs. It says the link is no longer usable and
 * points at the browser tab that genuinely still holds the session — which,
 * in the normal case where somebody simply pressed the button twice, is
 * exactly the right advice.
 */
</script>

<template>
    <Head :title="t(strings.title)" />

    <div class="mx-auto max-w-lg px-4 py-10">
        <AppCard>
            <h1 class="text-xl font-semibold" data-testid="return-expired-title">
                {{ t(strings.title) }}
            </h1>

            <AppAlert variant="warning" class="mt-4">
                {{ t(strings.body) }}
            </AppAlert>

            <p class="mt-4 text-sm leading-relaxed opacity-90">
                {{ t(strings.hint) }}
            </p>
        </AppCard>
    </div>
</template>
