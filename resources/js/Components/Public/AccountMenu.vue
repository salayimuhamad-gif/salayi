<script setup lang="ts">
import { computed } from 'vue';
import { Link, router, usePage } from '@inertiajs/vue3';
import AppIcon from '@/Components/Icons/AppIcon.vue';
import AppMenu from '@/Components/ui/AppMenu.vue';
import UserAvatar from '@/Components/ui/UserAvatar.vue';
import { useLocale } from '@/Composables/useLocale';
import { t } from '@/lib/i18n';
import type { SharedPageProps } from '@/Types/inertia';

/*
 * The header's account entry point (redesign Wave 1 §3).
 *
 * Signed out it is one compact sign-in action; the header previously offered
 * NOTHING — no way into /login except the mobile bottom bar's fifth tab.
 * Signed in it replaces the inert name badge with a real menu of the account
 * surfaces that exist today:
 *
 *   - Profile and My properties go through `localized()`, because the module
 *     loader registers every module web.php per locale (LocalizedRoutes in
 *     ModuleServiceProvider) — an Arabic visitor stays in Arabic. /login,
 *     /logout and /admin are registered once, outside the localized group,
 *     so they stay bare.
 *   - My properties appears only when the `portfolio` flag is on and the
 *     Advisor only when `advisor.residential` is — the same absent-not-hidden
 *     rule the primary navigation follows, because the server 404s a
 *     switched-off surface.
 *   - There is no standalone valuation page in the product (valuation lives
 *     inside a portfolio property), so no valuation entry is invented here.
 *   - The admin link renders from the server-shared `is_admin`, and the
 *     server enforces it regardless: /admin sits behind auth + account.active
 *     + mfa + per-route permissions. Hiding here is presentation, not the
 *     boundary.
 *
 * Sign out POSTs /logout exactly as the admin sidebar does.
 */
const page = usePage<SharedPageProps>();
const { localized } = useLocale();

const user = computed(() => page.props.auth.user);
const features = computed(() => page.props.features);

const initials = computed(() => {
    const parts = (user.value?.name ?? '').trim().split(/\s+/).filter((part) => part !== '');
    const letters = (parts[0]?.[0] ?? '') + (parts.length > 1 ? (parts[parts.length - 1]?.[0] ?? '') : '');

    return letters === '' ? '•' : letters.toLocaleUpperCase();
});

const itemClass = `flex min-h-11 w-full items-center gap-2.5 rounded-[0.6rem] px-3 text-sm text-ink-muted
    transition-colors duration-200 ease-calm hover:bg-surface-sunken hover:text-ink
    focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-accent`;

function signOut(close: () => void): void {
    close();
    router.post('/logout');
}
</script>

<template>
    <Link
        v-if="user === null"
        href="/login"
        data-testid="sign-in-link"
        class="flex min-h-11 shrink-0 items-center gap-2 rounded-card border border-line px-3 text-sm
               font-medium text-ink transition-colors duration-200 ease-calm hover:bg-surface-sunken
               focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-accent"
    >
        <AppIcon name="user" class="h-4 w-4 shrink-0 text-ink-muted" />
        <span class="whitespace-nowrap">{{ t('identity.auth.login') }}</span>
    </Link>

    <AppMenu
        v-else
        :label="t('home.nav.account')"
        data-testid="account-menu"
        trigger-class="min-h-11 gap-2 ps-1.5 pe-2 hover:bg-surface-sunken"
        panel-class="min-w-52"
    >
        <template #trigger>
            <UserAvatar :initials="initials" size="sm" />
            <span class="hidden max-w-[9rem] truncate text-sm font-medium text-ink md:inline">
                {{ user?.name }}
            </span>
            <AppIcon name="chevron-down" class="h-3.5 w-3.5 shrink-0 text-ink-muted" />
        </template>

        <template #default="{ close }">
            <Link :href="localized('/account/profile')" :class="itemClass" @click="close()">
                <AppIcon name="user" class="h-4 w-4 shrink-0" />
                {{ t('identity.profile.title') }}
            </Link>

            <Link
                v-if="features['portfolio'] === true"
                :href="localized('/account/portfolio')"
                :class="itemClass"
                @click="close()"
            >
                <AppIcon name="portfolio" class="h-4 w-4 shrink-0" />
                {{ t('portfolio.title') }}
            </Link>

            <Link
                v-if="features['advisor.residential'] === true"
                :href="localized('/advisor')"
                :class="itemClass"
                @click="close()"
            >
                <AppIcon name="spark" class="h-4 w-4 shrink-0" />
                {{ t('nav.public.advisor') }}
            </Link>

            <template v-if="user?.is_admin === true">
                <div class="my-1 border-t border-line" aria-hidden="true" />
                <Link href="/admin" data-testid="account-menu-admin" :class="itemClass" @click="close()">
                    <AppIcon name="shield-check" class="h-4 w-4 shrink-0" />
                    {{ t('nav.admin_dashboard') }}
                </Link>
            </template>

            <div class="my-1 border-t border-line" aria-hidden="true" />

            <button
                type="button"
                data-testid="account-menu-signout"
                :class="itemClass"
                class="text-start hover:text-negative"
                @click="signOut(close)"
            >
                {{ t('nav.sign_out') }}
            </button>
        </template>
    </AppMenu>
</template>
