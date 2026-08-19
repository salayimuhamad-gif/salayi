<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import { Link, usePage } from '@inertiajs/vue3';
import { useLocale } from '@/Composables/useLocale';
import { t } from '@/lib/i18n';
import InstallPrompt from '@/Components/InstallPrompt.vue';
import MobileBottomNav from '@/Components/Public/MobileBottomNav.vue';
import PublicSidebar from '@/Components/Public/PublicSidebar.vue';
import PublicTopbar from '@/Components/Public/PublicTopbar.vue';
import PublicMobileNav from '@/Components/Public/PublicMobileNav.vue';
import type { PublicNavItem } from '@/Components/Public/navigation';
import type { IconName } from '@/Components/Icons/icons';
import type { SharedPageProps } from '@/Types/inertia';

/*
 * Chrome variants (homepage re-architecture §5/§11). Additive and
 * backward-compatible: every existing page renders 'default' — rail, boxed
 * main — untouched. 'home' is the homepage's shell: NO left rail (the
 * horizontal topbar nav is the primary desktop navigation there), and the
 * main region carries no padding or max-width so the page composes its own
 * full-bleed and contained sections. The <lg experience is identical in both
 * chromes: hamburger + drawer + bottom tab bar, a contract the acceptance
 * suite pins on '/'.
 */
const props = withDefaults(defineProps<{ chrome?: 'default' | 'home' }>(), { chrome: 'default' });

const page = usePage<SharedPageProps>();
const { localized } = useLocale();

const siteName = computed(() => page.props.branding['branding.site_name'] ?? page.props.app.name);

/*
 * Navigation is filtered by feature flag, not hidden by CSS. A link to a
 * module that is switched off must not exist in the DOM at all — spec
 * Appendix D, an OFF flag means the surface is absent, not merely invisible.
 */
/*
 * The seven top-level items File one §4 specifies, in its order. Filtered by
 * feature flag rather than hidden with CSS: an OFF flag means the link is
 * absent from the DOM, not merely invisible — and since Phase 2 the route
 * behind it returns 404, so a rendered link would be a promise the server
 * breaks.
 */
/*
 * `areas` and `news` carried `flag: null`, which meant "always render". No
 * public route exists for either — `/areas` and `/news` are admin-only paths —
 * so both links 404'd on every page of the site, in all three languages, and
 * the comment directly above them describing an absent route as the reason to
 * omit a link was contradicted two lines later.
 *
 * They are now flagged like every other unbuilt surface. `geography.areas` and
 * `content.news` default to OFF in config/features.php, so the links stay out
 * of the DOM until the public Area and News modules actually exist; when they
 * ship, the flag is the switch and this list needs no edit.
 *
 * hrefs are passed through `localized()` because under `prefix_except_default`
 * a bare `/market` is the Sorani URL: an Arabic visitor clicking it was thrown
 * back to Sorani mid-session.
 */
/*
 * The redesign added an icon and an active flag per item and changed NOTHING
 * about which items exist: same seven keys, same order, same flags, same
 * localisation. `/companies` and `/places` have real public routes and are
 * still deliberately absent, because adding a destination to the primary
 * navigation is a product decision and this task is a visual one.
 */
const definition: Array<{ key: string; href: string; flag: string | null; icon: IconName }> = [
    { key: 'market', href: '/market', flag: 'market.intelligence', icon: 'market' },
    { key: 'map', href: '/map', flag: 'map.explorer', icon: 'map' },
    { key: 'invest', href: '/invest', flag: 'map.investment', icon: 'invest' },
    { key: 'projects', href: '/projects', flag: null, icon: 'projects' },
    { key: 'areas', href: '/areas', flag: 'geography.areas', icon: 'areas' },
    { key: 'advisor', href: '/advisor', flag: 'advisor.residential', icon: 'advisor' },
    { key: 'offers', href: '/offers', flag: 'marketplace.offers', icon: 'offers' },
    { key: 'news', href: '/news', flag: 'content.news', icon: 'news' },
];

/*
 * Active matching is done on the PATH only. `page.url` carries the query
 * string, so a comparison against the raw value marks /projects?page=2 as a
 * different surface from /projects and leaves the whole rail unmarked.
 *
 * A prefix match rather than equality, so a project detail page keeps its
 * section lit — with a separator, or /map would also claim /map-report.
 */
const currentPath = computed(() => page.url.split('?')[0].split('#')[0]);

function isActive(href: string): boolean {
    const path = currentPath.value;

    return path === href || path.startsWith(`${href}/`);
}

const navigation = computed<PublicNavItem[]>(() =>
    definition
        .filter((item) => item.flag === null || page.props.features[item.flag] === true)
        .map((item) => {
            const href = localized(item.href);

            return {
                key: item.key,
                href,
                icon: item.icon,
                active: isActive(href),
                // §6: the advisor is the product's centre of gravity, and the
                // navigation is the first place that has to say so.
                emphasised: item.key === 'advisor',
            };
        }),
);

