<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { createMapAdapter } from '@/lib/map';
import {
    addHole,
    addVertex,
    fromWkt,
    isUsableRing,
    moveVertex,
    removeComponent,
    removeHole,
    removeVertex,
    toWkt,
    canStartHole,
    moveHoleVertex,
    parseTypedPoint,
    canRemoveHoleVertex,
    validateGeometry,
    removeHoleVertex,
    toRenderedFeatures,
    toggleHoleSelection,
    type Component,
    type HoleSelection,
} from '@/lib/wizard/geometry';
import type { LatLng, MapAdapter } from '@/lib/map';
import AppButton from '@/Components/ui/AppButton.vue';
import AppAlert from '@/Components/ui/AppAlert.vue';
import { t } from '@/lib/i18n';

/*
 * Point selection and boundary drawing on one map (spec 12.1).
 *
 * Built on the existing MapAdapter rather than a second map stack: the
 * Explorer already resolves MapLibre-or-Google, handles the key-failure
 * lifecycle and cleans up listeners, and a parallel implementation would drift
 * from it the first time either changed.
 *
 * DRAWING IS DELIBERATELY SIMPLE. Vertices are appended by clicking, the last
 * can be undone, and a vertex can be dragged. There is no snapping, no
 * boolean geometry and no hole cutting — this is where somebody outlines a
 * compound in Erbil, not a CAD tool, and every extra mode is another way to
 * produce geometry the validator will reject.
 *
 * The component never repairs input. It reports what is wrong and lets the
 * person fix it, because silently closing a ring stores a shape they did not
 * draw.
 */
const props = withDefaults(defineProps<{
    latitude?: number | null;
    longitude?: number | null;
    /** WKT. POLYGON or MULTIPOLYGON. */
    boundary?: string | null;
    styleUrl?: string | null;
    googleKey?: string | null;
    disabled?: boolean;
    /** Erbil, as a sensible default view. */
    centre?: LatLng;
    provider?: string;
}>(), {
    latitude: null,
    longitude: null,
    boundary: null,
    styleUrl: null,
    googleKey: null,
    disabled: false,
    centre: () => ({ lat: 36.19, lng: 44.009 }),
    provider: 'maplibre',
});

const emit = defineEmits<{
    /*
     * The point is ONE value. Emitting latitude and longitude separately meant
     * the parent saw an intermediate state with one new coordinate and one old
     * — and fired a request for a location that never existed.
     */
    (e: 'update:point', value: { lat: number; lng: number } | null): void;
    (e: 'update:boundary', value: string | null): void;
}>();

type Mode = 'point' | 'draw' | 'edit';

const container = ref<HTMLElement | null>(null);
const adapter = ref<MapAdapter | null>(null);

/** Loading, failed and degraded are distinct states and look different. */
const status = ref<'loading' | 'ready' | 'failed'>('loading');
const failure = ref<string | null>(null);

const mode = ref<Mode>('point');

/** Structural geometry: each component is an exterior plus its holes. */
const components = ref<Component[]>([]);

/** Which component is being drawn or edited. */
const activeComponent = ref(0);
const activeVertex = ref<number | null>(null);

/** Drawing a hole rather than an exterior. */
const drawingHole = ref(false);
const holeError = ref(false);
const holeMinimum = ref(false);
const problems = ref<ReturnType<typeof validateGeometry>>([]);
/**
 * The open hole, as ONE value.
 *
 * Two independent refs meant the component index could be updated before the
 * hole index was compared against it.
 */
const selection = ref<HoleSelection | null>(null);

const holeVertices = ref<LatLng[]>([]);

const vertices = computed(() => components.value[activeComponent.value]?.exterior ?? []);

const point = computed<LatLng | null>(() =>
    props.latitude !== null && props.longitude !== null
        ? { lat: props.latitude, lng: props.longitude }
        : null,
);

const canClose = computed(() => isUsableRing(vertices.value));
const hasBoundary = computed(() => components.value.length > 0);

/* ------------------------------------------------------------------ WKT */

/*
 * Serialisation lives in the production module, which the test suite imports
 * too. The previous inline copy flattened holes into separate filled shapes,
 * and the tests copied the same mistake so nothing caught it.
 */
