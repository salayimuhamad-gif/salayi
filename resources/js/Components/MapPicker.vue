<script setup lang="ts">
import { onBeforeUnmount, onMounted, ref, shallowRef, watch } from 'vue';
import AppButton from '@/Components/ui/AppButton.vue';
import AppAlert from '@/Components/ui/AppAlert.vue';
import { t } from '@/lib/i18n';
import { createMapAdapter, type MapAdapter } from '@/lib/map';
import {
    boundaryDisplayCollection,
    classifyBoundaryComponents,
    componentsBounds,
    parsePolygonRing,
    polygonToWkt,
    ringBounds,
    ringCentroid,
    type BoundaryComplexity,
    type LngLat,
} from '@/lib/geometry';
import { fromWkt } from '@/lib/wizard/geometry';

/*
 * The admin location picker, ON THE SHARED ADAPTER.
 *
 * This component previously constructed MapLibre directly — the second map
 * stack the audit's RC4 names — so it received none of the adapter's
 * production behaviour: no bounded readiness, no resize self-healing (it is
 * routinely built inside a hidden v-show tab, where the canvas measures
 * 0×0), and its own private error path. It now builds through
 * createMapAdapter like every public map, and the adapter's ResizeObserver
 * recovers the canvas the moment the Location tab becomes visible — no
 * rebuild, exactly one map instance.
 *
 * The contract is unchanged: click to place the point, drag the pin, draw a
 * simple boundary ring, keep the sibling latitude/longitude fields
 * synchronized both ways, and NEVER take the form down with a failed map —
 * manual coordinate editing always works, and a provider failure renders a
 * human-readable message with a retry, not a stack trace and not silence.
 */
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
const adapter = shallowRef<MapAdapter | null>(null);

const drawing = ref(false);
const ring = ref<LngLat[]>([]);
const failed = ref(false);
const building = ref(false);
/*
 * Geometry fidelity (canonical L1). This picker edits exactly one hole-free
 * ring, but the stored WKT may be richer — a Polygon with holes, or a
 * MultiPolygon, both of which the server accepts at every door. Such a
 * boundary used to render as nothing (or as its exterior alone), and a
 * redraw or clear then silently replaced it with a downgrade. Now: complex
 * geometry renders in FULL, read-only, with a stated limitation; replacing
 * or clearing it demands an explicit, separate intent plus confirmation;
 * cancelling either emits nothing. Simple single-ring boundaries keep the
 * exact editing behavior they always had.
 */
const complexity = ref<BoundaryComplexity>('none');
const confirming = ref<'replace' | 'clear' | null>(null);
/*
 * Construction can outlive the component: unmounting mid-build destroys a
 * still-null adapter ref, so the adapter that resolves later must be
 * destroyed HERE, before it is installed — and no state may change after.
 */
let disposed = false;

/*
 * Erbil. A fixed fallback for records that carry no coordinates yet — the
 * operating-area centre is not delivered to this component, so re-pointing a
 * deployment at another city means changing this pair.
 */
const FALLBACK_CENTRE = { lat: 36.2, lng: 44.05 };

