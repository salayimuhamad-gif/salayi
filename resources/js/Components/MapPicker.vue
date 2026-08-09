<script setup lang="ts">
import { onBeforeUnmount, onMounted, ref, shallowRef, watch } from 'vue';
import AppButton from '@/Components/ui/AppButton.vue';
import AppAlert from '@/Components/ui/AppAlert.vue';
import { t } from '@/lib/i18n';
import { parsePolygonRing, polygonToWkt, ringBounds, ringCentroid, type LngLat } from '@/lib/geometry';

const props = withDefaults(defineProps<{
    latitude: number | null;
    longitude: number | null;
    boundaryWkt: string;
    styleUrl?: string | null;
    mode?: 'point' | 'polygon' | 'both';
    height?: string;
}>(), { styleUrl: null, mode: 'both', height: '420px' });

const emit = defineEmits<{
    'update:latitude': [number | null];
    'update:longitude': [number | null];
    'update:boundaryWkt': [string];
}>();

const container = ref<HTMLDivElement | null>(null);
const map = shallowRef<import('maplibre-gl').Map | null>(null);
const marker = shallowRef<import('maplibre-gl').Marker | null>(null);

const drawing = ref(false);
const ring = ref<LngLat[]>([]);
const failed = ref<string | null>(null);

/*
 * Erbil. A fixed fallback for records that carry no coordinates yet — the
 * operating-area centre is not delivered to this component, so re-pointing a
 * deployment at another city means changing this pair.
 */
const FALLBACK_CENTRE: [number, number] = [44.05, 36.2];

/**
 * The style URL comes from configuration (spec 10.1: MapLibre + OSM as the
 * preferred open provider). With none set the component renders a plain
 * background and still functions — clicking, drawing and emitting all work
 * without tiles. Spec 10.1 again: "the application must remain usable when one
 * provider fails."
 */
const resolvedStyle = (): string | import('maplibre-gl').StyleSpecification =>
    props.styleUrl && props.styleUrl.trim() !== ''
        ? props.styleUrl
        : {
            version: 8,
            sources: {},
            layers: [{
                id: 'background',
                type: 'background',
                paint: { 'background-color': '#e8e6e1' },
            }],
        };

async function build(): Promise<void> {
    if (!container.value) return;

    try {
        const maplibre = await import('maplibre-gl');

        const instance = new maplibre.Map({
            container: container.value,
            style: resolvedStyle(),
            center: props.longitude !== null && props.latitude !== null
                ? [props.longitude, props.latitude]
                : FALLBACK_CENTRE,
            zoom: 12,
            attributionControl: { compact: true },
        });

        instance.addControl(new maplibre.NavigationControl({ showCompass: false }), 'top-right');

        instance.on('load', () => {
            instance.addSource('boundary', {
                type: 'geojson',
                data: { type: 'FeatureCollection', features: [] },
            });
            instance.addLayer({
                id: 'boundary-fill',
                type: 'fill',
                source: 'boundary',
                paint: { 'fill-color': '#0f3e59', 'fill-opacity': 0.15 },
            });
            instance.addLayer({
                id: 'boundary-line',
                type: 'line',
                source: 'boundary',
                paint: { 'line-color': '#c9a227', 'line-width': 2 },
            });

            const existing = parsePolygonRing(props.boundaryWkt);
            if (existing) {
                ring.value = existing;
                renderRing();
                const bounds = ringBounds(existing);
                if (bounds) instance.fitBounds(bounds, { padding: 48, duration: 0 });
            }
        });

        instance.on('click', (event) => {
            const point: LngLat = { lng: event.lngLat.lng, lat: event.lngLat.lat };

            if (drawing.value) {
                ring.value = [...ring.value, point];
                renderRing();
                return;
            }

            if (props.mode !== 'polygon') setPoint(point);
        });

        map.value = instance;

        if (props.latitude !== null && props.longitude !== null) {
            placeMarker({ lng: props.longitude, lat: props.latitude });
        }
    } catch (error) {
        // A map that fails to initialise must not take the form down with it.
        // The WKT and coordinate fields remain editable by hand.
        failed.value = error instanceof Error ? error.message : String(error);
    }
}

async function placeMarker(point: LngLat): Promise<void> {
    if (!map.value) return;

    const maplibre = await import('maplibre-gl');

    marker.value?.remove();
    marker.value = new maplibre.Marker({ color: '#0f3e59', draggable: true })
        .setLngLat([point.lng, point.lat])
        .addTo(map.value);

    marker.value.on('dragend', () => {
        const position = marker.value?.getLngLat();
        if (position) emitPoint({ lng: position.lng, lat: position.lat });
    });
}

