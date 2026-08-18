import { ref, type Ref } from 'vue';

export type SheetDetent = 'peek' | 'half' | 'full';

export const DETENT_HEIGHTS: Record<SheetDetent, string> = {
    peek: '35dvh',
    half: '65dvh',
    full: '92dvh',
};

/*
 * Detent state + pointer-drag maths for MobileBottomSheet (redesign §6.5).
 * Drag-down only; a pull past the threshold requests dismissal, a shorter
 * pull snaps back. Transform-only — the sheet never animates layout.
 */
export function useBottomSheet(
    detents: Ref<SheetDetent[]>,
    onDismiss: () => void,
): {
    detent: Ref<SheetDetent>;
    dragY: Ref<number | null>;
    onPointerDown: (event: PointerEvent) => void;
    onPointerMove: (event: PointerEvent) => void;
    onPointerUp: () => void;
    reset: () => void;
} {
    const detent = ref<SheetDetent>(detents.value[0] ?? 'half');
    const dragY = ref<number | null>(null);
    let startY = 0;

    const onPointerDown = (event: PointerEvent): void => {
        startY = event.clientY;
        dragY.value = 0;
    };

    const onPointerMove = (event: PointerEvent): void => {
        if (dragY.value === null) return;
        dragY.value = Math.max(0, event.clientY - startY);
    };

    const onPointerUp = (): void => {
        if (dragY.value !== null && dragY.value > 96) {
            onDismiss();
        }
        dragY.value = null;
    };

    const reset = (): void => {
        detent.value = detents.value[0] ?? 'half';
        dragY.value = null;
    };

    return { detent, dragY, onPointerDown, onPointerMove, onPointerUp, reset };
}