async function build(): Promise<void> {
    if (!container.value || building.value || disposed) return;

    building.value = true;
    failed.value = false;

    try {
        const result = await createMapAdapter('maplibre', {
            container: container.value,
            styleUrl: props.styleUrl,
            googleKey: null,
            centre: props.latitude !== null && props.longitude !== null
                ? { lat: props.latitude, lng: props.longitude }
                : FALLBACK_CENTRE,
            zoom: 12,
            // With no configured style the picker still works on a plain
            // background — clicking, drawing and emitting need no tiles.
            fallbackStyle: 'plain',
            events: {
                onMoveEnd: () => {},
                onClick: (point) => {
                    if (drawing.value) {
                        ring.value = [...ring.value, { lng: point.lng, lat: point.lat }];
                        renderRing();
                        return;
                    }

                    if (props.mode !== 'polygon') setPoint({ lng: point.lng, lat: point.lat });
                },
                onError: () => {
                    // A provider failure after construction: state it; the
                    // form and the coordinate fields keep working.
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

        // Existing geometry, restored on edit: pin first, then the
        // boundary — and the camera fits the geometry when one exists.
        if (props.latitude !== null && props.longitude !== null) {
            placePin({ lng: props.longitude, lat: props.latitude });
        }

        syncBoundary(props.boundaryWkt, true);
    } catch {
        // Bounded readiness rejected (style failed or stalled): a clear
        // message with a retry — never an indefinite blank surface. After
        // disposal the unmount hook already destroyed the adapter and no
        // state may change.
        if (!disposed) {
            adapter.value?.destroy();
            adapter.value = null;
            failed.value = true;
        }
    } finally {
        if (!disposed) {
            building.value = false;
        }
    }
}

function retry(): void {
    adapter.value?.destroy();
    adapter.value = null;
    void build();
}

function placePin(point: LngLat): void {
    adapter.value?.setPin?.(
        { lat: point.lat, lng: point.lng },
        (dragged) => emitPoint({ lng: dragged.lng, lat: dragged.lat }),
    );
}

function setPoint(point: LngLat): void {
    placePin(point);
    emitPoint(point);
}

function emitPoint(point: LngLat): void {
    emit('update:latitude', Number(point.lat.toFixed(7)));
    emit('update:longitude', Number(point.lng.toFixed(7)));
}

/**
 * One reader for the incoming WKT, shared by construction and the prop
 * watch. Simple single-ring boundaries keep the historical path exactly
 * (exterior into `ring`, editable); complex ones render read-only in full
 * fidelity through the wizard parser, with the editing ring left empty.
 */
function syncBoundary(value: string, fitCamera: boolean): void {
    const components = fromWkt(value || null);
    complexity.value = classifyBoundaryComponents(components);
    confirming.value = null;

    if (complexity.value === 'complex') {
        ring.value = [];
        adapter.value?.setBoundaries(boundaryDisplayCollection(components));

        if (fitCamera) {
            const bounds = componentsBounds(components);
            if (bounds) {
                const [[west, south], [east, north]] = bounds;
                adapter.value?.flyTo({ lng: (west + east) / 2, lat: (south + north) / 2 }, 13);
            }
        }

        return;
    }

    const existing = complexity.value === 'simple' ? parsePolygonRing(value) : null;
    ring.value = existing ?? [];
    renderRing();

    if (existing && fitCamera) {
        const bounds = ringBounds(existing);
        if (bounds) {
            const [[west, south], [east, north]] = bounds;
            adapter.value?.flyTo({ lng: (west + east) / 2, lat: (south + north) / 2 }, 13);
        }
    }
}

function renderRing(): void {
    adapter.value?.setBoundaries(ring.value.length >= 3
        ? {
            type: 'FeatureCollection',
            features: [{
                type: 'Feature',
                geometry: {
                    type: 'Polygon',
                    coordinates: [[...ring.value, ring.value[0]].map((p) => [p.lng, p.lat])],
                },
                // The boundary contract carries label metadata for the public
                // layers; a draft ring has none and renders fill/line only.
                properties: { slug: 'boundary-draft', name: '', type: null },
            }],
        }
        : { type: 'FeatureCollection', features: [] });
}

function startDrawing(): void {
    // A complex boundary is read-only here: replacing it goes through the
    // explicit intent + confirmation below, never through plain Draw.
    if (complexity.value === 'complex') {
        return;
    }

    beginDrawing();
}

function beginDrawing(): void {
    drawing.value = true;
    ring.value = [];
    renderRing();
}

/**
 * The two-step destructive path for complex geometry: a named intent
 * ("replace" or "clear"), then a confirmation. Cancel resets the prompt and
 * nothing else — no state change, no emit, the stored WKT untouched.
 */
function requestReplace(): void {
    confirming.value = 'replace';
}

function requestClear(): void {
    confirming.value = 'clear';
}

function cancelConfirmation(): void {
    confirming.value = null;
}

function confirmReplace(): void {
    confirming.value = null;
    beginDrawing();
}

function confirmClear(): void {
    confirming.value = null;
    doClear();
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
    // Same rule as Draw: clearing a complex boundary is confirmation-gated.
    if (complexity.value === 'complex') {
        return;
    }

    doClear();
}

function doClear(): void {
    drawing.value = false;
    ring.value = [];
    renderRing();
    emit('update:boundaryWkt', '');
}

watch(() => props.boundaryWkt, (value) => {
    if (drawing.value) return;
    syncBoundary(value, false);
});

/*
 * Coordinates typed into the sibling form fields must move the pin — the
 * binding is two-way, and a cleared pair removes the pin rather than
 * leaving a stale one claiming a location the form no longer states. The
 * echo guard: after this component's own emit the incoming props equal the
 * pin position at the same 7-decimal rounding, so setPin() is a no-op move.
 */
watch(() => [props.latitude, props.longitude] as const, ([lat, lng]) => {
    if (!adapter.value) return;

    if (lat === null || lng === null) {
        adapter.value.setPin?.(null);
        return;
    }

    placePin({ lng, lat });
});

onMounted(() => { void build(); });
onBeforeUnmount(() => { disposed = true; adapter.value?.destroy(); adapter.value = null; });
</script>

<template>
    <div class="space-y-3">
        <AppAlert v-if="failed" variant="warning">
            {{ t('geography.map.unavailable') }}
            <AppButton variant="secondary" size="sm" class="ms-3" :disabled="building" @click="retry">
                {{ t('app.actions.retry') }}
            </AppButton>
        </AppAlert>

        <div
            v-show="!failed"
            ref="container"
            class="w-full overflow-hidden rounded-card border border-line"
            :style="{ height }"
            role="application"
            :aria-label="t('geography.map.label')"
        />

        <!-- A stored boundary richer than this editor's single-ring model:
             rendered in full above, stated here, and only replaceable or
             clearable through an explicit intent plus confirmation. Cancel
             leaves everything exactly as it was and emits nothing. -->
        <AppAlert
            v-if="mode !== 'point' && complexity === 'complex' && !drawing"
            variant="warning"
            data-testid="boundary-complex-notice"
        >
            <template v-if="confirming === null">
                {{ t('geography.map.complex_notice') }}
                <span class="ms-3 inline-flex flex-wrap gap-2 align-middle">
                    <AppButton
                        variant="secondary" size="sm" data-testid="boundary-replace"
                        @click="requestReplace"
                    >
                        {{ t('geography.map.replace_boundary') }}
                    </AppButton>
                    <AppButton
                        variant="ghost" size="sm" data-testid="boundary-clear-complex"
                        @click="requestClear"
                    >
                        {{ t('geography.map.clear') }}
                    </AppButton>
                </span>
            </template>
            <template v-else>
                {{ confirming === 'replace'
                    ? t('geography.map.replace_confirm')
                    : t('geography.map.clear_confirm') }}
                <span class="ms-3 inline-flex flex-wrap gap-2 align-middle">
                    <AppButton
                        variant="danger" size="sm" data-testid="boundary-confirm"
                        @click="confirming === 'replace' ? confirmReplace() : confirmClear()"
                    >
                        {{ t('app.actions.confirm') }}
                    </AppButton>
                    <AppButton
                        variant="secondary" size="sm" data-testid="boundary-cancel"
                        @click="cancelConfirmation"
                    >
                        {{ t('app.actions.cancel') }}
                    </AppButton>
                </span>
            </template>
        </AppAlert>

        <div class="flex flex-wrap items-center gap-2">
            <template v-if="mode !== 'point'">
                <AppButton
                    v-if="!drawing && complexity !== 'complex'"
                    variant="secondary" size="sm" @click="startDrawing"
                >
                    {{ t('geography.map.draw_boundary') }}
                </AppButton>

                <template v-if="drawing">
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
