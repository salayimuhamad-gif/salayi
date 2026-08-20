<script setup lang="ts">
import { computed, onBeforeUnmount, ref, watch } from 'vue';
import AppIcon from '@/Components/Icons/AppIcon.vue';
import { t } from '@/lib/i18n';
import { DETENT_HEIGHTS, useBottomSheet, type SheetDetent } from '@/Composables/useBottomSheet';

/*
 * The mobile bottom sheet (redesign §6.5) — a `<lg` component; desktop keeps
 * inline panels. `role="dialog"` + `aria-modal`, focus moved in on open and
 * returned to the previously-focused element on close, body scroll-lock
 * saved/restored (the same discipline PublicMobileNav established), Escape
 * and backdrop and drag-down all dismiss. Transform-only motion; the global
 * reduced-motion clamp makes open/close instant.
 */
const props = withDefaults(defineProps<{
    open: boolean;
    title?: string;
    detents?: SheetDetent[];
}>(), { title: undefined, detents: () => ['half', 'full'] });

const emit = defineEmits<{ close: [] }>();

const sheet = ref<HTMLElement | null>(null);
const detentsRef = computed(() => props.detents);
const { detent, dragY, onPointerDown, onPointerMove, onPointerUp, reset } = useBottomSheet(
    detentsRef,
    () => emit('close'),
);

let previousOverflow = '';
let trigger: HTMLElement | null = null;

watch(() => props.open, (open) => {
    if (open) {
        reset();
        trigger = document.activeElement as HTMLElement | null;
        previousOverflow = document.body.style.overflow;
        document.body.style.overflow = 'hidden';
        requestAnimationFrame(() => {
            sheet.value?.querySelector<HTMLElement>('[data-sheet-initial]')?.focus();
        });
    } else {
        document.body.style.overflow = previousOverflow;
        trigger?.focus();
        trigger = null;
    }
});

function onKeydown(event: KeyboardEvent): void {
    if (event.key === 'Escape') emit('close');
}

onBeforeUnmount(() => {
    if (props.open) document.body.style.overflow = previousOverflow;
});
</script>

<template>
    <Teleport to="body">
        <Transition name="mh-sheet">
            <div
                v-if="open"
                class="fixed inset-0 z-50 lg:hidden"
                role="dialog"
                aria-modal="true"
                :aria-label="title"
                @keydown="onKeydown"
            >
                <!-- Tokenized scrim (Wave 2A): identical ink dim in light;
                     under midnight it deepens instead of washing out, because
                     ink there is near-white. -->
                <button
                    type="button"
                    class="mh-scrim absolute inset-0"
                    :aria-label="t('app.actions.close')"
                    @click="emit('close')"
                />

                <div
                    ref="sheet"
                    class="absolute inset-x-0 bottom-0 flex flex-col rounded-t-panel bg-surface-raised
                           shadow-overlay transition-transform duration-300 ease-calm"
                    :style="{
                        height: DETENT_HEIGHTS[detent],
                        transform: dragY ? `translateY(${dragY}px)` : undefined,
                        transitionDuration: dragY !== null ? '0ms' : undefined,
                        paddingBottom: 'env(safe-area-inset-bottom)',
                    }"
                >
                    <button
                        type="button"
                        data-sheet-initial
                        class="mx-auto flex h-11 w-full max-w-xs touch-none items-start justify-center"
                        :aria-label="title ?? t('app.actions.close')"
                        @pointerdown="onPointerDown"
                        @pointermove="onPointerMove"
                        @pointerup="onPointerUp"
                        @pointercancel="onPointerUp"
                    >
                        <span class="mt-2.5 h-1 w-10 rounded-pill bg-line-strong" aria-hidden="true" />
                    </button>

                    <header v-if="title" class="flex items-center justify-between border-b border-line px-5 pb-3">
                        <h2 class="min-w-0 truncate text-base font-semibold text-ink">{{ title }}</h2>
                        <button
                            type="button"
                            class="flex min-h-11 min-w-11 items-center justify-center rounded-card text-ink-muted"
                            @click="emit('close')"
                        >
                            <AppIcon name="close" class="h-5 w-5" aria-hidden="true" />
                            <span class="sr-only">{{ t('app.actions.close') }}</span>
                        </button>
                    </header>

                    <div class="min-h-0 flex-1 overflow-y-auto px-5 py-4">
                        <slot />
                    </div>
                </div>
            </div>
        </Transition>
    </Teleport>
</template>

<style scoped>
.mh-sheet-enter-active,
.mh-sheet-leave-active {
    transition: opacity 0.2s cubic-bezier(0.22, 1, 0.36, 1);
}

.mh-sheet-enter-from,
.mh-sheet-leave-to {
    opacity: 0;
}
</style>
