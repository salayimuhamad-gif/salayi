<script setup lang="ts">
import { computed, ref } from 'vue';
import { Head, router, usePage } from '@inertiajs/vue3';
import PublicLayout from '@/Layouts/PublicLayout.vue';
import AppButton from '@/Components/ui/AppButton.vue';
import AppAlert from '@/Components/ui/AppAlert.vue';
import { t } from '@/lib/i18n';
import type { SharedPageProps } from '@/Types/inertia';

/*
 * The page an unsubscribe link lands on.
 *
 * Nothing happens on arrival. Telegram and every mail client fetch link
 * previews automatically, so a page that acted on load would opt people out
 * because a bot glanced at the message. The withdrawal is a POST the person
 * has to press.
 */
const props = defineProps<{
    status: 'confirm' | 'already' | 'invalid';
    token: string | null;
    purpose: string | null;
    purposes: Array<{ value: string; label: string }>;
    transactional_notice?: string;
}>();

/*
 * SharedPageProps, not a flash-only shape. Inertia constrains usePage() to the
 * full PageProps contract, so narrowing it here failed to compile; flash is
 * part of the shared payload already.
 */
const page = usePage<SharedPageProps>();
const working = ref(false);

// After a successful POST the server flashes and Inertia re-renders this same
// page, so the flash is what tells the person it worked.
const done = computed(() => Boolean(page.props.flash?.success));

function confirm(): void {
    if (!props.token) return;
    working.value = true;
    router.post(`/unsubscribe/${props.token}`, {}, {
        preserveScroll: true,
        onFinish: () => { working.value = false; },
    });
}

function undo(): void {
    if (!props.token) return;
    working.value = true;
    router.post(`/unsubscribe/${props.token}/undo`, {}, {
        preserveScroll: true,
        onFinish: () => { working.value = false; },
    });
}
</script>

<template>
    <Head :title="t('notifications.unsubscribe.title')" />

    <PublicLayout>
        <div class="mx-auto w-full max-w-xl">
            <div class="mh-card p-6 sm:p-8">
                <h1 class="font-display text-2xl font-bold text-ink">
                    {{ t('notifications.unsubscribe.title') }}
                </h1>

                <!-- Invalid: one message for malformed and for unknown, so the
                     page cannot be used to work out which accounts exist. -->
                <template v-if="status === 'invalid'">
                    <AppAlert variant="danger" class="mt-5">
                        {{ t('notifications.unsubscribe.invalid') }}
                    </AppAlert>
                    <p class="mt-4 text-sm text-ink-muted">
                        {{ t('notifications.unsubscribe.invalid_hint') }}
                    </p>
                </template>

                <template v-else>
                    <AppAlert v-if="page.props.flash?.error" variant="danger" class="mt-5">
                        {{ page.props.flash.error }}
                    </AppAlert>

                    <!-- Done. The undo is offered immediately, because
                         unsubscribing by mistake is common and the alternative
                         is a support request nobody sends. -->
                    <template v-if="done">
                        <AppAlert variant="success" class="mt-5">
                            {{ page.props.flash?.success }}
                        </AppAlert>

                        <AppButton variant="ghost" size="sm" class="mt-5" :loading="working" @click="undo">
                            {{ t('notifications.unsubscribe.undo') }}
                        </AppButton>
                    </template>

                    <template v-else>
                        <p class="mt-4 text-sm leading-relaxed text-ink-muted">
                            {{ status === 'already'
                                ? t('notifications.unsubscribe.already')
                                : t('notifications.unsubscribe.intro') }}
                        </p>

                        <ul class="mt-5 space-y-2">
                            <li
                                v-for="item in purposes"
                                :key="item.value"
                                class="flex items-start gap-2.5 rounded-card bg-surface-sunken px-3.5 py-2.5 text-sm text-ink"
                            >
                                <span class="mt-1.5 h-1.5 w-1.5 shrink-0 rounded-full bg-accent" aria-hidden="true" />
                                <span>{{ item.label }}</span>
                            </li>
                        </ul>

                        <AppButton
                            v-if="status === 'confirm'"
                            variant="primary"
                            class="mt-6"
                            block
                            :loading="working"
                            @click="confirm"
                        >
                            {{ t('notifications.unsubscribe.confirm') }}
                        </AppButton>

                        <AppButton
                            v-else
                            variant="secondary"
                            class="mt-6"
                            block
                            :loading="working"
                            @click="undo"
                        >
                            {{ t('notifications.unsubscribe.resubscribe') }}
                        </AppButton>
                    </template>

                    <!-- Said up front rather than discovered afterwards: a
                         moderation outcome and a security notice keep arriving,
                         and finding that out later feels like a trick. -->
                    <p class="mt-6 border-t border-line pt-4 text-xs leading-relaxed text-ink-faint">
                        {{ transactional_notice ?? t('notifications.unsubscribe.transactional_notice') }}
                    </p>
                </template>
            </div>
        </div>
    </PublicLayout>
</template>
