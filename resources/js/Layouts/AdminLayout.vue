<script setup lang="ts">
import { computed, ref } from 'vue';
import { Link, router, usePage } from '@inertiajs/vue3';
import { useLocale } from '@/Composables/useLocale';
import { useTheme } from '@/Composables/useTheme';
import AppAlert from '@/Components/ui/AppAlert.vue';
import type { SharedPageProps } from '@/Types/inertia';
import ActingCompanySwitcher from '@/Components/ActingCompanySwitcher.vue';

interface NavItem {
    key: string;
    route: string;
    icon: string;
    children: NavItem[];
}

const page = usePage<SharedPageProps & { nav: NavItem[]; navLabels: Record<string, string> }>();
const { current, available, switchTo, isRtl } = useLocale();
const { cycle } = useTheme();

const sidebarOpen = ref(false);
const expanded = ref<Record<string, boolean>>({});

const nav = computed<NavItem[]>(() => page.props.nav ?? []);
const labels = computed<Record<string, string>>(() => page.props.navLabels ?? {});
const label = (key: string): string => labels.value[key] ?? key;

const siteName = computed(() => page.props.branding['branding.site_name'] ?? page.props.app.name);
const user = computed(() => page.props.auth.user);

/*
 * Read from the shared payload, so the badge is correct on every page load
 * without a poll. A polling badge on Erbil mobile data costs a request every
 * few seconds to tell most users nothing has changed.
 */
const unreadCount = computed<number>(() => page.props.notifications?.unread ?? 0);

const isCurrent = (item: NavItem): boolean =>
    typeof window !== 'undefined' && window.location.pathname.endsWith(item.route.replace('admin.', '/'));

function toggle(key: string): void {
    expanded.value[key] = !expanded.value[key];
}

function logout(): void {
    router.post('/logout');
}

/*
 * Ziggy's route() is a global injected by the @routes directive. A global
 * declaration satisfies the compiler in <script>, but a Vue template resolves
 * identifiers against the component instance, so it has to be re-exported here
 * to be callable from the markup.
 */
const route = (name: string): string =>
    typeof window !== 'undefined' && typeof window.route === 'function'
        ? window.route(name)
        : '/' + name.replace(/^admin\.?/, 'admin/').replace(/\./g, '/');
</script>

