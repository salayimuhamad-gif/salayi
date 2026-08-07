<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import AppButton from '@/Components/ui/AppButton.vue';
import { t } from '@/lib/i18n';

/*
 * One notification, in full.
 *
 * The reason is shown as its own labelled block rather than left buried at the
 * foot of the rendered body. Spec 22.3 requires the recipient to be told why
 * they received this; a reason technically present at the end of a paragraph
 * is not one anybody reads.
 */
const props = defineProps<{
    notification: {
        id: number;
        key: string;
        subject: string;
        body: string;
        reason: string | null;
        unsubscribe_url: string | null;
        action_url: string | null;
        priority: string;
        channel: string;
        locale: string;
        created_at: string | null;
        read_at: string | null;
    };
}>();

const formatted = (iso: string | null): string =>
    iso ? new Date(iso).toLocaleString() : '—';

/*
 * Navigation lives here rather than in the template. Template expressions
 * resolve against the component instance, which has no `window`, so the inline
 * handler this replaces did not type-check and blocked the guarded build (the
 * reason the shipped bundle predated these pages). Guarded for SSR too, where
 * `window` is genuinely absent.
 *
 * A full page load is deliberate: action_url is an absolute, server-issued
 * destination and may leave the SPA, which router.visit() cannot do.
 */
function openAction(): void {
    const url = props.notification.action_url;

    if (url && typeof window !== 'undefined') {
        window.location.href = url;
    }
}
</script>

<template>
    <Head :title="notification.subject" />

    <AdminLayout>
        <template #title>{{ t('notifications.center.detail_title') }}</template>

        <Link
            href="/admin/notifications"
            class="mb-5 inline-flex items-center gap-1.5 text-sm text-ink-muted transition-colors
                   hover:text-ink focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-accent"
        >
            <svg
                class="h-4 w-4 rtl:-scale-x-100" viewBox="0 0 24 24" fill="none"
                stroke="currentColor" stroke-width="2" aria-hidden="true"
            >
                <path d="m15 18-6-6 6-6" stroke-linecap="round" stroke-linejoin="round" />
            </svg>
            {{ t('notifications.center.back') }}
        </Link>

        <article class="mh-card p-5 sm:p-7">
            <header>
                <h2 class="font-display text-lg font-semibold leading-snug text-ink">
                    {{ notification.subject }}
                </h2>

                <dl class="mt-3 flex flex-wrap items-center gap-x-4 gap-y-1 text-xs text-ink-faint">
                    <div class="flex gap-1.5">
                        <dt>{{ t('notifications.center.received_at') }}:</dt>
                        <dd class="numeral">
                            <time v-if="notification.created_at" :datetime="notification.created_at">
                                {{ formatted(notification.created_at) }}
                            </time>
                        </dd>
                    </div>
                    <div class="flex gap-1.5">
                        <dt>{{ t('notifications.center.channel') }}:</dt>
                        <dd dir="ltr">{{ notification.channel }}</dd>
                    </div>
                </dl>
            </header>

            <!-- The stored body is the envelope's own rendering, which already
                 contains the reason and the unsubscribe line. Rendered as
                 preserved-whitespace text, never as HTML: this string was
                 assembled from moderation notes and listing titles, and
                 v-html here would be a stored-XSS route straight from a form. -->
            <p class="mt-5 whitespace-pre-line text-sm leading-relaxed text-ink">{{ notification.body }}</p>

            <AppButton
                v-if="notification.action_url"
                variant="primary"
                class="mt-6"
                @click="openAction"
            >
                {{ t('notifications.center.open_action') }}
            </AppButton>

            <section v-if="notification.reason" class="mt-7 rounded-card bg-surface-sunken p-4">
                <h3 class="mh-label mb-1.5">{{ t('notifications.center.why_received') }}</h3>
                <p class="text-sm leading-relaxed text-ink-muted">{{ notification.reason }}</p>

                <a
                    v-if="notification.unsubscribe_url"
                    :href="notification.unsubscribe_url"
                    class="mt-3 inline-block text-xs text-ink-faint underline transition-colors
                           hover:text-ink focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-accent"
                >
                    {{ t('notifications.unsubscribe.title') }}
                </a>
            </section>
        </article>
    </AdminLayout>
</template>
