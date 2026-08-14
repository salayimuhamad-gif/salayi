<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref, shallowRef } from 'vue';
import { usePage } from '@inertiajs/vue3';
import { createMapAdapter, type MapAdapter } from '@/lib/map';

/*
 * Shared read-only map preview for detail pages (redesign §13.2): one
 * project/area/place located on real, EXISTING coordinates — this component
 * renders what the server already sent and nothing else. No coordinates, no
 * mount (the parent's v-if guards); a provider failure degrades SILENTLY to
 * nothing — a detail page loses a nicety, never its content, and the page's
 * own textual location facts remain.
 *
 * Same shared adapter as every other map (clustering path unused — a single
 * neutral pin via the marker source), lazily constructed on intersection so
 * the MapLibre chunk stays out of the critical path.
 */
const props = withDefaults(defineProps<{
    lat: number;
    lng: number;
    /** Accessible name for the map region, already translated. */
    label: string;
    zoom?: number;
}>(), { zoom: 14 });

const page = usePage();
const styleUrl = computed(() => (page.props.map as { style_url?: string | null } | undefined)?.style_url ?? null);

const wrapper = ref<HTMLElement | null>(null);
const container = ref<HTMLDivElement | null>(null);
const adapter = shallowRef<MapAdapter | null>(null);
const failed = ref(false);

let started = false;
/*
 * Construction can outlive the component: the adapter chunk import is slow
 * on a weak connection, and a visitor can navigate away before it resolves.
 * At that point onBeforeUnmount has already run against a null adapter ref,
 * so the adapter that materialises later must be destroyed HERE — otherwise
 * its ResizeObserver, worker and WebGL context leak for the page lifetime.
 */
let disposed = false;
let observer: IntersectionObserver | null = null;

async function start(): Promise<void> {
    if (started || !container.value) return;
    started = true;

    try {
        const result = await createMapAdapter('maplibre', {
            container: container.value,
            styleUrl: styleUrl.value,
            googleKey: null,
            centre: { lat: props.lat, lng: props.lng },
            zoom: props.zoom,
            minZoom: 9,
            maxZoom: 17,
            events: {
                onMoveEnd: () => {},
                onClick: () => {},
                onError: () => {
                    failed.value = true;
                },
            },
        });

        if (disposed) {
            result.adapter.destroy();

            return;
        }

        adapter.value = result.adapter;
        await result.adapter.ready();

        if (disposed) {
            return;
        }

        adapter.value.setPoints([{
            lat: props.lat,
            lng: props.lng,
            title: props.label,
            colour: '#0f3e59',
        }]);
    } catch {
        // Silent degradation stays silent — but not leaky: the adapter
        // installed just above is destroyed rather than idling behind the
        // collapsed box. After disposal the unmount hook already did.
        if (!disposed) {
            adapter.value?.destroy();
            adapter.value = null;
            failed.value = true;
        }
    }
}

onMounted(() => {
    if (typeof IntersectionObserver === 'undefined') {
        void start();
        return;
    }

    observer = new IntersectionObserver((entries) => {
        if (entries.some((entry) => entry.isIntersecting)) {
            observer?.disconnect();
            observer = null;
            void start();
        }
    }, { rootMargin: '200px' });

    if (wrapper.value) observer.observe(wrapper.value);
});

onBeforeUnmount(() => {
    disposed = true;
    observer?.disconnect();
    adapter.value?.destroy();
    adapter.value = null;
});
</script>

<template>
    <!-- Silent degradation: on provider failure the whole box collapses. -->
    <div v-show="!failed" ref="wrapper" class="overflow-hidden rounded-card border border-line">
        <div ref="container" class="h-64 w-full sm:h-72" role="application" :aria-label="label" />
    </div>
</template>
