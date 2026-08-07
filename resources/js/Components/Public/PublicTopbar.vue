<script setup lang="ts">
import { Link, usePage } from '@inertiajs/vue3';
import AppIcon from '@/Components/Icons/AppIcon.vue';
import LocaleSwitcher from '@/Components/Public/LocaleSwitcher.vue';
import { t } from '@/lib/i18n';
import type { SharedPageProps } from '@/Types/inertia';

/*
 * The top bar (§7.1).
 *
 * WHAT IS DELIBERATELY ABSENT.
 *
 *   No search field. §7.1 permits one only against a confirmed search contract
 *   and route, and there is none: nothing under routes/ or the module route
 *   files answers a public query. A box that swallows what somebody types is
 *   worse than no box, because it teaches them the platform does not work.
 *
 *   No notifications bell. `notifications.unread` IS a real shared prop, so the
 *   count would be honest — but every public GET route was enumerated and there
 *   is no notification centre to open. The badge would be a number with nowhere
 *   to go, which §3.4 names as a prohibited dead control. It is recorded as a
 *   future backend requirement instead.
 *
 * The signed-in name is plain text for the same reason: the portfolio is the
 * only account surface with a public route, and it already has its own call to
 * action on the homepage.
 */
defineProps<{
    siteName: string;
    homeHref: string;
    /** Already translated by the layout, or null on a page with no nav match. */
    pageTitle: string | null;
    mobileOpen: boolean;
}>();

defineEmits<{ toggle: [] }>();

const page = usePage<SharedPageProps>();
</script>

<template>
    <header class="sticky top-0 z-40 border-b border-line bg-surface-raised">
        <div class="flex h-16 items-center gap-3 px-4 lg:px-6">
            <!-- §7.1 mobile: a labelled control, not an icon a reader has to
                 guess at. `nav.toggle_navigation` already existed; the previous
                 shell mislabelled this button "filter". -->
            <button
                type="button"
                class="-ms-2 flex min-h-11 min-w-11 items-center justify-center rounded-card text-ink-muted
                       transition-colors hover:bg-surface-sunken hover:text-ink
                       focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-accent lg:hidden"
                :aria-expanded="mobileOpen"
                aria-controls="public-mobile-nav"
                :aria-label="t('nav.toggle_navigation')"
                @click="$emit('toggle')"
            >
                <AppIcon :name="mobileOpen ? 'close' : 'menu'" class="h-5 w-5" />
            </button>

            <Link
                :href="homeHref"
                class="flex min-h-11 min-w-0 items-center gap-2.5 rounded-card
                       focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-accent"
            >
                <span
                    class="hidden h-8 w-8 shrink-0 items-center justify-center rounded-card border border-line
                           mh-lux-field sm:flex"
                    aria-hidden="true"
                >
                    <AppIcon name="projects" class="h-4 w-4 mh-lux-gold" />
                </span>
                <span class="truncate font-display text-base font-bold text-ink">{{ siteName }}</span>
            </Link>

            <!-- Page context. Derived from the active navigation entry, so it is
                 a real translated route label and never a string this component
                 invented. -->
            <template v-if="pageTitle !== null">
                <span class="hidden text-ink-faint md:inline" aria-hidden="true">/</span>
                <span class="hidden truncate text-sm text-ink-muted md:inline">{{ pageTitle }}</span>
            </template>

            <div class="ms-auto flex items-center gap-2">
                <span
                    v-if="page.props.auth.user !== null"
                    class="hidden items-center gap-2 rounded-card border border-line px-2.5 py-1.5 text-xs text-ink-muted sm:flex"
                >
                    <AppIcon name="user" class="h-3.5 w-3.5" />
                    <span class="max-w-[10rem] truncate">{{ page.props.auth.user.name }}</span>
                </span>

                <LocaleSwitcher />
            </div>
        </div>
    </header>
</template>
