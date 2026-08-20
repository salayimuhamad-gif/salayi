<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import AppIcon from '@/Components/Icons/AppIcon.vue';
import { t } from '@/lib/i18n';

/*
 * The floating AI dock (Wave 2B §5I, the reference .dock-ai): a fixed glass
 * pill at the inline-end corner with the amber advisor bubble. It is a real
 * Link into the real advisor route — the parent renders it only when the
 * advisor capability is actually on, so a switched-off advisor never leaves
 * a dead floating control. Keyboard access is the link itself; the glow
 * pulse is clamped by the global reduced-motion rule.
 *
 * Below lg it compacts to the bubble alone and sits above the bottom tab
 * bar (3.5rem tall + safe area) so it never covers the fifth tab. z-30
 * keeps it under the drawer, the menus and the bottom sheet (z-50).
 */
defineProps<{ href: string }>();
</script>

<template>
    <Link
        :href="href"
        :aria-label="t('home.advisor_cta')"
        class="mh-dock fixed z-30 flex items-center gap-3 p-2 pe-2 ps-2
               bottom-[calc(4.5rem+env(safe-area-inset-bottom))] end-4
               lg:bottom-7 lg:end-7 lg:ps-4
               focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-accent"
    >
        <span class="hidden text-[13px] font-medium text-ink lg:inline">{{ t('home.advisor_cta') }}</span>
        <span
            class="mh-spark mh-dock-bubble flex h-11 w-11 items-center justify-center rounded-full"
            aria-hidden="true"
        >
            <AppIcon name="spark" class="h-5 w-5" />
        </span>
    </Link>
</template>
