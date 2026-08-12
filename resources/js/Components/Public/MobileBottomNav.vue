<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';
import { Link, usePage } from '@inertiajs/vue3';
import AppIcon from '@/Components/Icons/AppIcon.vue';
import { useLocale } from '@/Composables/useLocale';
import { t } from '@/lib/i18n';
import type { IconName } from '@/Components/Icons/icons';
import type { SharedPageProps } from '@/Types/inertia';

/*
 * The persistent five-tab bottom bar (<lg) — redesign §6.1.
 *
 * It COMPLEMENTS the existing drawer rather than replacing it: the drawer
 * (PublicMobileNav) keeps the full flag-gated navigation behind the topbar
 * hamburger — a contract the acceptance suite pins — while this bar carries
 * the five primary destinations one thumb reaches without opening anything.
 * (The locale switcher lives ONLY in the header now — re-architecture §5.)
 *
 * Flag-off tabs are OMITTED, never disabled: the server 404s a disabled
 * surface, so a rendered tab would be a dead promise. Everything derives
 * from the shared `features` prop; nothing is hard-coded on.
 *
 * The Account tab goes to the real account entry (`/account/profile`) for a
 * signed-in visitor and to `/login` otherwise — both existing routes with
 * existing behaviors; account paths are deliberately NOT localized(), which
 * is the convention the layout already documents for /account/portfolio.
 */
const page = usePage<SharedPageProps>();
const { localized } = useLocale();

interface Tab {
    key: string;
    label: string;
    href: string;
    icon: IconName;
}

const tabs = computed<Tab[]>(() => {
    const features = page.props.features;
    const signedIn = page.props.auth.user !== null;

    const out: Tab[] = [
        { key: 'home', label: t('home.nav.home'), href: localized('/'), icon: 'home' },
        { key: 'explore', label: t('nav.public.projects'), href: localized('/projects'), icon: 'compass' },
    ];

    if (features['map.explorer'] === true) {
        out.push({ key: 'map', label: t('nav.public.map'), href: localized('/map'), icon: 'map-pin' });
    }

    if (features['advisor.residential'] === true) {
        out.push({ key: 'ai', label: t('nav.public.advisor'), href: localized('/advisor'), icon: 'spark' });
    }

    out.push({
        key: 'account',
        label: t('home.nav.account'),
        href: signedIn ? '/account/profile' : '/login',
        icon: 'user',
    });

    return out;
});

const currentPath = computed(() => page.url.split('?')[0].split('#')[0]);

function isActive(tab: Tab): boolean {
    const path = currentPath.value;

    if (tab.key === 'home') {
        return path === '/' || /^\/(ar|en)\/?$/.test(path);
    }

    // Match against the unlocalized stem too, so /ar/projects lights Explore.
    const stem = tab.href.replace(/^\/(ar|en)(?=\/|$)/, '');

    return path === tab.href || path.startsWith(`${tab.href}/`) || path === stem || path.startsWith(`${stem}/`);
}

/*
 * The bar yields to the on-screen keyboard: a fixed bar above a keyboard
 * covers form actions and steals thumb space. Focus heuristics, not visual
 * viewport maths — cheap and adequate.
 */
const keyboardOpen = ref(false);

function onFocusIn(event: FocusEvent): void {
    const target = event.target as HTMLElement | null;
    keyboardOpen.value = target !== null && ['INPUT', 'TEXTAREA', 'SELECT'].includes(target.tagName);
}

function onFocusOut(): void {
    keyboardOpen.value = false;
}

onMounted(() => {
    document.addEventListener('focusin', onFocusIn);
    document.addEventListener('focusout', onFocusOut);
});

onBeforeUnmount(() => {
    document.removeEventListener('focusin', onFocusIn);
    document.removeEventListener('focusout', onFocusOut);
});
</script>

<template>
    <nav
        v-show="!keyboardOpen"
        :aria-label="t('nav.primary_navigation')"
        class="fixed inset-x-0 bottom-0 z-40 flex border-t border-line bg-surface-raised/95
               shadow-raised backdrop-blur lg:hidden"
        :style="{ paddingBottom: 'env(safe-area-inset-bottom)' }"
    >
        <Link
            v-for="tab in tabs"
            :key="tab.key"
            :href="tab.href"
            :aria-current="isActive(tab) ? 'page' : undefined"
            class="relative flex min-h-14 flex-1 flex-col items-center justify-center gap-0.5
                   text-[0.6875rem] font-medium text-ink-muted transition-colors duration-200 ease-calm"
            :class="{ 'text-ink': isActive(tab) }"
        >
            <span
                v-if="isActive(tab)"
                aria-hidden="true"
                class="absolute inset-x-6 top-0 h-0.5 rounded-pill bg-accent"
            ></span>
            <AppIcon :name="tab.icon" class="h-5 w-5" aria-hidden="true" />
            {{ tab.label }}
        </Link>
    </nav>
</template>
