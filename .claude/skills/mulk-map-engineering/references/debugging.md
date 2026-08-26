# Mulk map debugging — deterministic checklists

Every checklist below is ordered by (a) what has actually broken in this
project before (`docs/MAP_PRODUCTION_AUDIT.md` RC1–RC9) and (b) cheapest check
first. Work top to bottom; do not skip steps because a later one "feels
likely" — several of these failure modes mask each other (RC8 hid behind
RC1/RC2 for an entire release).

## Blank map (grey/empty rectangle, no tiles)

1. **MapLibre CSS in the bundle** (RC1): `resources/js/app.ts` must import
   `maplibre-gl/dist/maplibre-gl.css`; `php scripts/frontend-guard.php` must
   pass (it greps built CSS for `maplibregl-map`). Without the CSS the canvas
   is mispositioned/unstyled on every surface at once.
2. **Style URL** (RC2): what does `page.props.map.style_url` actually resolve
   to? `MAPLIBRE_STYLE_URL` unset → code falls back to the same-origin
   `/map-styles/mulk-dark.json` MULK dark basemap (CARTO Dark Matter raster;
   the style document loads from this origin, the tiles from
   `basemaps.cartocdn.com`). In hermetic browser tests, external hosts are
   deliberately blanked — the style document still loads, the tiles never do —
   unless the spec serves a deterministic style or aborts the style route.
3. **Worker asset** (RC8): does `public/build/assets/` contain
   `maplibre-gl-worker-*.js`? Is the `?worker&url` import in `maplibre.ts`
   intact and is `setWorkerUrl()` called before Map construction? Symptom
   signature: map fires `load`, tiles may even paint, but every
   GeoJSON/vector source stays empty. Network tab shows a 404 for the worker.
4. **Container size** (RC3): was the map constructed inside a hidden
   (`v-show`, 0×0) container? The adapter's ResizeObserver should self-heal —
   confirm it is attached and `map.resize()` fires when the container becomes
   visible.
5. **WebGL**: check `ready()` rejection reason and console; headless Chromium
   needs no special flags in this project's suite, but a WebGL-less
   environment fails construction — that lands in the honest failure state,
   never a silent grey box.
6. Confirm the failure state honesty: if the provider genuinely failed, the
   localized failure message must show and the list must keep working (the
   list uses a fallback Erbil bbox — "the map failing must not take the data
   with it").

## Map incorrectly sized (too small, clipped, wrong after navigation)

1. Constructed while hidden → revealed: ResizeObserver present and observing
   the right container?
2. SPA (Inertia) navigation: was the old adapter destroyed
   (`onBeforeUnmount` → `destroy()`)? Two adapters on one container paint
   over each other (the Google destroy clears container innerHTML for exactly
   this reason).
3. Canvas CSS: `.maplibregl-canvas` must be `position:absolute`
   (pinned by `map-production.spec.ts` — a broken canvas position was a real
   regression).
4. Mobile tab switch: switching map/list must not rebuild; if size is wrong
   after a switch, the observer died or the container was replaced.

## Missing style / missing tiles

1. `MAPLIBRE_STYLE_URL` set in `.env` / SystemSettings? Provider resolution
   fell back (`provider_fallback_reason` in page props)?
2. Style host reachable from the browser context? (Hermetic tests blank it on
   purpose.)
3. Style loads but no streets at city zoom → demo style in play (RC2), not a
   bug in code.
4. `ready()` timing out (20s) → the style URL hangs; the pre-fix behavior
   (waiting forever on `load`) must not be reintroduced.

## Missing markers

1. **Worker first** (RC8) — the classic "map loads, markers never appear".
2. `/map/features` / `/invest/features` response: does the layer array
   actually contain rows? Check flags (`map.explorer`/`map.investment`),
   published status, coordinates present (coordinate-less projects are
   excluded by design), viewport bounds, `truncated` flag.
3. Layer filters: `unclustered` filters OUT points with `trendIcon`;
   trend points render via `trend-markers`. A wrong property drops the point
   from both.
4. Clustering: at low zoom points may be inside clusters (`clusterMaxZoom`
   15, radius 50) — zoom in or check the `clusters` layer before declaring
   markers missing.
5. `setPoints()` actually called after fetch (`syncSource()` in the page) and
   after `ready()` resolved?

## Broken / missing polygon

1. Zoom gates: area boundaries need zoom ≥ 11, project boundaries ≥ 14 —
   below that the server sends none by design.
2. Data exists? Fresh installs ship zero areas; unpublished areas contribute
   no boundary.
3. Coordinate order: GeoJSON is `[lng, lat]` — a swapped ring renders in the
   Indian Ocean without erroring. Check `ringsToCoordinates()` output.
4. Stored WKT validity: a malformed WKT skips that one boundary silently
   (by design) — validate the row's `boundary_wkt`.
5. Google provider only: winding/component handling in `lib/map/geojson.ts`
   (`normaliseComponent`) — same-winding holes render as filled shapes;
   flattened MultiPolygons drop all but the first ring.
6. Boundaries went through the clustered source? They must be in the
   separate `boundaries` source — the clusterer silently drops non-points.

## Wrong project opens on marker click

1. `feature.properties.id` on the clicked feature — is the id the project id
   the page expects (vs slug confusion)?
2. `queryRenderedFeatures` layer set: the click resolver only queries layers
   actually present; a new layer must be added to `presentLayers()`.
3. Clicked a cluster, not a point → expansion zoom, not selection.
4. Overlapping points: query returns topmost first — verify ordering
   assumptions.
5. On Google: `onMarkerClick` is never emitted (documented gap) — selection
   is list-only there; that is not a bug.

## Stale / wrong price on marker

1. Label is caller-formatted: check the page's label building (currency comes
   from the price row itself — no conversion exists anywhere).
