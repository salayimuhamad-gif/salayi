<script setup lang="ts">
import { computed } from 'vue';
import { Link } from '@inertiajs/vue3';
import AppIcon from '@/Components/Icons/AppIcon.vue';
import type { IconName } from '@/Components/Icons/icons';
import { t } from '@/lib/i18n';

/*
 * The real `cta` flags, rendered as actions (§8.5).
 *
 * Every entry here is switched on by a feature flag the server evaluated, and
 * an entry that is off is FILTERED OUT rather than dimmed — §8.5 and §3.4 both
 * require it, and the homepage's own controller comment gives the reason:
 * inviting somebody into a switched-off advisor is a broken promise made on the
 * first screen they see.
 *
 * No badges and no counts. There is no prop behind a number here, so a "12 new
 * offers" pill would be invented, which §3.4 lists by name.
 *
 * The map action is a link and a glyph, never a preview. §8.5 permits a map
 * screenshot only against real geographic data on this page, and Home receives
 * none — so an abstract grid stands in, and it cannot be mistaken for pins on
 * Erbil.
 *
 * Every action carries a translated label beside its icon: §10.1 rules out an
 * icon-only control whose meaning a visitor has to infer.
 */
const props = defineProps<{
    cta: { advisor: boolean; lifestyle: boolean; portfolio: boolean; map: boolean; offers: boolean };
    /** Locale-prefixed destinations, resolved by the page that owns the routes. */
    hrefs: { advisor: string; lifestyle: string; portfolio: string; map: string; offers: string };
}>();

interface Action {
    key: string;
    label: string;
    icon: IconName;
    href: string;
}

const actions = computed<Action[]>(() => {
    const all: Array<Action & { enabled: boolean }> = [
        {
            key: 'advisor',
            label: t('home.advisor_cta'),
            icon: 'advisor',
            href: props.hrefs.advisor,
            enabled: props.cta.advisor,
        },
        {
            key: 'lifestyle',
            label: t('home.lifestyle_cta'),
            icon: 'spark',
            href: props.hrefs.lifestyle,
            enabled: props.cta.lifestyle,
        },
        {
            key: 'map',
            label: t('home.map_cta'),
            icon: 'map',
            href: props.hrefs.map,
            enabled: props.cta.map,
        },
        {
            key: 'offers',
            label: t('home.offers_cta'),
            icon: 'offers',
            href: props.hrefs.offers,
            enabled: props.cta.offers,
        },
        {
            key: 'portfolio',
            label: t('home.portfolio_cta'),
            icon: 'portfolio',
            href: props.hrefs.portfolio,
            enabled: props.cta.portfolio,
        },
    ];

    return all.filter((action) => action.enabled).map(({ enabled: _enabled, ...action }) => action);
});
</script>

<template>
    <div v-if="actions.length > 0" class="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
        <Link
            v-for="action in actions"
            :key="action.key"
            :href="action.href"
            class="mh-lux-card mh-lux-card-interactive flex items-center gap-3 p-4
                   focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-accent"
        >
            <span
                class="flex h-10 w-10 shrink-0 items-center justify-center rounded-card border border-line
                       bg-surface-sunken"
                aria-hidden="true"
            >
                <AppIcon :name="action.icon" class="h-5 w-5 mh-lux-gold" />
            </span>

            <span class="min-w-0 flex-1 truncate text-sm text-ink">{{ action.label }}</span>

            <AppIcon name="chevron" mirror class="h-4 w-4 shrink-0 text-ink-faint" />
        </Link>
    </div>
</template>