function emitBoundary(): void {
    /*
     * Invalid geometry BLOCKS the emission rather than being quietly
     * corrected. The local components are untouched, so the person can repair
     * or delete the offending ring themselves.
     */
    problems.value = validateGeometry(components.value);

    if (problems.value.length > 0) {
        render();

        return;
    }

    const wkt = toWkt(components.value);

    // Recorded so the watcher can tell our own echo from a genuine external
    // change and avoid an update loop.
    lastEmitted.value = wkt;
    emit('update:boundary', wkt);
    render();
}

/* --------------------------------------------------------------- render */

function render(): void {
    const map = adapter.value;

    if (map === null) {
        return;
    }

    /*
     * Exteriors and holes are drawn as separate outlines so the editor can see
     * both. The map layer has no notion of a void, but the GEOMETRY does — and
     * that distinction is preserved in the WKT regardless of how it is drawn.
     */
    /*
     * ONE component renders as ONE Polygon whose ring list is exterior first,
     * then holes. GeoJSON gives that meaning natively — the renderer punches
     * the holes out.
     *
     * The previous version pushed every ring as its own filled feature, so a
     * courtyard drew as a second building on top of the first. The data was
     * already structural; only the rendering had lost it.
     */
    const closeRing = (ring: LatLng[]): [number, number][] =>
        [...ring, ring[0]].map((v): [number, number] => [v.lng, v.lat]);

    const features = toRenderedFeatures(
        components.value,
        (index) => t('projects.wizard.creation.map_ring_n', { n: index + 1 }),
    ).map((feature, index) => ({
        type: 'Feature' as const,
        properties: { slug: `component-${index}`, name: feature.label, type: null },
        // Exterior first, then holes: GeoJSON punches the voids out.
        geometry: { type: 'Polygon' as const, coordinates: feature.rings },
    }));

    // The hole being drawn is shown as its own outline until it is applied —
    // it is not yet part of any component.
    if (holeVertices.value.length >= 3) {
        features.push({
            type: 'Feature' as const,
            properties: {
                slug: 'hole-in-progress',
                name: t('projects.wizard.creation.map_hole_drawing'),
                type: null,
            },
            geometry: {
                type: 'Polygon' as const,
                coordinates: [closeRing(holeVertices.value)],
            },
        });
    }

    map.setBoundaries({ type: 'FeatureCollection', features });

    /*
     * Markers follow whatever is being edited: a hole under edit, a hole being
     * drawn, or the exterior. Without this the hole's vertices are invisible
     * and cannot be aimed at.
     */
    const editingHole = selection.value !== null
        ? components.value[selection.value.componentIndex]?.holes[selection.value.holeIndex] ?? []
        : null;

    const active = drawingHole.value
        ? holeVertices.value
        : (editingHole ?? vertices.value);

    const markers: Array<{ lat: number; lng: number; title: string; colour: string }> = active.map(
        (v, index) => ({
            lat: v.lat,
            lng: v.lng,
            title: String(index + 1),
            colour: activeVertex.value === index ? '#c2410c' : '#0f766e',
        }),
    );

    if (point.value !== null && mode.value === 'point') {
        markers.push({
            lat: point.value.lat,
            lng: point.value.lng,
            title: t('projects.wizard.creation.map_mode_point'),
            colour: '#1d4ed8',
        });
    }

    map.setPoints(markers);
}

/* ---------------------------------------------------------- interaction */

/**
 * Typed coordinates, still emitted as one point.
 *
 * A half-typed pair emits null rather than a point built from one new number
 * and one stale one — the parent then clears its suggestion instead of asking
 * about a location nobody is at.
 */
/**
 * A LOCAL buffer for the two typed inputs.
 *
 * Reconstructing the other coordinate from props meant each keystroke rebuilt
 * a pair from one fresh value and one committed one — so typing a latitude
 * emitted a point using the OLD longitude, which is a location nobody is at.
 *
 * The buffer holds what the person is currently typing. Nothing is emitted
 * until both halves of that same editing state parse.
 */
const typed = ref<{ lat: string; lng: string }>({
    lat: props.latitude === null ? '' : String(props.latitude),
    lng: props.longitude === null ? '' : String(props.longitude),
});

function commitTyped(): void {
    const lat = typed.value.lat.trim();
    const lng = typed.value.lng.trim();

    // Clearing either half clears the POINT, deliberately: half a coordinate
    // pair is not a location, and keeping the other half invites a request
    // built from a stale partner.
    const parsed = parseTypedPoint(lat, lng);

    // undefined means "not yet usable" — say nothing rather than guess.
    if (parsed !== undefined) {
        emit('update:point', parsed);
    }
}

