<script setup lang="ts">
import { nextTick, onBeforeUnmount, ref, useId, watch } from 'vue';

/*
 * The shell's disclosure menu (redesign Wave 1).
 *
 * One primitive for every header dropdown, so the language and account
 * controls cannot drift apart the way the three inline locale switchers did.
 * It is a DISCLOSURE, not an ARIA menubar: the items the consumers put inside
 * are ordinary links and buttons, announced as what they are, which is the
 * same judgement the mobile drawer already made. What the pattern owes a
 * keyboard is implemented here once:
 *
 *   - Enter/Space open it (native button activation — nothing to reimplement);
 *   - ArrowDown on the closed trigger opens it too;
 *   - focus moves into the panel on open, to the current item when one is
 *     marked `aria-current`, so the language menu opens ON the language you
 *     are in rather than on the first row;
 *   - ArrowUp/ArrowDown/Home/End walk the items without wrapping a screen
 *     reader out of context (they wrap visually — a two-item menu should not
 *     dead-end);
 *   - Escape closes and puts focus back on the trigger, exactly like the
 *     drawer;
 *   - Tab closes and returns focus to the trigger BEFORE the default action
 *     runs, so the tab order continues from the control the visitor opened —
 *     not from a panel that no longer exists;
 *   - a pointer press outside closes it without stealing focus.
 *
 * The panel is rendered only while open — the same rule the drawer follows —
 * so a closed menu's links are absent from the DOM, not merely invisible.
 * `aria-controls` points at the panel id regardless, matching the existing
 * `aria-controls="public-mobile-nav"` convention for a not-yet-rendered
 * target.
 *
 * Positioning is logical (`start`/`end`), so RTL needs no branch here at all.
 */
withDefaults(
    defineProps<{
        /**
         * Accessible name for the trigger. Required: every consumer renders an
         * icon-led trigger, and an unnamed icon button is the exact defect the
         * old shell shipped under the "filter" mislabel.
         */
        label: string;
        /** Panel edge aligned with the trigger. Logical, so RTL mirrors it. */
        align?: 'start' | 'end';
        triggerClass?: string;
        panelClass?: string;
    }>(),
    { align: 'end', triggerClass: '', panelClass: '' },
);

const open = ref(false);
const root = ref<HTMLElement | null>(null);
const trigger = ref<HTMLButtonElement | null>(null);
const panel = ref<HTMLElement | null>(null);
const panelId = useId();

function items(): HTMLElement[] {
    return Array.from(
        panel.value?.querySelectorAll<HTMLElement>('a[href], button:not([disabled])') ?? [],
    );
}

function close(options: { focusTrigger?: boolean } = {}): void {
    if (!open.value) {
        return;
    }

    open.value = false;

    if (options.focusTrigger === true) {
        trigger.value?.focus();
    }
}

async function openPanel(): Promise<void> {
    open.value = true;
    await nextTick();

    const all = items();
    const current = all.find((item) => item.getAttribute('aria-current') !== null);

    (current ?? all[0])?.focus();
}

function toggle(): void {
    if (open.value) {
        close({ focusTrigger: true });

        return;
    }

    void openPanel();
}

function onKeydown(event: KeyboardEvent): void {
    if (!open.value) {
        // ArrowDown on the closed trigger opens, per the disclosure pattern.
        // Enter and Space already arrive as a click on the native button.
        if (event.key === 'ArrowDown') {
            event.preventDefault();
            void openPanel();
        }

        return;
    }

    if (event.key === 'Escape') {
        event.preventDefault();
        close({ focusTrigger: true });

        return;
    }

    if (event.key === 'Tab') {
        // Refocus the trigger first; the default action then continues the
        // tab order from there instead of from an unmounted panel.
        close({ focusTrigger: true });

        return;
    }

    if (!['ArrowDown', 'ArrowUp', 'Home', 'End'].includes(event.key)) {
        return;
    }

    const all = items();

    if (all.length === 0) {
        return;
    }

    event.preventDefault();

    const index = all.indexOf(document.activeElement as HTMLElement);
    const next =
        event.key === 'Home'
            ? 0
            : event.key === 'End'
                ? all.length - 1
                : event.key === 'ArrowDown'
                    ? (index + 1) % all.length
                    : (index - 1 + all.length) % all.length;

    all[next]?.focus();
}

function onOutsidePointerDown(event: PointerEvent): void {
    if (root.value !== null && !root.value.contains(event.target as Node)) {
        close();
    }
}

watch(open, (isOpen) => {
    if (typeof document === 'undefined') {
        return;
    }

    if (isOpen) {
        document.addEventListener('pointerdown', onOutsidePointerDown);

        return;
    }

    document.removeEventListener('pointerdown', onOutsidePointerDown);
});

onBeforeUnmount(() => {
    if (typeof document !== 'undefined') {
        document.removeEventListener('pointerdown', onOutsidePointerDown);
    }
});
</script>

<template>
    <div ref="root" class="relative" @keydown="onKeydown">
        <button
            ref="trigger"
            type="button"
            class="flex items-center justify-center rounded-card transition-colors duration-200 ease-calm
                   focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-accent"
            :class="triggerClass"
            :aria-expanded="open ? 'true' : 'false'"
            :aria-controls="panelId"
            aria-haspopup="true"
            :aria-label="label"
            @click="toggle"
        >
            <slot name="trigger" :open="open" />
        </button>

        <Transition
            enter-active-class="transition duration-150 ease-calm"
            enter-from-class="-translate-y-1 opacity-0"
            enter-to-class="translate-y-0 opacity-100"
        >
            <div
                v-if="open"
                :id="panelId"
                ref="panel"
                class="absolute top-full z-50 mt-2 min-w-44 rounded-card border border-line
                       bg-surface-raised/95 p-1.5 shadow-overlay backdrop-blur"
                :class="[align === 'end' ? 'end-0' : 'start-0', panelClass]"
            >
                <slot :close="close" />
            </div>
        </Transition>
    </div>
</template>
