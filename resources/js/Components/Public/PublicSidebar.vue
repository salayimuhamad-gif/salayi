<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import AppIcon from '@/Components/Icons/AppIcon.vue';
import { t } from '@/lib/i18n';
import type { PublicNavItem } from '@/Components/Public/navigation';

/*
 * The desktop rail (§7.1).
 *
 * It renders what it is given and resolves nothing. Every decision about which
 * items exist — the feature flags, the locale prefixing, the active match — is
 * made once in PublicLayout, because a sidebar that filtered its own list would
 * be a second place for a switched-off module to leak into the DOM.
 *
 * The active marker is a gold bar on the INLINE start edge (.mh-lux-nav-link in
 * app.css), so it lands on the right in Sorani and Arabic without a
 * direction-specific rule. `aria-current="page"` carries the same fact to a
 * screen reader, because §13 forbids state signalled by colour alone.
 */
defineProps<{ items: PublicNavItem[] }>();
</script>

<template>
    <nav :aria-label="t('nav.primary_navigation')" class="flex flex-col gap-1 p-4">
        <Link
            v-for="item in items"
            :key="item.key"
            :href="item.href"
            class="mh-lux-nav-link"
            :aria-current="item.active ? 'page' : undefined"
        >
            <AppIcon
                :name="item.icon"
                class="h-5 w-5 shrink-0"
                :class="item.emphasised && !item.active ? 'mh-lux-gold' : ''"
            />
            <span class="min-w-0 flex-1 truncate">{{ t(`nav.public.${item.key}`) }}</span>

            <!--
                The advisor carries a mark the other items do not, because §6
                asks for the AI to be the visual centre of the product. It is a
                decorative glyph, not a badge: it counts nothing, so it cannot
                claim anything.
            -->
            <AppIcon
                v-if="item.emphasised"
                name="spark"
                class="h-3.5 w-3.5 shrink-0 mh-lux-gold"
            />
        </Link>
    </nav>
</template>