function ensureComponent(): void {
    if (components.value.length === 0) {
        components.value = [{ exterior: [], holes: [] }];
        activeComponent.value = 0;
    }
}

function handleMapClick(position: LatLng): void {
    if (props.disabled) {
        return;
    }

    if (mode.value === 'point') {
        emit('update:point', {
            lat: Number(position.lat.toFixed(6)),
            lng: Number(position.lng.toFixed(6)),
        });
        render();

        return;
    }

    if (mode.value === 'draw') {
        if (drawingHole.value) {
            holeVertices.value = [...holeVertices.value, position];
            render();

            return;
        }

        ensureComponent();
        components.value = addVertex(components.value, activeComponent.value, position);
        emitBoundary();

        return;
    }

    // One source of truth: the structured selection. Reading activeComponent
    // here while rendering from selection.componentIndex is how a click landed
    // on the wrong component.
    if (mode.value === 'edit' && activeVertex.value !== null && selection.value !== null) {
        editHoleVertex(
            selection.value.componentIndex,
            selection.value.holeIndex,
            activeVertex.value,
            position,
        );
        activeVertex.value = null;

        return;
    }

    if (mode.value === 'edit' && activeVertex.value !== null) {
        // In edit mode a click means "put it here".
        components.value = moveVertex(
            components.value,
            activeComponent.value,
            activeVertex.value,
            position,
        );
        activeVertex.value = null;
        emitBoundary();
    }
}

function undoVertex(): void {
    if (drawingHole.value) {
        holeVertices.value = holeVertices.value.slice(0, -1);
        render();

        return;
    }

    components.value = removeVertex(
        components.value,
        activeComponent.value,
        vertices.value.length - 1,
    );
    emitBoundary();
}

/** Finish this component and start another — a MULTIPOLYGON part. */
function finishComponent(): void {
    if (!canClose.value) {
        return;
    }

    components.value = [...components.value, { exterior: [], holes: [] }];
    activeComponent.value = components.value.length - 1;
    emitBoundary();
}

/**
 * Begin a hole in the ACTIVE component.
 *
 * Refused when that component has no usable exterior: a void inside nothing is
 * not geometry, and accepting it would produce WKT the server rejects after
 * the person has done the work.
 */
function startHole(): boolean {
    if (! canStartHole(components.value[activeComponent.value])) {
        holeError.value = true;

        return false;
    }

    holeError.value = false;
    drawingHole.value = true;
    holeVertices.value = [];
    mode.value = 'draw';

    return true;
}

function finishHole(): void {
    if (!isUsableRing(holeVertices.value)) {
        return;
    }

    components.value = addHole(components.value, activeComponent.value, holeVertices.value);
    holeVertices.value = [];
    drawingHole.value = false;
    emitBoundary();
}

function deleteComponent(index: number): void {
    // The selection cannot outlive the component it points into.
    if (selection.value?.componentIndex === index) {
        selection.value = null;
        activeVertex.value = null;
    }

    components.value = removeComponent(components.value, index);
    activeComponent.value = Math.max(0, Math.min(activeComponent.value, components.value.length - 1));
    emitBoundary();
}

/** Deleting a courtyard must not delete the building around it. */
function deleteHole(index: number, holeIndex: number): void {
    if (selection.value?.componentIndex === index && selection.value.holeIndex === holeIndex) {
        selection.value = null;
        activeVertex.value = null;
    }

    components.value = removeHole(components.value, index, holeIndex);
    emitBoundary();
}

function clearAll(): void {
    components.value = [];
    holeVertices.value = [];
    drawingHole.value = false;
    activeVertex.value = null;
    activeComponent.value = 0;
    emit('update:boundary', null);
    render();
}

function selectVertex(index: number): void {
    selection.value = null;
    activeVertex.value = activeVertex.value === index ? null : index;
    mode.value = 'edit';
}

function dropVertex(index: number): void {
    components.value = removeVertex(components.value, activeComponent.value, index);
    activeVertex.value = null;
    emitBoundary();
}