/*
 * The top bar's page context. Taken from the active navigation entry, so it is
 * always a real translated route label; a page with no match — the homepage, an
 * unsubscribe link — shows nothing rather than an invented breadcrumb.
 */
const pageTitle = computed<string | null>(() => {
    const active = navigation.value.find((item) => item.active);

    return active === undefined ? null : t(`nav.public.${active.key}`);
});

const mobileOpen = ref(false);

/*
 * Inertia keeps this component mounted across a client-side visit, so a drawer
 * opened on one page would still be open on the next if the URL change did not
 * close it. Each link's own @click covers the ordinary case; this covers back,
 * forward, and any programmatic redirect.
 */
watch(currentPath, () => {
    mobileOpen.value = false;
});
</script>

<template>
    <div class="mh-luxury-public flex min-h-full flex-col">
        <!-- §13: a keyboard user should not have to walk the whole rail to
             reach the page. The key already existed and was unused. -->
        <a
            href="#public-main"
            class="sr-only focus:not-sr-only focus:absolute focus:z-50 focus:m-3 focus:rounded-card
                   focus:bg-accent focus:px-4 focus:py-2 focus:text-sm focus:font-medium
                   focus:text-ink"
        >
            {{ t('nav.skip_to_content') }}
        </a>

        <PublicTopbar
            :site-name="siteName"
            :home-href="localized('/')"
            :page-title="pageTitle"
            :mobile-open="mobileOpen"
            :nav-items="props.chrome === 'home' ? navigation : null"
            @toggle="mobileOpen = !mobileOpen"
        />

        <div class="flex flex-1 flex-col lg:flex-row">
            <!--
                The rail sticks below the 4rem top bar and scrolls on its own, so
                a long page never strands the navigation above the fold. Hidden
                rather than collapsed under lg: a 4rem icon-only rail forces
                either a tooltip or an unlabelled control, and §13 rules out the
                second. Absent entirely in the home chrome — the homepage's
                desktop navigation is the horizontal topbar nav, and a permanent
                sidebar is exactly the structure the re-architecture removes.
            -->
            <aside
                v-if="props.chrome === 'default'"
                class="sticky top-16 hidden h-[calc(100dvh-4rem)] w-64 shrink-0
                       overflow-y-auto border-e border-line bg-surface-raised lg:block"
            >
                <PublicSidebar :items="navigation" />
            </aside>

            <main
                id="public-main"
                class="min-w-0 flex-1"
                :class="props.chrome === 'home' ? 'pb-24' : 'px-4 py-8 pb-24 lg:px-8 lg:py-10'"
            >
                <div :class="props.chrome === 'home' ? undefined : 'mx-auto w-full max-w-6xl'">
                    <slot />
                </div>
            </main>
        </div>

        <PublicMobileNav :open="mobileOpen" :items="navigation" @close="mobileOpen = false" />
        <MobileBottomNav />

        <!-- Lighter footer (re-architecture §12): a quiet sunken band instead
             of the charcoal composition, and NO locale switcher — the language
             control exists exactly once, in the header. -->
        <footer class="border-t border-line bg-surface-sunken pb-14 lg:pb-0">
            <div class="mx-auto w-full max-w-screen-2xl px-4 py-10 lg:px-8">
                <div class="flex flex-col gap-8 md:flex-row md:items-start md:justify-between">
                    <div class="max-w-sm">
                        <p class="font-display text-lg font-bold text-ink">{{ siteName }}</p>
                        <p class="mt-2 text-sm text-ink-muted">{{ t('home.hero_sub') }}</p>
                    </div>

                    <nav v-if="navigation.length > 0" :aria-label="t('nav.primary_navigation')">
                        <ul class="grid grid-cols-2 gap-x-10 gap-y-2 sm:grid-cols-3">
                            <li v-for="item in navigation" :key="item.key">
                                <Link
                                    :href="item.href"
                                    class="inline-flex min-h-11 items-center text-sm text-ink-muted
                                           transition-colors duration-200 ease-calm hover:text-ink"
                                >
                                    {{ t(`nav.public.${item.key}`) }}
                                </Link>
                            </li>
                        </ul>
                    </nav>
                </div>

                <div class="mt-8 flex flex-col gap-3 border-t border-line pt-5 text-xs text-ink-faint
                            md:flex-row md:items-center md:justify-between">
                    <!-- Data honesty, stated where the page closes: distances
                         are straight-line; market figures carry their own
                         methodology qualifiers. Existing strings, verbatim. -->
                    <p class="max-w-xl">{{ t('map.distance.straight_line_notice') }}</p>
                    <p class="numeral shrink-0">{{ siteName }} · v{{ page.props.app.version }}</p>
                </div>
            </div>
        </footer>

        <!-- §12.4: rate-limited, never on a first visit. -->
        <InstallPrompt v-if="page.props.features['pwa'] === true" />
    </div>
</template>
