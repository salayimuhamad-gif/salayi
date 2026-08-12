<script setup lang="ts">
import { nextTick, onBeforeUnmount, ref, watch } from 'vue';
import { Link } from '@inertiajs/vue3';
import AppIcon from '@/Components/Icons/AppIcon.vue';
import { t } from '@/lib/i18n';
import type { PublicNavItem } from '@/Components/Public/navigation';

/*
 * The mobile drawer (§7.1).
 *
 * Erbil traffic is phone-first, so this is the primary navigation rather than a
 * fallback for the sidebar.
 *
 * Four behaviours are load-bearing and none of them are decoration:
 *
 *   - Rendered only when open, so a switched-off module's link is absent from
 *     the DOM rather than merely off-screen. That is the same rule the feature
 *     flags follow upstream in PublicLayout, and breaking it here would quietly
 *     undo it.
 *
 *   - Escape closes. §7.1 asks for it, and without it a keyboard user who opens
 *     the drawer has no way out but the mouse.
 *
 *   - Background scroll is locked while open, by setting overflow on <body> —
 *     no dependency, per §12. The previous value is restored rather than
 *     assumed to be empty, and it is restored on unmount too, so a visitor who
 *     navigates away mid-drawer does not land on a page that cannot scroll.
 *
 *   - Focus moves to the panel on open and returns to the trigger on close.
 *     There is no focus TRAP: §13 forbids one that prevents exiting, and a
 *     hand-rolled trap is the more likely way to build exactly that.
 */
const props = defineProps<{
    open: boolean;
    items: PublicNavItem[];
}>();

const emit = defineEmits<{ close: [] }>();

const panel = ref<HTMLElement | null>(null);
const closeButton = ref<HTMLButtonElement | null>(null);
let restoreOverflow: string | null = null;

/*
 * The control that opened the drawer, captured at open time rather than passed
 * down as a prop. When `open` flips true the trigger is still the focused
 * element — it was just activated — so reading document.activeElement is both
 * accurate and keeps the topbar and the drawer from having to share a ref.
 */
let trigger: HTMLElement | null = null;

function onKeydown(event: KeyboardEvent): void {
    if (event.key === 'Escape') {
        emit('close');
    }
}

function lock(): void {
    if (typeof document === 'undefined' || restoreOverflow !== null) return;

    restoreOverflow = document.body.style.overflow;
    document.body.style.overflow = 'hidden';
    window.addEventListener('keydown', onKeydown);
}

function unlock(): void {
    if (typeof document === 'undefined' || restoreOverflow === null) return;

    document.body.style.overflow = restoreOverflow;
    restoreOverflow = null;
    window.removeEventListener('keydown', onKeydown);
}

watch(
    () => props.open,
    async (isOpen) => {
        if (isOpen) {
            trigger = document.activeElement instanceof HTMLElement ? document.activeElement : null;
            lock();
            await nextTick();
            // The close button, not the panel: landing on a real control means
            // the first Tab goes forward into the list instead of re-entering
            // it, and a screen reader announces something actionable.
            (closeButton.value ?? panel.value)?.focus();

            return;
        }

        unlock();

        /*
         * Focus must come back to where it left. Without this, closing the
         * drawer drops focus onto <body> and the next Tab restarts at the top
         * of the document — which for a keyboard user is indistinguishable from
         * the page having reloaded.
         */
        trigger?.focus();
        trigger = null;
    },
);

onBeforeUnmount(unlock);
</script>

<template>
    <div v-if="open" class="fixed inset-0 z-50 lg:hidden">
        <!-- A real button rather than a div with a click handler, so it is
             reachable and announced. Its label is the close action because that
             is what activating it does. -->
        <button
            type="button"
            class="absolute inset-0 bg-black/70"
            :aria-label="t('app.actions.close')"
            @click="emit('close')"
        />

        <!--
            role="dialog" + aria-modal because the overlay makes the rest of the
            page inert to a pointer, and a screen reader has to be told the same
            thing or it will keep reading the content behind. Labelled by the
            visible heading rather than a duplicate aria-label, so the two can
            never disagree.
        -->
        <div
            id="public-mobile-nav"
            ref="panel"
            role="dialog"
            aria-modal="true"
            aria-labelledby="public-mobile-nav-title"
            tabindex="-1"
            class="absolute inset-y-0 start-0 flex w-[min(19rem,85vw)] flex-col
                   border-e border-line bg-surface-raised focus:outline-none"
            style="padding-block-end: env(safe-area-inset-bottom)"
        >
            <div class="flex h-16 shrink-0 items-center justify-between border-b border-line px-4">
                <h2 id="public-mobile-nav-title" class="mh-lux-eyebrow">
                    {{ t('nav.primary_navigation') }}
                </h2>
                <button
                    ref="closeButton"
                    type="button"
                    class="-me-2 flex min-h-11 min-w-11 items-center justify-center rounded-card text-ink-muted
                           transition-colors hover:bg-surface-sunken hover:text-ink
                           focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-accent"
                    :aria-label="t('app.actions.close')"
                    @click="emit('close')"
                >
                    <AppIcon name="close" class="h-5 w-5" />
                </button>
            </div>

            <nav class="flex-1 overflow-y-auto p-3">
                <Link
                    v-for="item in items"
                    :key="item.key"
                    :href="item.href"
                    class="mh-lux-nav-link min-h-[2.75rem]"
                    :aria-current="item.active ? 'page' : undefined"
                    @click="emit('close')"
                >
                    <AppIcon
                        :name="item.icon"
                        class="h-5 w-5 shrink-0"
                        :class="item.emphasised && !item.active ? 'mh-lux-gold' : ''"
                    />
                    <span class="min-w-0 flex-1 truncate">{{ t(`nav.public.${item.key}`) }}</span>
                    <AppIcon v-if="item.emphasised" name="spark" class="h-3.5 w-3.5 shrink-0 mh-lux-gold" />
                </Link>
            </nav>
        </div>
    </div>
</template>