/** Edit a vertex of a COMPLETED hole, not only the exterior. */
function editHoleVertex(index: number, holeIndex: number, vertexIndex: number, vertex: LatLng): void {
    components.value = moveHoleVertex(components.value, index, holeIndex, vertexIndex, vertex);
    emitBoundary();
}

/**
 * Select a hole vertex for placement.
 *
 * The next map click moves it, using the same select-then-place interaction
 * the exterior uses — one gesture to learn, not two.
 */
/**
 * Open a hole for vertex editing.
 *
 * Selecting index 0 only, as the first attempt did, made exactly one vertex of
 * each hole editable — which is not editing a hole, it is nudging a corner.
 * This exposes the hole's vertex list so any of them can be chosen.
 */
function openHole(index: number, holeIndex: number): void {
    /*
     * Compare BEFORE mutating.
     *
     * The previous version assigned activeComponent first and then tested
     * `activeComponent.value === index`, which was therefore always true — so
     * switching from hole 0 of component A to hole 0 of component B closed the
     * editor instead of opening B. One structured selection removes the chance
     * of reading a value that has already moved.
     */
    selection.value = toggleHoleSelection(selection.value, index, holeIndex);
    activeComponent.value = index;
    activeVertex.value = null;
    mode.value = 'edit';
    render();
}

/** Select a specific hole vertex; the next map click places it. */
function selectHoleVertex(vertexIndex: number): void {
    activeVertex.value = activeVertex.value === vertexIndex ? null : vertexIndex;
    mode.value = 'edit';
    render();
}

/** Remove one vertex from a hole, keeping the rest of the ring. */
function dropHoleVertex(index: number, holeIndex: number, vertexIndex: number): void {
    // A hole cannot shrink below a usable ring; say so rather than dropping it.
    if (! canRemoveHoleVertex(components.value, index, holeIndex, vertexIndex)) {
        holeMinimum.value = true;

        return;
    }

    holeMinimum.value = false;
    components.value = removeHoleVertex(components.value, index, holeIndex, vertexIndex);
    activeVertex.value = null;
    emitBoundary();
}

function editComponent(index: number): void {
    activeComponent.value = index;
    // Switching component CLOSES any open hole. Leaving it open rendered one
    // component's hole while map clicks mutated another's.
    selection.value = null;
    activeVertex.value = null;
    mode.value = 'edit';
    render();
}

/* ---------------------------------------------------------------- setup */

/*
 * Map construction is a RETRYABLE attempt, not a one-shot mount step.
 *
 * The previous version awaited createMapAdapter() and ready() bare inside
 * onMounted: a construction throw or a readiness rejection (the adapter's
 * bounded deadline) was an unhandled rejection, status stayed 'loading'
 * forever, and the failed-state alert below was unreachable for exactly the
 * failures it exists for. Every other map consumer already wrapped this
 * sequence; now this one does too, with a retry.
 *
 * The generation token makes attempts strictly ordered: an obsolete attempt
 * — superseded by a retry, or outliving the component — may not install its
 * adapter, flip status, or touch state. `building` keeps retry clicks from
 * ever constructing two maps at once.
 */
const building = ref(false);
let attempt = 0;
let disposed = false;

async function buildMap(): Promise<void> {
    if (container.value === null || building.value || disposed) {
        return;
    }

    building.value = true;
    status.value = 'loading';
    const token = ++attempt;

    // A retry replaces the failed instance. The old adapter goes first so two
    // maps never share the container.
    adapter.value?.destroy();
    adapter.value = null;

    try {
        // Provider comes from shared config; MapLibre is the default and the
        // fallback, so a missing Google key degrades rather than breaks.
        const result = await createMapAdapter(props.provider, {
            container: container.value,
            styleUrl: props.styleUrl,
            googleKey: props.googleKey,
            centre: point.value ?? props.centre,
            zoom: point.value === null ? 11 : 15,
            events: {
                onMoveEnd: () => {},
                onClick: handleMapClick,
                /*
                 * The numbered vertex/hole handles render through the
                 * adapter's shared point layers, and the adapter claims
                 * marker hits — absorbing them before onClick — only for
                 * surfaces that declare onMarkerClick. This picker's handles
                 * ARE its markers, and a click landing on one must keep
                 * being absorbed: in draw mode it would otherwise append a
                 * coincident duplicate vertex. The handles carry no feature
                 * id, so this handler never actually fires — declaring it
                 * exists to keep the claim (and the pre-existing handle
                 * cursor) exactly as before.
                 */
                onMarkerClick: () => {},
                // A provider can fail minutes after construction — a revoked
                // key, a tile 403. Saying so beats a map that quietly stops
                // working. Gated by token so a superseded adapter's late
                // error cannot mark a newer, healthy attempt as failed.
                onError: () => {
                    if (token === attempt) {
                        status.value = 'failed';
                    }
                },
            },
        });

        if (disposed || token !== attempt) {
            result.adapter.destroy();

            return;
        }

        adapter.value = result.adapter;

        /*
         * A fallback is not a failure — MapLibre standing in for Google still
         * renders a usable map — but it IS a fact worth showing, because the
         * imagery differs from what the person may expect.
         */
        failure.value = result.fallbackReason;
        await result.adapter.ready();

        if (disposed || token !== attempt) {
            return;
        }

        status.value = 'ready';
        // Render the CURRENT in-memory geometry — components, holes and the
        // point survive a provider failure; only the map is rebuilt.
        render();
    } catch {
        if (!disposed && token === attempt) {
            status.value = 'failed';
        }
    } finally {
        if (token === attempt) {
            building.value = false;
        }
    }
}