<template>
    <!-- Scoping context, reachable from every admin screen. -->
    <div class="flex min-h-screen bg-surface text-ink">
        <!-- Skip link: first tab stop, so a keyboard user is not forced
             through the whole navigation tree on every page. -->
        <a
            href="#main"
            class="sr-only focus:not-sr-only focus:absolute focus:z-50 focus:m-3 focus:rounded-card
                   focus:bg-brand focus:px-4 focus:py-2 focus:text-white"
        >
            {{ label('skip_to_content') }}
        </a>

        <!-- Mobile scrim -->
        <div
            v-if="sidebarOpen"
            class="fixed inset-0 z-30 bg-black/40 lg:hidden"
            aria-hidden="true"
            @click="sidebarOpen = false"
        />

        <aside
            class="fixed inset-y-0 z-40 flex w-72 flex-col border-e border-line bg-surface-raised
                   transition-transform duration-300 ease-calm lg:static lg:translate-x-0"
            :class="sidebarOpen ? 'translate-x-0' : (isRtl ? 'translate-x-full lg:translate-x-0' : '-translate-x-full lg:translate-x-0')"
            :aria-label="label('primary_navigation')"
        >
            <div class="flex h-16 shrink-0 items-center gap-3 border-b border-line px-5">
                <span class="h-7 w-1.5 rounded-full bg-accent" aria-hidden="true" />
                <span class="truncate font-display text-base font-bold text-brand">{{ siteName }}</span>
            </div>

            <nav class="flex-1 overflow-y-auto px-3 py-4">
                <ul class="space-y-0.5">
                    <li v-for="item in nav" :key="item.key">
                        <component
                            :is="item.children.length ? 'button' : Link"
                            :href="item.children.length ? undefined : route(item.route)"
                            :type="item.children.length ? 'button' : undefined"
                            :aria-expanded="item.children.length ? !!expanded[item.key] : undefined"
                            :aria-current="!item.children.length && isCurrent(item) ? 'page' : undefined"
                            class="flex w-full items-center gap-3 rounded-card px-3 py-2 text-start text-sm
                                   text-ink-muted transition-colors duration-150
                                   hover:bg-surface-sunken hover:text-ink
                                   focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-accent"
                            @click="item.children.length ? toggle(item.key) : (sidebarOpen = false)"
                        >
                            <span class="h-1.5 w-1.5 shrink-0 rounded-full bg-current opacity-40" aria-hidden="true" />
                            <span class="flex-1 truncate">{{ label(item.key) }}</span>
                            <svg
                                v-if="item.children.length"
                                class="h-4 w-4 shrink-0 transition-transform duration-200"
                                :class="expanded[item.key] ? 'rotate-90' : 'rtl:-scale-x-100'"
                                viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"
                            >
                                <path d="m9 18 6-6-6-6" stroke-linecap="round" stroke-linejoin="round" />
                            </svg>
                        </component>

                        <ul v-if="item.children.length && expanded[item.key]" class="mt-0.5 space-y-0.5 ps-6">
                            <li v-for="child in item.children" :key="child.key">
                                <Link
                                    :href="route(child.route)"
                                    class="block rounded-card px-3 py-1.5 text-sm text-ink-faint
                                           transition-colors duration-150 hover:bg-surface-sunken hover:text-ink
                                           focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-accent"
                                    @click="sidebarOpen = false"
                                >
                                    {{ label(child.key) }}
                                </Link>
                            </li>
                        </ul>
                    </li>
                </ul>
            </nav>

            <div class="shrink-0 border-t border-line p-3">
                <p class="truncate px-2 text-sm font-medium text-ink">{{ user?.name }}</p>
                <button
                    type="button"
                    class="mt-2 w-full rounded-card px-2 py-1.5 text-start text-sm text-ink-muted
                           transition-colors hover:bg-surface-sunken hover:text-negative
                           focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-accent"
                    @click="logout"
                >
                    {{ label('sign_out') }}
                </button>
            </div>
        </aside>

        <div class="flex min-w-0 flex-1 flex-col">
            <header class="sticky top-0 z-20 flex h-16 items-center gap-3 border-b border-line bg-surface-raised/90 px-4 backdrop-blur">
                <button
                    type="button"
                    class="rounded-card p-2 text-ink-muted hover:bg-surface-sunken lg:hidden
                           focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-accent"
                    :aria-label="label('toggle_navigation')"
                    :aria-expanded="sidebarOpen"
                    @click="sidebarOpen = !sidebarOpen"
                >
                    <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                        <path d="M4 6h16M4 12h16M4 18h16" stroke-linecap="round" />
                    </svg>
                </button>

                <!-- A <select> inside an <h1> is invalid HTML and a heading a
                     screen reader announces as containing a form control. The
                     switcher is a sibling control in the header, not part of
                     the page title. -->
                <h1 class="min-w-0 flex-1 truncate font-display text-sm font-semibold text-ink">
                    <slot name="title" />
                </h1>

                <ActingCompanySwitcher />

                <div class="flex items-center gap-1">
                    <!--
                        The bell. A link rather than a dropdown: a panel would
                        need its own fetch, its own loading state and its own
                        pagination, and the centre already is that page. On a
                        phone the full page is the better target anyway.
                    -->
                    <Link
                        href="/admin/notifications"
                        class="relative rounded-card p-2 text-ink-muted transition-colors hover:bg-surface-sunken
                               focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-accent"
                        :aria-label="unreadCount > 0
                            ? label('notifications') + ' — ' + unreadCount
                            : label('notifications')"
                    >
                        <svg
                            class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                            stroke-width="1.8" aria-hidden="true"
                        >
                            <path
                                d="M18 8a6 6 0 1 0-12 0c0 7-3 9-3 9h18s-3-2-3-9"
                                stroke-linecap="round" stroke-linejoin="round"
                            />
                            <path d="M13.7 21a2 2 0 0 1-3.4 0" stroke-linecap="round" stroke-linejoin="round" />
                        </svg>

                        <!--
                            Capped at 9+. The badge sits in a 16px circle, and a
                            three-digit count either overflows it or shrinks the
                            text below legibility — the exact number matters far
                            less than "there are some".
                        -->
                        <span
                            v-if="unreadCount > 0"
                            class="numeral absolute -end-0.5 -top-0.5 flex h-4 min-w-4 items-center
                                   justify-center rounded-full bg-negative px-1 text-[10px]
                                   font-semibold leading-none text-white"
                            aria-hidden="true"
                        >
                            {{ unreadCount > 9 ? '9+' : unreadCount }}
                        </span>
                    </Link>

                    <button
                        v-for="locale in available"
                        :key="locale.code"
                        type="button"
                        class="rounded px-2 py-1 text-xs transition-colors focus-visible:outline-none
                               focus-visible:ring-2 focus-visible:ring-accent"
                        :class="locale.code === current ? 'bg-brand text-white' : 'text-ink-muted hover:bg-surface-sunken'"
                        :aria-current="locale.code === current ? 'true' : undefined"
                        @click="switchTo(locale.code)"
                    >
                        {{ locale.native }}
                    </button>

                    <button
                        type="button"
                        class="rounded-card p-2 text-ink-muted hover:bg-surface-sunken
                               focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-accent"
                        :aria-label="label('toggle_theme')"
                        @click="cycle"
                    >
                        <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true">
                            <path d="M21 12.8A9 9 0 1 1 11.2 3a7 7 0 0 0 9.8 9.8Z" stroke-linecap="round" stroke-linejoin="round" />
                        </svg>
                    </button>
                </div>
            </header>

            <main id="main" class="mx-auto w-full max-w-6xl flex-1 px-4 py-8">
                <AppAlert v-if="page.props.flash.success" variant="success" class="mb-6">
                    {{ page.props.flash.success }}
                </AppAlert>
                <AppAlert v-if="page.props.flash.error" variant="danger" class="mb-6">
                    {{ page.props.flash.error }}
                </AppAlert>

                <slot />
            </main>
        </div>
    </div>
</template>
