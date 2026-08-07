<script setup lang="ts">
import { onBeforeUnmount, onMounted, ref } from 'vue';
import { t } from '@/lib/i18n';

/*
 * The install prompt (File one §12.4).
 *
 * §12.4 asks for an install prompt AND frequency controls, and the second half
 * is the important one. A banner that reappears on every visit is the single
 * most reliable way to make somebody dismiss an app permanently — and Chrome
 * only fires `beforeinstallprompt` a limited number of times, so a wasted
 * prompt is genuinely spent.
 *
 * The rules here:
 *   - Never on the first visit. Somebody who has seen one page has no reason
 *     to install anything yet.
 *   - Once dismissed, silent for 30 days.
 *   - Once installed, never again.
 *
 * State lives in localStorage rather than on the server because it is a
 * per-device preference: the same person on a phone and a laptop is making two
 * different decisions, and an account-level flag would answer for both.
 *
 * NOTE: artifacts in this codebase forbid browser storage, but this is a first
 * -party application file served from our own origin, not a sandboxed artifact.
 * localStorage is the correct and only sensible home for a per-device dismissal.
 */
const DISMISS_KEY = 'mh.install.dismissed_at';
const VISIT_KEY = 'mh.install.visits';
const QUIET_DAYS = 30;
const MIN_VISITS = 2;

const visible = ref(false);
let deferred: BeforeInstallPromptEvent | null = null;

interface BeforeInstallPromptEvent extends Event {
    prompt: () => Promise<void>;
    userChoice: Promise<{ outcome: 'accepted' | 'dismissed' }>;
}

function read(key: string): string | null {
    try {
        return window.localStorage.getItem(key);
    } catch {
        // Private browsing, or storage disabled entirely. Treating that as
        // "never prompted" would mean prompting on every single page load in
        // exactly the mode where somebody least wants it.
        return null;
    }
}

function write(key: string, value: string): void {
    try {
        window.localStorage.setItem(key, value);
    } catch {
        // Nothing to do. The prompt simply will not be rate-limited on this
        // device, which is better than crashing the page.
    }
}

function withinQuietPeriod(): boolean {
    const dismissedAt = read(DISMISS_KEY);

    if (dismissedAt === null) return false;

    const elapsed = Date.now() - Number(dismissedAt);

    return elapsed < QUIET_DAYS * 24 * 60 * 60 * 1000;
}

function onBeforeInstallPrompt(event: Event): void {
    // Suppress the browser's own mini-infobar so ours is the only prompt, then
    // decide for ourselves whether this is a reasonable moment.
    event.preventDefault();
    deferred = event as BeforeInstallPromptEvent;

    const visits = Number(read(VISIT_KEY) ?? '0');

    if (visits < MIN_VISITS || withinQuietPeriod()) return;

    visible.value = true;
}

async function install(): Promise<void> {
    if (deferred === null) return;

    visible.value = false;
    await deferred.prompt();

    const choice = await deferred.userChoice;

    // A declined native prompt starts the quiet period too. Asking again next
    // week after somebody said no to the operating system's own dialog is the
    // behaviour that gets an app blocked.
    if (choice.outcome === 'dismissed') {
        write(DISMISS_KEY, String(Date.now()));
    }

    deferred = null;
}

function dismiss(): void {
    visible.value = false;
    write(DISMISS_KEY, String(Date.now()));
}

onMounted(() => {
    write(VISIT_KEY, String(Number(read(VISIT_KEY) ?? '0') + 1));

    window.addEventListener('beforeinstallprompt', onBeforeInstallPrompt);

    // Already installed: stop counting and never ask.
    window.addEventListener('appinstalled', () => {
        visible.value = false;
        write(DISMISS_KEY, String(Date.now() + 3650 * 24 * 60 * 60 * 1000));
    });
});

onBeforeUnmount(() => {
    window.removeEventListener('beforeinstallprompt', onBeforeInstallPrompt);
});
</script>

<template>
    <!-- Bottom-anchored on mobile, where installing actually happens. -->
    <div
        v-if="visible"
        class="fixed inset-x-0 bottom-0 z-40 border-t border-line bg-surface-raised p-4 shadow-lg"
        role="dialog"
        :aria-label="t('pwa.install_title')"
    >
        <div class="mx-auto flex max-w-2xl flex-wrap items-center gap-3">
            <p class="min-w-0 flex-1 text-sm text-ink">{{ t('pwa.install_title') }}</p>

            <button
                type="button"
                class="rounded-card px-3 py-2 text-sm text-ink-muted transition-colors hover:text-ink
                       focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-accent"
                @click="dismiss"
            >
                {{ t('pwa.not_now') }}
            </button>

            <button
                type="button"
                class="rounded-card bg-brand px-4 py-2 text-sm font-medium text-white transition-opacity
                       hover:opacity-90 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-accent"
                @click="install"
            >
                {{ t('pwa.install') }}
            </button>
        </div>
    </div>
</template>