function setPoint(point: LngLat): void {
    void placeMarker(point);
    emitPoint(point);
}

function emitPoint(point: LngLat): void {
    emit('update:latitude', Number(point.lat.toFixed(7)));
    emit('update:longitude', Number(point.lng.toFixed(7)));
}

function renderRing(): void {
    const source = map.value?.getSource('boundary') as
        | { setData: (data: unknown) => void }
        | undefined;

    if (!source) return;

    source.setData(ring.value.length >= 3
        ? {
            type: 'Feature',
            geometry: {
                type: 'Polygon',
                coordinates: [[...ring.value, ring.value[0]].map((p) => [p.lng, p.lat])],
            },
            properties: {},
        }
        : { type: 'FeatureCollection', features: [] });
}

function startDrawing(): void {
    drawing.value = true;
    ring.value = [];
    renderRing();
}

function undoPoint(): void {
    ring.value = ring.value.slice(0, -1);
    renderRing();
}

/**
 * Finish drawing and emit.
 *
 * The centroid is offered as the point only when none was set by hand — an
 * editor who dropped a pin on a project's entrance meant that, not the
 * mathematical centre of its plot.
 */
function finishDrawing(): void {
    drawing.value = false;

    const wkt = polygonToWkt(ring.value);
    if (!wkt) return;

    emit('update:boundaryWkt', wkt);

    if (props.latitude === null || props.longitude === null) {
        const centre = ringCentroid(ring.value);
        if (centre) setPoint(centre);
    }
}

function clearBoundary(): void {
    drawing.value = false;
    ring.value = [];
    renderRing();
    emit('update:boundaryWkt', '');
}

watch(() => props.boundaryWkt, (value) => {
    if (drawing.value) return;
    const parsed = parsePolygonRing(value);
    ring.value = parsed ?? [];
    renderRing();
});

/*
 * Coordinates typed into the sibling form fields must move the pin. Without
 * this watcher the binding was one-way (map → fields): the form value changed
 * but the already-placed marker stayed put until a full page reload. The
 * echo guard stops the marker's own emissions (click, drag) from re-placing
 * it: after an emit the incoming props equal the marker position to the same
 * 7-decimal rounding, so the move is a no-op and is skipped.
 */
watch(() => [props.latitude, props.longitude] as const, ([lat, lng]) => {
    if (!map.value) return;

    if (lat === null || lng === null) {
        marker.value?.remove();
        marker.value = null;
        return;
    }

    const current = marker.value?.getLngLat();
    if (current
        && Number(current.lat.toFixed(7)) === lat
        && Number(current.lng.toFixed(7)) === lng) {
        return;
    }

    void placeMarker({ lng, lat });

    // Recentre only when the typed point left the viewport — easing the
    // camera on every keystroke of a coordinate field is unusable.
    const bounds = map.value.getBounds();
    if (!bounds.contains([lng, lat])) {
        map.value.easeTo({ center: [lng, lat] });
    }
});

onMounted(() => { void build(); });
onBeforeUnmount(() => { marker.value?.remove(); map.value?.remove(); });
</script>

<template>
    <div class="space-y-3">
        <AppAlert v-if="failed" variant="warning">
            {{ t('geography.map.unavailable') }}
        </AppAlert>

        <div
            v-else
            ref="container"
            class="w-full overflow-hidden rounded-card border border-line"
            :style="{ height }"
            role="application"
            :aria-label="t('geography.map.label')"
        />

        <div class="flex flex-wrap items-center gap-2">
            <template v-if="mode !== 'point'">
                <AppButton v-if="!drawing" variant="secondary" size="sm" @click="startDrawing">
                    {{ t('geography.map.draw_boundary') }}
                </AppButton>

                <template v-else>
                    <AppButton size="sm" :disabled="ring.length < 3" @click="finishDrawing">
                        {{ t('geography.map.finish') }}
                    </AppButton>
                    <AppButton variant="secondary" size="sm" :disabled="ring.length === 0" @click="undoPoint">
                        {{ t('geography.map.undo_point') }}
                    </AppButton>
                    <span class="numeral text-xs text-ink-muted">
                        {{ ring.length }} {{ t('geography.map.points') }}
                    </span>
                </template>

                <AppButton v-if="ring.length > 0 && !drawing" variant="ghost" size="sm" @click="clearBoundary">
                    {{ t('geography.map.clear') }}
                </AppButton>
            </template>

            <p class="ms-auto text-xs text-ink-faint">
                {{ drawing ? t('geography.map.drawing_hint') : t('geography.map.click_hint') }}
            </p>
        </div>
    </div>
</template>