function retryMap(): void {
    void buildMap();
}

onMounted(() => {
    // Structural parse: holes stay holes rather than becoming separate
    // shapes. Parsed ONCE at mount — a retry rebuilds only the map and must
    // not reparse props.boundary over unsaved edits.
    components.value = fromWkt(props.boundary ?? null);
    activeComponent.value = Math.max(0, components.value.length - 1);

    void buildMap();
});

onBeforeUnmount(() => {
    disposed = true;
    // Invalidate any in-flight attempt so it cannot resurrect an adapter.
    attempt++;
    adapter.value?.destroy();
    adapter.value = null;
});

watch(() => [props.latitude, props.longitude], ([lat, lng]) => {
    // Keep the buffer in step with an external change (draft recovery, a map
    // click) without disturbing what somebody is mid-way through typing.
    if (document.activeElement?.tagName !== 'INPUT') {
        typed.value = {
            lat: lat === null ? '' : String(lat),
            lng: lng === null ? '' : String(lng),
        };
    }

    render();
});

/*
 * EXTERNAL boundary changes — draft recovery, a reset, a server correction.
 *
 * Parsing only in onMounted meant the picker showed the geometry it was born
 * with forever. Re-parsing on every change would fight the person's own edits,
 * so the incoming value is compared against what this component last emitted:
 * if they match, it is our own echo and is ignored.
 */
const lastEmitted = ref<string | null>(null);

watch(() => props.boundary, (incoming) => {
    if (incoming === lastEmitted.value) {
        return;   // our own change coming back
    }

    components.value = fromWkt(incoming ?? null);
    activeComponent.value = Math.max(0, components.value.length - 1);
    selection.value = null;
    activeVertex.value = null;
    holeVertices.value = [];
    drawingHole.value = false;
    render();
});
</script>

