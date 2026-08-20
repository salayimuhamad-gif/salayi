<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';
import { Link } from '@inertiajs/vue3';
import AccountMenu from '@/Components/Public/AccountMenu.vue';
import AppIcon from '@/Components/Icons/AppIcon.vue';
import LanguageMenu from '@/Components/LanguageMenu.vue';
import { t } from '@/lib/i18n';
import type { PublicNavItem } from '@/Components/Public/navigation';

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
 * Wave 1 replaced the signed-in name badge (inert text) with the account menu,
 * and the segmented three-language row with the compact language menu. Both
 * keep this header's standing rules: the brand link stays the FIRST anchor in
 * the header (the locales suite pins that — the sign-in link and every menu
 * item render after it), and both controls remain present below lg so language
 * and account stay reachable without opening the drawer.
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
    /**
     * Floating glass-pill geometry (Wave 2B, the reference navbar): the bar
     * detaches from the viewport edge into a centred, rounded-pill glass
     * chrome. Purely a visual shell — every control, order, label, testid
     * and keyboard behavior inside is byte-identical to the classic bar, and
     * pages that do not pass it keep today's attached header untouched.
     */
    pill?: boolean;
}>();

defineEmits<{ toggle: [] }>();

const hasNav = computed(() => (props.navItems?.length ?? 0) > 0);

/** First visible letter of the site name — the reference's brand mark chip. */
const markLetter = computed(() => (props.siteName.trim()[0] ?? 'M').toLocaleUpperCase());

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
        class="sticky z-40"
        :class="props.pill
            ? 'top-2.5 px-3 sm:top-4 sm:px-4'
            : 'top-0 border-b border-line bg-surface-raised/95 backdrop-blur transition-shadow duration-200 ease-calm '
                + (scrolled ? 'shadow-raised' : 'shadow-hairline')"
    >
        <div
            class="flex items-center"
            :class="props.pill
                ? 'mh-pill-nav mx-auto w-full max-w-[1200px] gap-2 px-3 py-2 transition-shadow duration-200 ease-calm sm:gap-3 sm:px-5 '
                    + (scrolled ? 'shadow-overlay' : '')
                : 'h-16 gap-3 px-4 lg:px-6'"
        >
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
                <!-- Pill chrome carries the reference's amber brand mark;
                     the classic bar keeps its exact Wave 1 emblem. -->
                <span
                    v-if="props.pill"
                    class="mh-brand-mark flex h-7 w-7 shrink-0 items-center justify-center rounded-[9px]
                           text-[13px] font-semibold"
                    aria-hidden="true"
                >{{ markLetter }}</span>
                <span
                    v-else
                    class="hidden h-8 w-8 shrink-0 items-center justify-center rounded-card border border-line
                           mh-lux-field sm:flex"
                    aria-hidden="true"
                >
                    <AppIcon name="projects" class="h-4 w-4 mh-lux-gold" />
                </span>
                <span
                    class="truncate text-ink"
                    :class="props.pill ? 'mh-wordmark text-[13px] font-medium' : 'font-display text-base font-bold'"
                >{{ siteName }}</span>
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

            <div class="ms-auto flex shrink-0 items-center gap-1.5">
                <LanguageMenu />
                <AccountMenu />
            </div>
        </div>
    </header>
</template>
