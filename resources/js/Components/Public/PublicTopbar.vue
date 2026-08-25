<script setup lang="ts">
import { computed, nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { Link, usePage } from '@inertiajs/vue3';
import AccountMenu from '@/Components/Public/AccountMenu.vue';
import AppIcon from '@/Components/Icons/AppIcon.vue';
import AppMenu from '@/Components/ui/AppMenu.vue';
import LanguageMenu from '@/Components/LanguageMenu.vue';
import { useTheme } from '@/Composables/useTheme';
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
 * Wave 1 replaced the signed-in name badge (inert text) with the account menu,
 * and the segmented three-language row with the compact language menu. Both
 * keep this header's standing rules: the brand link stays the FIRST anchor in
 * the header (the locales suite pins that — the sign-in link and every menu
 * item render after it), and both controls remain present below lg so language
 * and account stay reachable without opening the drawer.
 *
 * The glass refinement adds the theme control beside them: the same two-state
 * appearance choice the admin header already offers, rendered only while the
 * operator's `branding.dark_mode_enabled` setting says visitors may choose.
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

const page = usePage<SharedPageProps>();
const { night, togglePublic } = useTheme();

/*
 * The operator's "let users choose a dark appearance" switch (an existing
 * Branding setting that previously gated nothing). The repository decodes
 * boolean settings to real booleans while older rows carry '1' — the same
 * accept-both rule Admin/Branding.vue documents. Switched off, the control
 * is absent from the DOM and the shell simply keeps its night-first default.
 */
const themeChoiceOffered = computed(() => {
    const value = page.props.branding['branding.dark_mode_enabled'] as string | boolean | null | undefined;

    return value === true || value === '1' || value === 'true';
});

const hasNav = computed(() => (props.navItems?.length ?? 0) > 0);

/** First visible letter of the site name — the reference's brand mark chip. */
const markLetter = computed(() => (props.siteName.trim()[0] ?? 'M').toLocaleUpperCase());

/*
 * Priority navigation (§2 of the glass refinement): the horizontal nav must
 * NEVER scroll or clip. Every item is measured in a hidden mirror row, the
 * bar shows as many leading items as genuinely fit, and the rest move into
 * the "more" disclosure — so a crowded lg viewport, a long Sorani label set
 * or the owner's 120% type scale all degrade into an elegant menu instead of
 * a browser scrollbar.
 *
 * Mechanics: the mirror row duplicates the items as inert spans with the
 * exact link classes (same font, padding and gap ⇒ same widths) inside the
 * overflow-hidden nav, so it can never affect page scroll or the harness's
 * overflow probe. A ResizeObserver on the container and on the mirror row
 * re-runs the fit when the viewport, the fonts or the type scale change.
 * All items fitting ⇒ everything inline and no "more" trigger at all.
 */
const navContainer = ref<HTMLElement | null>(null);
const measureRow = ref<HTMLElement | null>(null);
const measureMore = ref<HTMLElement | null>(null);
const visibleCount = ref(props.navItems?.length ?? 0);

const visibleItems = computed(() => (props.navItems ?? []).slice(0, visibleCount.value));
const overflowItems = computed(() => (props.navItems ?? []).slice(visibleCount.value));
const overflowHoldsActive = computed(() => overflowItems.value.some((item) => item.active));

function recomputeFit(): void {
    const container = navContainer.value;
    const row = measureRow.value;
    const items = props.navItems ?? [];

    if (container === null || row === null || items.length === 0) {
        return;
    }

    const spans = Array.from(row.children) as HTMLElement[];

    if (spans.length !== items.length) {
        return;
    }

    const available = container.clientWidth;
    const gap = Number.parseFloat(getComputedStyle(container).columnGap) || 0;
    const widths = spans.map((span) => span.getBoundingClientRect().width);
    const total = widths.reduce((sum, width, index) => sum + width + (index > 0 ? gap : 0), 0);

    if (total <= available) {
        visibleCount.value = items.length;

        return;
    }

    const moreWidth = (measureMore.value?.getBoundingClientRect().width ?? 44) + gap;
    let used = 0;
    let count = 0;

    for (const [index, width] of widths.entries()) {
        const next = used + width + (index > 0 ? gap : 0);

        if (next + moreWidth > available) {
            break;
        }

        used = next;
        count += 1;
    }

    visibleCount.value = count;
}

let observer: ResizeObserver | null = null;

function armFitObserver(): void {
    if (typeof ResizeObserver === 'undefined' || observer !== null) {
        return;
    }

    observer = new ResizeObserver(() => recomputeFit());

    if (navContainer.value !== null) observer.observe(navContainer.value);
    if (measureRow.value !== null) observer.observe(measureRow.value);
}

onMounted(() => {
    armFitObserver();
    recomputeFit();
});

onBeforeUnmount(() => {
    observer?.disconnect();
    observer = null;
});

watch(
    () => props.navItems,
    async () => {
        await nextTick();
        armFitObserver();
        recomputeFit();
    },
);

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

const navLinkClass = (item: PublicNavItem): string =>
    item.active
        ? 'text-ink'
        : item.emphasised
            ? 'text-ai-ink hover:bg-ai-soft/60'
            : 'text-ink-muted hover:bg-surface-sunken hover:text-ink';

const menuItemClass = `flex min-h-11 w-full items-center gap-2.5 rounded-[0.6rem] px-3 text-sm
    transition-colors duration-200 ease-calm
    focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-accent`;
</script>

<template>
    <header
        class="sticky z-40"
        :class="props.pill
            ? 'top-2.5 px-3 sm:top-4 sm:px-4'
            : 'top-0 border-b border-line bg-surface-raised/90 mh-glass-blur transition-shadow duration-200 ease-calm '
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
                <!-- On phones the pill keeps the amber mark alone: the long
                     site name only ever rendered as an awkward truncation
                     there. sr-only keeps the link's accessible name intact;
                     nothing else about the control changes. -->
                <span
                    class="truncate text-ink"
                    :class="props.pill ? 'mh-wordmark text-[13px] font-medium max-sm:sr-only' : 'font-display text-base font-bold'"
                >{{ siteName }}</span>
            </Link>

            <!-- Horizontal primary navigation (home chrome only). Flag-gated
                 items, already localized by the layout. What does not fit
                 moves into the "more" disclosure via the measured fit above,
                 so the bar can never grow a scrollbar or clip a destination.
                 overflow-x-clip rather than overflow-hidden: clip guarantees
                 zero horizontal overflow without creating a scroll container,
                 and leaves the vertical axis visible so the disclosure panel
                 can open below the bar. -->
            <nav
                v-if="hasNav"
                ref="navContainer"
                :aria-label="t('nav.primary_navigation')"
                class="relative ms-4 hidden min-w-0 flex-1 items-center gap-0.5 overflow-x-clip lg:flex xl:gap-1"
            >
                <Link
                    v-for="item in visibleItems"
                    :key="item.key"
                    :href="item.href"
                    :aria-current="item.active ? 'page' : undefined"
                    class="relative flex min-h-11 shrink-0 items-center gap-1.5 rounded-card px-3 text-sm
                           font-medium transition-colors duration-200 ease-calm
                           focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-accent"
                    :class="navLinkClass(item)"
                >
                    <AppIcon v-if="item.emphasised" name="spark" class="h-4 w-4" aria-hidden="true" />
                    {{ t(`nav.public.${item.key}`) }}
                    <span
                        v-if="item.active"
                        aria-hidden="true"
                        class="absolute inset-x-3 bottom-0.5 h-0.5 rounded-pill bg-accent"
                    />
                </Link>

                <AppMenu
                    v-if="overflowItems.length > 0"
                    :label="t('nav.more')"
                    align="end"
                    :trigger-class="'relative min-h-11 min-w-11 shrink-0 rounded-card px-2.5 '
                        + (overflowHoldsActive ? 'text-ink' : 'text-ink-muted hover:bg-surface-sunken hover:text-ink')"
                    panel-class="min-w-48"
                >
                    <template #trigger>
                        <AppIcon name="more" class="h-5 w-5" />
                        <!-- The section marker follows the current page into
                             the disclosure, so "where am I" never disappears
                             with the collapsed item. -->
                        <span
                            v-if="overflowHoldsActive"
                            aria-hidden="true"
                            class="absolute inset-x-3 bottom-0.5 h-0.5 rounded-pill bg-accent"
                        />
                    </template>

                    <template #default="{ close }">
                        <Link
                            v-for="item in overflowItems"
                            :key="item.key"
                            :href="item.href"
                            :aria-current="item.active ? 'page' : undefined"
                            :class="[menuItemClass, item.active
                                ? 'font-medium text-ink'
                                : item.emphasised
                                    ? 'text-ai-ink hover:bg-ai-soft/60'
                                    : 'text-ink-muted hover:bg-surface-sunken hover:text-ink']"
                            @click="close()"
                        >
                            <AppIcon :name="item.emphasised ? 'spark' : item.icon" class="h-4 w-4 shrink-0" aria-hidden="true" />
                            <span class="min-w-0 flex-1 truncate text-start">{{ t(`nav.public.${item.key}`) }}</span>
                            <AppIcon
                                v-if="item.active"
                                name="check"
                                class="h-4 w-4 shrink-0 text-accent-strong"
                                aria-hidden="true"
                            />
                        </Link>
                    </template>
                </AppMenu>

                <!-- Inert measurement mirror: same classes, same labels, same
                     gaps — layout truth for the fit computation, invisible
                     (never painted, never hit-testable) and overlaying the
                     bar itself so it can extend no scrollable area. -->
                <div
                    ref="measureRow"
                    aria-hidden="true"
                    inert
                    class="invisible absolute start-0 top-0 flex w-max items-center gap-0.5 xl:gap-1"
                >
                    <span
                        v-for="item in props.navItems ?? []"
                        :key="item.key"
                        class="flex min-h-11 shrink-0 items-center gap-1.5 rounded-card px-3 text-sm font-medium"
                    >
                        <AppIcon v-if="item.emphasised" name="spark" class="h-4 w-4" />
                        {{ t(`nav.public.${item.key}`) }}
                    </span>
                </div>
                <span
                    ref="measureMore"
                    aria-hidden="true"
                    inert
                    class="invisible absolute start-0 top-0 flex min-h-11 min-w-11 items-center px-2.5"
                >
                    <AppIcon name="more" class="h-5 w-5" />
                </span>
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
                <!-- Appearance. Two explicit states — night glass and day
                     glass — persisted by the shared theme mechanism; the icon
                     shows the appearance the press switches TO. -->
                <button
                    v-if="themeChoiceOffered"
                    type="button"
                    data-testid="theme-toggle"
                    class="flex min-h-11 min-w-11 items-center justify-center rounded-card text-ink-muted
                           transition-colors duration-200 ease-calm hover:bg-surface-sunken hover:text-ink
                           focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-accent"
                    :aria-label="t('nav.toggle_theme')"
                    :aria-pressed="night"
                    @click="togglePublic"
                >
                    <AppIcon :name="night ? 'sun' : 'moon'" class="h-[1.125rem] w-[1.125rem]" />
                </button>

                <LanguageMenu />
                <AccountMenu />
            </div>
        </div>
    </header>
</template>
