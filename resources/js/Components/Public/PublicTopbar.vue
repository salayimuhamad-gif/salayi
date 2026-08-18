<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';
import { Link, usePage } from '@inertiajs/vue3';
import AppIcon from '@/Components/Icons/AppIcon.vue';
import LocaleSwitcher from '@/Components/Public/LocaleSwitcher.vue';
import { t } from '@/lib/i18n';
import type { PublicNavItem } from '@/Components/Public/navigation';
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
const props = defineProps<{
    siteName: string;
    homeHref: string;
    /** Already translated by the layout, or null on a page with no nav match. */
    pageTitle: string | null;
    mobileOpen: boolean;
    /**
     * Horizontal desktop navigation (homepage re-architecture §5): when the
     * layout passes the resolved nav items — same flag-gated, localized
     * entries the rail and drawer consume — this bar renders them inline and
     * becomes the page's PRIMARY desktop navigation. Additive and optional:
     * absent (every non-home page today) the header renders exactly as
     * before. The brand link stays the FIRST anchor in the header either way
     * (the locales suite pins that), and the <lg hamburger/drawer contract
     * is untouched — the horizontal nav simply does not exist below lg.
     */
    navItems?: PublicNavItem[] | null;
}>();

defineEmits<{ toggle: [] }>();

const page = usePage<SharedPageProps>();

const hasNav = computed(() => (props.navItems?.length ?? 0) > 0);

/*
 * Scroll elevation (redesign §13.1, adapted): the raised shadow appears once
 * the page has moved. The 64->56px HEIGHT compression the spec sketches is
 * deliberately not implemented — a sticky header changing height shifts the
 * whole page by 8px (a real CLS hit §10.4 forbids) and would break the
 * desktop rail's top-16/100dvh-4rem geometry. Elevation alone carries the
 * scrolled state. Tiny passive listener, progressive enhancement.
 */
const scrolled = ref(false);

function onScroll(): void {
    scrolled.value = window.scrollY > 8;
}

onMounted(() => {
    window.addEventListener('scroll', onScroll, { passive: true });
    onScroll();
});

onBeforeUnmount(() => {
    window.removeEventListener('scroll', onScroll);
});
</script>

<template>
    <header
        class="sticky top-0 z-40 border-b border-line bg-surface-raised/95 backdrop-blur
               transition-shadow duration-200 ease-calm"
        :class="scrolled ? 'shadow-raised' : 'shadow-hairline'"
    >
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

            <!-- Horizontal primary navigation (home chrome only). Flag-gated
                 items, already localized by the layout; flex-1 + min-w-0 keeps
                 a crowded lg viewport scrollable instead of broken. -->
            <nav
                v-if="hasNav"
                :aria-label="t('nav.primary_navigation')"
                class="ms-4 hidden min-w-0 flex-1 items-center gap-0.5 overflow-x-auto lg:flex xl:gap-1"
            >
                <Link
                    v-for="item in navItems"
                    :key="item.key"
                    :href="item.href"
                    :aria-current="item.active ? 'page' : undefined"
                    class="relative flex min-h-11 shrink-0 items-center gap-1.5 rounded-card px-3 text-sm
                           font-medium transition-colors duration-200 ease-calm
                           focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-accent"
                    :class="item.active
                        ? 'text-ink'
                        : item.emphasised
                            ? 'text-ai-ink hover:bg-ai-soft/60'
                            : 'text-ink-muted hover:bg-surface-sunken hover:text-ink'"
                >
                    <AppIcon v-if="item.emphasised" name="spark" class="h-4 w-4" aria-hidden="true" />
                    {{ t(`nav.public.${item.key}`) }}
                    <span
                        v-if="item.active"
                        aria-hidden="true"
                        class="absolute inset-x-3 bottom-0.5 h-0.5 rounded-pill bg-accent"
                    ></span>
                </Link>
            </nav>

            <!-- Page context. Derived from the active navigation entry, so it is
                 a real translated route label and never a string this component
                 invented. Suppressed under the horizontal nav, which already
                 marks the current surface itself. -->
            <template v-if="pageTitle !== null && !hasNav">
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