2. `withPriceTrends` picks the newest `project_prices` row by
   `effective_date` then `id` — verify the expected row actually is newest.
3. `requiresQualifier()` (asking prices) — qualifier missing is a display
   contract issue, not data.
4. Null `price_from` must render as "no price", never 0.

## Fake trend (−100%, absurd %, arrow with no basis)

The fabricated-trend bug class is fixed and pinned — if you see it again, the
comparability gate regressed. Check in order:

1. Null handling: a null `price_from` on either side must yield `unknown`
   (`(float) null === 0.0` was the −100% bug).
2. Currency match: IQD previous vs USD current must yield `unknown`.
3. price_type match: rent vs sale must never compare.
4. Single observation must be `unknown`, never `flat`.
5. Client: `normaliseTrend()` must degrade null/garbage to `unknown`;
   `trendHasClaim()` must gate every arrow/percent badge.
6. Run `php artisan test --filter MapTrendSemanticsTest` — if it passes but
   the UI shows a fake trend, the defect is in a page bypassing `trend.ts`.

## Worker failure

1. Import form: `maplibre-gl/dist/maplibre-gl-worker.mjs?worker&url` — plain
   `?url` breaks one request later (`maplibre-gl-shared.mjs`).
2. `setWorkerUrl()` before first `Map` construction (v6 creates the pool in
   the constructor).
3. Built assets contain `maplibre-gl-worker-*.js` (`MapRtlTextPluginTest`
   asserts this).
4. Symptom recap: no worker = `load` fires, sources stay empty, no exception.

## RTL / overlay problems (ckb, ar)

1. Plugin status: `getRTLTextPluginStatus()` should be `loaded`/`deferred` —
   `error` means the asset 404'd; double registration (`setRTLTextPlugin`
   called when status ≠ `unavailable`) rejects — the guard must stay.
2. Plugin must be served same-origin from `/build/assets/` (never CDN) —
   pinned by `map-rtl.spec.ts`.
3. Isolated/disjointed Arabic-script glyphs on the canvas = plugin not
   loaded; page-chrome RTL issues = `postcss-rtlcss`/`dir` attr instead.
4. Numbers: Latin digits always (`formatNumber` en-GB) wrapped in `.numeral`
   for bidi isolation — mixed-direction garbage around prices means a raw
   interpolation bypassed it.
5. Overlays/controls on the wrong side or covering content in RTL: check the
   combined-mode `postcss-rtlcss` output and the floating card positioning at
   360×800/390×844.
6. MapLibre controls on the wrong side, or a DOM marker/pin off its
   coordinates, in RTL only (Phase 6 contract): the vendor stylesheet must be
   direction-neutral — no `[dir]`-scoped rule may target `.maplibregl-*`
   (rtlcss is scoped away from `node_modules/maplibre-gl` in
   `postcss.config.js`; an unscoped rtlcss once emitted
   `[dir=rtl] .maplibregl-marker { right: 0 }` and displaced every pin).
   Corner choice is the adapter's: `maplibre.ts` resolves zoom to top-END,
   scale to bottom-START, attribution to bottom-END from the container's
   computed direction. `map-rtl.spec.ts` pins vendor-CSS neutrality, corner
   membership + rendered side, chrome collision, and marker placement for
   ckb/ar/en — start there.

## Mobile touch failure

1. Tab switch rebuilt the map? It must only toggle `hidden` classes.
2. An overlay (veil, card, vignette) intercepting pointer events? Check
   z-index/`pointer-events` on `.mh-invest-*` layers.
3. Touch targets: harness `expectTouchTargets` standard.
4. Gestures are MapLibre/Google built-ins — no custom touch code exists; if
   gestures die, suspect the container/overlay, not gesture code.

## Browser console errors

1. The diagnostics fixture fails tests on ANY console error / Vue warning /
   page error — treat every one as a defect, not noise.
2. Frequent real causes seen here: worker 404 (RC8), RTL plugin double
   registration, `gm_authFailure` (Google key invalid → fallback should
   engage), missing Inertia page component (frontend-guard catches).
3. Reproduce with the direct-Playwright script (`references/testing.md` §2)
   capturing `console` and `requestfailed` events across all three locales —
   some errors only fire on RTL locales or after SPA navigation.
