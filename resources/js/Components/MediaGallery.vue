<script setup lang="ts">
import { ref } from 'vue';
import { t } from '@/lib/i18n';

interface Image {
    url: string;
    alt: string | null;
    credit: string | null;
    width: number | null;
    height: number | null;
}

const props = defineProps<{ media: Image[] }>();

const active = ref(0);

function select(index: number): void {
    active.value = Math.max(0, Math.min(props.media.length - 1, index));
}

/*
 * Keyboard navigation, because a gallery reachable only by pointer is
 * unreachable for anyone using a keyboard — and the thumbnails are buttons for
 * exactly that reason rather than clickable divs.
 */
function onKey(event: KeyboardEvent): void {
    if (event.key === 'ArrowRight') select(active.value + 1);
    if (event.key === 'ArrowLeft') select(active.value - 1);
}
</script>

<template>
    <div v-if="media.length > 0" class="space-y-3" @keydown="onKey">
        <figure class="overflow-hidden rounded-card bg-surface-sunken">
            <img
                :src="media[active].url"
                :alt="media[active].alt ?? ''"
                :width="media[active].width ?? undefined"
                :height="media[active].height ?? undefined"
                class="h-auto w-full object-cover"
                loading="eager"
                fetchpriority="high"
                decoding="async"
            >
            <!-- §10.1: the hero image of a detail page is the LCP candidate —
                 it alone loads eagerly; every thumbnail below stays lazy. -->
            <figcaption v-if="media[active].credit" class="px-3 py-2 text-xs text-ink-faint">
                {{ media[active].credit }}
            </figcaption>
        </figure>

        <!--
          An image with no alt text gets alt="" rather than a filename. A screen
          reader announcing "IMG_20260714.jpg" is worse than silence, because it
          interrupts without informing.
        -->
        <div v-if="media.length > 1" class="flex snap-x snap-mandatory gap-2 overflow-x-auto pb-1">
            <button
                v-for="(image, index) in media"
                :key="image.url"
                type="button"
                class="h-16 w-20 shrink-0 snap-start overflow-hidden rounded border-2 transition-colors
                       focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-accent"
                :class="index === active ? 'border-brand' : 'border-transparent opacity-70 hover:opacity-100'"
                :aria-label="`${t('media.image')} ${index + 1}`"
                :aria-current="index === active ? 'true' : undefined"
                @click="select(index)"
            >
                <img :src="image.url" :alt="''" class="h-full w-full object-cover" loading="lazy">
            </button>
        </div>
    </div>
</template>