<template>
    <div class="space-y-3">
        <!-- Point and boundary are different intentions; one click cannot
             serve both. -->
        <div class="flex flex-wrap gap-2">
            <AppButton
                type="button" size="sm"
                :variant="mode === 'point' ? 'primary' : 'secondary'"
                :disabled="disabled" @click="mode = 'point'"
            >
                {{ t('projects.wizard.creation.map_mode_point') }}
            </AppButton>

            <AppButton
                type="button" size="sm"
                :variant="mode === 'draw' ? 'primary' : 'secondary'"
                :disabled="disabled" @click="mode = 'draw'; drawingHole = false"
            >
                {{ t('projects.wizard.creation.map_mode_draw') }}
            </AppButton>

            <AppButton
                v-if="hasBoundary && !drawingHole"
                type="button" size="sm" variant="secondary"
                :disabled="disabled" @click="startHole()"
            >
                {{ t('projects.wizard.creation.map_draw_hole') }}
            </AppButton>

            <AppButton
                v-if="drawingHole"
                type="button" size="sm" variant="secondary"
                :disabled="disabled || holeVertices.length < 3" @click="finishHole"
            >
                {{ t('projects.wizard.creation.map_finish_hole') }}
            </AppButton>

            <AppButton
                v-if="(drawingHole ? holeVertices.length : vertices.length) > 0"
                type="button" size="sm" variant="secondary"
                :disabled="disabled" @click="undoVertex"
            >
                {{ t('projects.wizard.creation.map_undo') }}
            </AppButton>

            <AppButton
                v-if="canClose && !drawingHole"
                type="button" size="sm" variant="secondary"
                :disabled="disabled" @click="finishComponent"
            >
                {{ t('projects.wizard.creation.map_finish_ring') }}
            </AppButton>

            <AppButton
                v-if="hasBoundary"
                type="button" size="sm" variant="danger"
                :disabled="disabled" @click="clearAll"
            >
                {{ t('projects.wizard.creation.map_clear') }}
            </AppButton>
        </div>

        <!-- Named precisely: which component, which hole, and why. -->
        <div v-if="problems.length > 0" class="space-y-1">
            <AppAlert
                v-for="(problem, i) in problems"
                :key="i"
                variant="danger"
                :message="t(`projects.wizard.creation.geometry_${problem.reason}`, {
                    component: problem.componentIndex + 1,
                    hole: problem.holeIndex === null ? 0 : problem.holeIndex + 1,
                })"
            />
        </div>

        <AppAlert
            v-if="holeMinimum"
            variant="warning"
            :message="t('projects.wizard.creation.map_hole_minimum')"
        />

        <AppAlert
            v-if="holeError"
            variant="warning"
            :message="t('projects.wizard.creation.map_hole_needs_component')"
        />

        <p class="text-xs text-ink-faint">
            {{ mode === 'point'
                ? t('projects.wizard.creation.map_hint_point')
                : (drawingHole
                    ? t('projects.wizard.creation.map_hint_hole')
                    : t('projects.wizard.creation.map_hint_draw')) }}
        </p>

        <p v-if="status === 'loading'" class="text-sm text-ink-muted">
            {{ t('projects.wizard.creation.map_loading') }}
        </p>

        <AppAlert v-if="status === 'failed'" variant="warning">
            {{ t('projects.wizard.creation.map_failed') }}
            <AppButton
                variant="secondary" size="sm" class="ms-3"
                :disabled="building" @click="retryMap"
            >
                {{ t('app.actions.retry') }}
            </AppButton>
        </AppAlert>

        <!-- A fallback is not a failure, but it IS a fact: the imagery differs
             from what the person may expect. -->
        <AppAlert
            v-else-if="failure"
            variant="info"
            :message="t('projects.wizard.creation.map_fallback')"
        />

        <div
            ref="container"
            class="h-80 w-full overflow-hidden rounded-card border border-line"
            :class="{ 'opacity-50': disabled }"
            role="application"
            :aria-label="t('projects.wizard.creation.map_label')"
        />

        <!-- Coordinates stay typeable: a map is not usable by everyone, and a
             surveyor with exact figures should not have to click for them. -->
        <div class="grid gap-3 sm:grid-cols-2">
            <label class="block text-sm">
                <span class="text-ink-muted">{{ t('projects.fields.latitude') }}</span>
                <input
                    :value="typed.lat" type="number" step="0.000001" :disabled="disabled"
                    class="mt-1 w-full rounded-card border border-line px-3 py-2"
                    @input="typed.lat = ($event.target as HTMLInputElement).value; commitTyped()"
                >
            </label>

            <label class="block text-sm">
                <span class="text-ink-muted">{{ t('projects.fields.longitude') }}</span>
                <input
                    :value="typed.lng" type="number" step="0.000001" :disabled="disabled"
                    class="mt-1 w-full rounded-card border border-line px-3 py-2"
                    @input="typed.lng = ($event.target as HTMLInputElement).value; commitTyped()"
                >
            </label>
        </div>

        <!-- Vertex list: the editing surface. Dragging alone is unusable on a
             phone and inaccessible without a pointer. -->
        <div v-if="vertices.length > 0 && !drawingHole" class="space-y-1">
            <p class="text-xs font-medium text-ink-muted">
                {{ t('projects.wizard.creation.map_vertices') }} ({{ vertices.length }})
            </p>

            <ul class="max-h-40 space-y-1 overflow-y-auto">
                <li
                    v-for="(vertex, index) in vertices" :key="index"
                    class="flex items-center justify-between gap-2 rounded-card border border-line px-2 py-1 text-xs"
                    :class="{ 'border-accent': activeVertex === index }"
                >
                    <span class="font-mono">{{ vertex.lat.toFixed(5) }}, {{ vertex.lng.toFixed(5) }}</span>
                    <span class="flex gap-1">
                        <button
                            type="button" class="text-ink-muted underline" :disabled="disabled"
                            @click="selectVertex(index)"
                        >{{ activeVertex === index
                            ? t('projects.wizard.creation.map_click_to_place')
                            : t('projects.wizard.creation.map_move') }}</button>
                        <button
                            type="button" class="text-danger underline" :disabled="disabled"
                            @click="dropVertex(index)"
                        >{{ t('app.actions.delete') }}</button>
                    </span>
                </li>
            </ul>
        </div>

        <!-- Components and their holes, each individually editable. Deleting a
             courtyard must not delete the building around it. -->
        <div v-if="components.length > 0" class="space-y-1">
            <p class="text-xs font-medium text-ink-muted">
                {{ t('projects.wizard.creation.map_rings') }} ({{ components.length }})
            </p>

            <ul class="space-y-1">
                <li
                    v-for="(component, index) in components" :key="index"
                    class="rounded-card border border-line px-2 py-1 text-xs"
                    :class="{ 'border-accent': activeComponent === index }"
                >
                    <div class="flex items-center justify-between">
                        <span>
                            {{ t('projects.wizard.creation.map_ring_n', { n: index + 1 }) }}
                            — {{ component.exterior.length }}
                        </span>
                        <span class="flex gap-2">
                            <button
                                type="button" class="text-ink-muted underline" :disabled="disabled"
                                @click="editComponent(index)"
                            >{{ t('app.actions.edit') }}</button>
                            <button
                                type="button" class="text-danger underline" :disabled="disabled"
                                @click="deleteComponent(index)"
                            >{{ t('app.actions.delete') }}</button>
                        </span>
                    </div>

                    <ul v-if="component.holes.length > 0" class="mt-1 space-y-1 ps-3">
                        <li
                            v-for="(componentHole, holeIndex) in component.holes" :key="holeIndex"
                            class="flex items-center justify-between text-ink-faint"
                        >
                            <span>
                                {{ t('projects.wizard.creation.map_hole_n', { n: holeIndex + 1 }) }}
                                — {{ componentHole.length }}
                            </span>
                            <span class="flex gap-2">
                                <!-- Hole vertices are editable, not only
                                     deletable: a courtyard drawn slightly
                                     wrong should be corrected, not redrawn. -->
                                <button
                                    type="button" class="text-ink-muted underline" :disabled="disabled"
                                    @click="openHole(index, holeIndex)"
                                >{{ selection?.componentIndex === index && selection?.holeIndex === holeIndex
                                    ? t('app.actions.close')
                                    : t('app.actions.edit') }}</button>
                                <button
                                    type="button" class="text-danger underline" :disabled="disabled"
                                    @click="deleteHole(index, holeIndex)"
                                >{{ t('app.actions.delete') }}</button>
                            </span>
                            <!-- Every vertex of the open hole, individually
                                 selectable and removable. -->
                            <ul
                                v-if="selection?.componentIndex === index && selection?.holeIndex === holeIndex"
                                class="mt-1 space-y-1 ps-3"
                            >
                                <li
                                    v-for="(holeVertex, vertexIndex) in componentHole"
                                    :key="vertexIndex"
                                    class="flex items-center justify-between gap-2"
                                    :class="{ 'text-accent': activeVertex === vertexIndex }"
                                >
                                    <span class="font-mono">
                                        {{ holeVertex.lat.toFixed(5) }}, {{ holeVertex.lng.toFixed(5) }}
                                    </span>
                                    <span class="flex gap-2">
                                        <button
                                            type="button" class="underline" :disabled="disabled"
                                            @click="selectHoleVertex(vertexIndex)"
                                        >{{ activeVertex === vertexIndex
                                            ? t('projects.wizard.creation.map_click_to_place')
                                            : t('projects.wizard.creation.map_move') }}</button>
                                        <button
                                            type="button" class="text-danger underline" :disabled="disabled"
                                            @click="dropHoleVertex(index, holeIndex, vertexIndex)"
                                        >{{ t('app.actions.delete') }}</button>
                                    </span>
                                </li>
                            </ul>
                        </li>
                    </ul>
                </li>
            </ul>
        </div>
    </div>
</template>
