---
name: mulk-map-engineering
description: >-
  Engineering contract for this repo's map/geospatial system: the MapLibre
  adapter stack, map explorer (/map), investment map (/invest), homepage
  pricing map, admin map pickers, area polygons/boundaries, project markers,
  price trends on the map, GeoJSON/WKT geometry, Erbil coordinates, and map
  browser testing. Use whenever a task touches ANY map surface or geospatial
  data — blank/broken/mis-sized maps, markers not rendering or clickable,
  wrong/fabricated price trends on markers, missing or invalid area polygons,
  polygon drawing/editing, MapPicker components, MapLibre workers or RTL
  text, tiles/styles/providers, clustering, geolocation, map mobile/RTL
  behavior, MapExplorerController or the Geography module,
  latitude/longitude/boundary_wkt data, or map Playwright tests — even if
  "map" is never said (e.g. "area boundaries missing", "invest page markers",
  "polygon editor broken", "Erbil pin wrong place"). Not for non-map features
  (auth, accounting, price tables on non-map pages).
---

# Mulk Map Engineering

The engineering contract for this repository's map/geospatial system. Every
rule below was derived from the actual codebase, its documented production
incidents (`docs/MAP_PRODUCTION_AUDIT.md` RC1–RC9), and the regression tests
that pin their fixes. Follow this file instead of generic MapLibre knowledge —
this project has already been burned by most of the generic mistakes, and the
fixes are load-bearing.

Deeper detail lives in three reference files — read them when the task touches
their domain:

- `references/architecture.md` — full file→responsibility map, API endpoint
  table, data shapes, settings pipeline. Read before touching unfamiliar map
  code.
- `references/testing.md` — every test suite, how to run each, and the direct
  Playwright script pattern for when MCP browser tools are absent. Read before
  verifying any map change.
- `references/debugging.md` — deterministic symptom→cause checklists mapped to
  the RC1–RC9 incident history. Read when diagnosing a map bug.

Authoritative historical records in the repo itself: `docs/MAP_PRODUCTION_AUDIT.md`
(root causes), `docs/MAP_PRODUCTION_COMPLETION.md` (repairs + production config),
`docs/MAP_PICKER_SLICE.md` (picker origins).

## A. Technology contract

- **Library**: `maplibre-gl` **6.0.0** (locked) + `@mapbox/mapbox-gl-rtl-text`
  **0.4.0** (exact pin — `MapRtlTextPluginTest` fails on a semver range).
  Google Maps is an **optional** secondary provider (no npm package; ambient
  types hand-declared in `google.ts`). There is no `supercluster`, no draw
  library, no tile-format package — do not add one casually.
- **Stack**: Laravel (modular, `app/Modules/*`) + Inertia + Vue 3 + Vite.
  Provider and style URL reach every page via Inertia shared props
  `page.props.map = {provider, style_url}` (`HandleInertiaRequests`). The
  Google API key is **never** in shared props — only
  `MapExplorerController::providerPageProps()` emits it, per-request, and only
  when Google actually resolved.
- **The authoritative implementation is `resources/js/lib/map/`**:
  `createMapAdapter(provider, options)` in `index.ts` is the ONLY way to
  construct a map. `MapLibreAdapter` (`maplibre.ts`) has a private constructor
  behind a static `create()`. Never call `new maplibre.Map()` outside
  `maplibre.ts` — that exact duplication was audit root-cause RC4 and has been
  eliminated once already.
- **Two admin MapPickers exist deliberately — both are live, neither is dead
  code**:
  - `resources/js/Components/MapPicker.vue` — simple point + single-ring
    polygon picker; used by `Admin/{Areas,Offers,Places,Projects}/Form.vue`.
  - `resources/js/Components/map/MapPicker.vue` — MultiPolygon-with-holes
    vertex editor; used only by `Admin/Projects/Wizard.vue`.
  Do not merge them, delete one, or create a third. Fix bugs in the one the
  affected page actually imports.
- **`InvestMapTeaser.vue` is NOT a map** — pure CSS decoration, three pulsing
  dots. The real homepage map is `Components/Public/HomeProjectMap.vue`.
- **Three WKT implementations are manually kept in agreement**:
  `resources/js/lib/geometry.ts` (simple picker), `resources/js/lib/wizard/geometry.ts`
  (wizard picker), and PHP `App\Modules\Geography\Support\Wkt`. If you change
  WKT semantics in one, check the other two and their tests
  (`tests/js/wizard.test.ts`, `tests/Standalone/WktValidationTest.php`).
- Files that must never be duplicated: everything under `resources/js/lib/map/`,
  both MapPickers, `MapExplorerController`, the geometry helpers above.

## B. Geospatial data contract

- **Coordinate order is lat-first everywhere except WKT and GeoJSON.**
  DB columns (`latitude`,`longitude` DECIMAL(10,7)), the `Coordinates` value
  object (`Coordinates::make($lat, $lng)`), FormRequests, and every point-layer
  JSON row (`{lat, lng}`) are latitude-first. Only WKT (`POINT(lng lat)`) and
  GeoJSON (`[lng, lat]`) are longitude-first. The conversion happens in exactly
  two places — `MapExplorerController::ringsToCoordinates()` for polygons and
  `Coordinates::toGeoJson()` for points. As the inline comment warns:
  "Backwards puts Erbil in the Indian Ocean and the map still renders" —
  a swapped pair is silent, so never infer order; check which side of the
  conversion boundary you are on.
- **Geometry is stored three ways** (dual-engine migration design): DECIMAL
  lat/lng (indexed hot path), `boundary_wkt` LONGTEXT (portable source of
  truth), and MySQL-only `GEOMETRY` columns that are currently declared but
  **unpopulated and unindexed** — do not query them.
- Eloquent returns `decimal:7` casts as **strings**; write coordinates through
  `HasCoordinates::setCoordinates()` (uses `number_format(..., 7)`) — never
  assign raw floats.
- **Validation** (`Coordinates` VO + FormRequests): range check, null-island
  rejection, `looksSwapped()` heuristic, `withinOperatingArea()`. Operating
  area (config `mulkihawler.php`): lat 35.90–36.50, lng 43.70–44.40 (Erbil).
- **Null/missing coordinates**: `projects.latitude/longitude` are nullable;
  geometry (point or polygon) is enforced at the **publish transition**
  (`Project::hasRequiredGeometry()`), not the schema. A project without
  coordinates must never appear on any map — it is excluded, never
  centroid-placed (pinned by `MapExplorerTest`). `places` coordinates are NOT
  nullable.
- **WKT validation rejects, never repairs** (`ValidWkt`, shared by Area,
  Project, and wizard doors — "door parity" is itself a fixed bug): open
  rings, <3 distinct vertices, out-of-range ordinates, self-intersection,
  holes outside/overlapping, overlapping MultiPolygon components. Keep that
  property: surface the error to the user, do not auto-close or auto-fix rings
  on either side of the stack.
- **Response shapes**: point layers (`projects`, `places`, `offers`, `areas`,
  `companies`, `prices`) are plain JSON arrays of `{lat, lng, ...}` objects —
  NOT GeoJSON. Only `boundaries` (`/map/features`) and `project_boundaries`
  (`/invest/features`) are real GeoJSON FeatureCollections.
- **Areas** form a materialised-path hierarchy (`path`, `depth`) with cycle
  and containment guards in `Area::booted()`; bbox cache columns
  (`bbox_min_lat` …) are recomputed on save. Projects resolve their area via
  `AreaResolver` (bbox prefilter + ray-cast, most-specific published match);
  `ProjectGeometryObserver` re-resolves on geometry change. A fresh install
  ships **zero areas** — empty area layers on a new site are data absence, not
  a bug.

## C. Map lifecycle rules

- Construct through `createMapAdapter()` only; hold one adapter per component
  in a `shallowRef`, guarded by a local `started`/`building` boolean.
- **Worker**: `maplibre.ts` imports the worker as
  `maplibre-gl/dist/maplibre-gl-worker.mjs?worker&url` and calls
  `maplibre.setWorkerUrl()` **before** the first `Map` construction. This is
  the RC8 fix — Vite cannot resolve MapLibre's runtime `new URL()` worker, and
  without this the map fires `load` while every GeoJSON source stays empty
  forever. Plain `?url` is not enough (the worker statically imports
  `maplibre-gl-shared.mjs`). Do not "simplify" this import.
- **RTL text plugin**: imported via `?url` through a regex Vite alias
  (`vite.config.ts` — a string alias silently never matches), registered only
  when `getRTLTextPluginStatus() === 'unavailable'` (plugin status is
  module-global and survives Inertia navigations — double registration
  rejects), `lazy=true`, registration failures swallowed (shaping degrades,
  map stays up), and registration precedes `Map` construction (pinned by
  `MapRtlTextPluginTest`, behaviorally by `map-rtl.spec.ts`).
- **CSS**: `maplibre-gl/dist/maplibre-gl.css` is imported in
  `resources/js/app.ts` and guarded by `scripts/frontend-guard.php` check 2b
  (RC1: without it every surface renders as a blank/grey area). Never remove.
- **Style resolution** precedence: server-provided `styleUrl` → inline
  zero-network `plain` style (admin pickers) → `demotiles.maplibre.org`
  last-resort default (documented as unsuitable for production; production
  must set `MAPLIBRE_STYLE_URL`).
- **Readiness**: `ready()` races `load` vs `error` vs a 20s deadline. Never
  wait on `load` alone — an unreachable style used to hang every caller
  forever. Sources and layers may only be added inside the `load` handler.
- **Sources**: points go in the clustered `features` GeoJSON source;
  boundaries in the separate `boundaries` source. Never merge them — the
  clusterer silently drops non-point geometry.
- **Resize**: the adapter owns a `ResizeObserver` (guarded for jsdom) calling
  `map.resize()` — this is what makes maps constructed inside hidden
  `v-show` tabs recover. Update data via `setPoints()`/`setBoundaries()`
  (`setData` under the hood); do not rebuild layers or adapters on data
  change, filter change, or mobile map/list tab switch (the tab switch
  toggles `hidden` classes only — pinned by `map-production.spec.ts`).
- **Cleanup**: every consumer calls `adapter.destroy()` in `onBeforeUnmount`.
  The Google adapter's destroy is deliberately exhaustive (listener-by-listener
  detach, ownership-guarded `gm_authFailure` restore, container innerHTML
  clear) — preserve that pattern when touching it.
- **Provider fallback**: Google construction failure falls back to MapLibre
  inside the factory; runtime Google failure is handled per-page
  (`handleRuntimeFailure`, guarded by a `fallingBack` boolean). MapLibre has
  no further fallback — its failure shows the honest failure state and the
  list keeps working.

## D. Project marker contract

- Markers are **not DOM elements**. Points render through layers on the
  clustered `features` source: `clusters` / `cluster-count` (native MapLibre
  clustering, radius 50, clusterMaxZoom 15, click-to-expand), `unclustered`
  (plain colour dots), `trend-markers` (canvas-drawn icons), `point-labels`
  (price text), `point-names` (project name, minzoom 13).
- Each project point carries: `id` (used for click→selection), name, optional
  pre-formatted price `label`, `colour`, and `trendIcon`.
- **Price**: the latest valid `project_prices` row. The caller formats the
  label (`Intl.NumberFormat('en-GB')` — Latin digits always, `.numeral`
  class for bidi isolation) with the row's own `currency`. The adapter never
  formats, converts, or invents a figure. **Never fabricate a price. Never
  render a null price as 0.**
- **Trend** is four-valued: `up` / `down` / `flat` / `unknown`
  (`PriceTrend`). Server derivation (`MapExplorerController::withPriceTrends()`,
  invest map only) applies the comparability gate: previous observation must
  match **price_type AND currency**, and **both** `price_from` values must be
  non-null, and the previous must be > 0. Anything else → `trend: 'unknown'`,
  `trend_percent: null`. This gate is the fix for the documented fabricated
  "−100%" (null cast to 0.0) and fake thousand-percent (USD vs IQD) trends —
  pinned by `MapTrendSemanticsTest`. **Never compute a trend across
  currencies or price types; never let null become zero; a single observation
  is `unknown`, never `flat`.**
- Client side, `normaliseTrend()` degrades garbage/null to `unknown` (never
  `flat`), and `trendHasClaim()` gates whether an arrow/percent badge may
  render at all — `unknown` renders a neutral dot and screen-reader "no trend"
  text. Trend colours/shapes are redundant (colour + glyph) for colour
  blindness: up green chevron, down red chevron, flat amber bar, unknown navy
  dot (`trend.ts` — the single source of truth; `tests/js/trend.test.ts`).
- **Click**: resolved via `queryRenderedFeatures` limited to layers actually
  present, reading `feature.properties.id` → `onMarkerClick(id)`. A guard
  keeps the generic map `onClick` (centre-pick/drawing) from firing on the
  same click. Invest and homepage select in-page (floating card /
  `MobileBottomSheet`) with a real `/projects/{slug}` link; Explorer
  deliberately navigates via the list only. The Google adapter never emits
  `onMarkerClick` — that capability gap is documented and intentional.
- **No `maplibre-gl` Popup class anywhere.** Selection UI is Vue-rendered.
  Keep it that way.

## E. Area / polygon contract

- **Fetch**: `/map/features` → `boundaries`; `/invest/features` →
  `project_boundaries` (+ area `boundaries`). Both zoom-gated server-side
  (area boundaries below zoom 11 → none; project boundaries below zoom 14 →
  `[]`), RDP-simplified per zoom band, capped at 40 with an honest
  `truncated: true` flag. The GeoJSON envelope is always present, even with
  zero rows (`MapZeroStateTest`).
- **Rendering**: `boundary-fill` + `boundary-line` layers keyed off one
  `accentColour` option. Boundaries are visual context only — no hover/click
  handlers are wired to polygon layers; selection always goes through
  markers or the list. Do not add polygon interactivity without an explicit
  requirement.
- **MultiPolygon**: MapLibre renders it natively. The Google path must go
  through `lib/map/geojson.ts` (`toPolygonComponents` +
  `normaliseComponent` winding fixes) — flattening components or skipping
  winding normalisation re-introduces two documented bugs (only-first-ring
  rendered; holes punched out of the wrong polygon).
- **Admin editing**: the wizard picker edits a
  `{exterior, holes[]}[]` component model (`lib/wizard/geometry.ts`);
  `validateGeometry()` names each problem, `toWkt()` returns `null` rather
  than serialising bad geometry, and the component blocks the emit and shows
  per-problem alerts so the user repairs their own ring. The simple picker is
  single-ring click-to-append + undo, `polygonToWkt()` from `lib/geometry.ts`.
- **Persistence**: `boundary_wkt` via the admin FormRequests, validated by the
  shared `ValidWkt` rule (reject-never-repair, all doors). `Area::booted()`
  additionally enforces hierarchy containment/cycles; `AreaController`
  converts its `RuntimeException` into a field error, not a 500. Round-trip
  fidelity (save→reload→edit→save preserves WKT verbatim) is pinned by
  `GeometryWorkflowTest` — keep it green.
- **Invalid geometry from the DB is skipped safely**: a malformed stored WKT
  must skip that one boundary, never fail the whole features request
  (pinned by `MapExplorerTest`).

## F. UX + design rules

Apply the `frontend-design` skill's principles to map surfaces; specifics for
this project:

- **Kurdish Sorani (ckb) RTL is the first-class locale**, then Arabic RTL,
  then English LTR. Page chrome RTL comes from `postcss-rtlcss` (combined
  mode) + `dir`/`lang` sync in `app.ts`; **map-canvas text** RTL comes from
  the mapbox-gl-rtl-text plugin (§C). Numbers stay Latin-digit via
  `useLocale().formatNumber()` with `.numeral` bidi isolation.
- **Map and list are peers, not primary/fallback.** The map failing must
  never take the data with it: pages fetch with a fallback Erbil bbox when
  the map is down, and a failed refresh keeps stale data instead of blanking
  the list.
- **Mobile** (`<md`/`<lg`): map/list is a tab switch (`role="tablist"`), and
  switching must not rebuild the adapter or show a loader. Touch targets meet
  the harness's `expectTouchTargets` check. Selection cards on mobile use
  `MobileBottomSheet`, not floating popovers.
- **States**: loading veil while the style loads; an honest, localized
  failure message when the provider fails (never a silent grey box); zero
  data still renders a live map (empty FeatureCollections, `MapZeroStateTest`).
- Controls: MapLibre `NavigationControl` + `ScaleControl` only; keep controls
  and floating cards from covering the list, filters, or selection UI —
  verify at 360×800 and 390×844, both RTL and LTR.
- Premium visual quality per the invest-surface CSS (`.mh-invest-*`,
  `.mh-lux-gilded` in `resources/css/app.css`); accent colour gold
  `#c9a227` on Invest, default `#1f6feb` elsewhere.
- Viewports that matter (the Playwright projects): 360×800, 390×844,
  768×1024, 1366×768, 1440×900.
- **Do not redesign unrelated pages** while fixing a map task.

## G. Performance rules

- Never reinitialize an adapter for data/filter/tab changes — update via
  `setPoints`/`setBoundaries`. Adapter construction is once per component
  lifetime.
- Respect the existing budgets: 250ms debounce on `moveend` refetch;
  per-layer cap 300 + boundary cap 40 with `truncated` (no pagination —
  zooming in is the answer); zoom gates on boundary layers; server-side RDP
  simplification (never ship full-resolution rings to the client).
- DB queries pre-filter on the indexed DECIMAL bbox columns
  (`scopeWithinBox`) before any exact geometry math; keep eager loading
  (`with(...)`) on map queries — N+1 regressions here are pinned by a query
  count assertion in `MapExplorerTest`.
- Rate limiters are **named and separate**: `map-features` 60/min,
  `map-search` 30/min. They were once a shared bucket and panning starved
  search (RC9) — never route them through one bucket again.
- Lazy construction on below-the-fold maps via `IntersectionObserver`
  (`HomeProjectMap`, `ErbilMapPreview`); `maplibre-gl` is a ~981KB lazy
  chunk — do not import it (or `lib/map`) into eagerly-loaded public pages.
- There is deliberately no response caching on map endpoints today (bbox
  indexes + caps carry the load); if adding any, it must be edge/HTTP-level
  and invalidation-safe — do not bolt `Cache::remember` onto viewport
  queries.
- Always clean up: adapter `destroy()`, observers disconnected, listeners
  detached (see the Google adapter for the reference standard).

## H. Testing contract

Full commands, fixtures, and script templates: `references/testing.md`.

The four suites that cover maps, in the order to run them:

1. **PHP Feature** — `php artisan test --filter 'MapExplorerTest|MapTrendSemanticsTest|InvestmentMapTest|MapZeroStateTest|MapRtlTextPluginTest|GeometryWorkflowTest'`
2. **JS unit (framework-free)** — `node tests/js/run.mjs` (type-checks first,
   then runs geojson/wizard/trend tests; no vitest/jest — do not add one).
3. **Standalone PHP geometry** — `php tests/Standalone/run.php`.
4. **Browser (Playwright)** — `npx playwright test` (config auto-starts
   `php artisan serve` on 8100; seed first with
   `php tests/Browser/support/seed-browser-fixtures.php --confirm-disposable-database`;
   5 viewports × 3 locales; hermetic-network harness; console errors fail
   tests via the diagnostics fixture).

**When Playwright MCP browser tools are absent** (they currently are — npm
egress is blocked so the project-scoped `@playwright/mcp` server cannot
start): do NOT block on MCP and do NOT try to install it. Use direct
Playwright through executable Node scripts. This environment has a working
globally-installed `playwright` library and a pre-installed Chromium
(`PLAYWRIGHT_BROWSERS_PATH` is already exported). Resolve the library
environment-relatively — e.g. `NODE_PATH="$(npm root -g)" node script.cjs`
with `require('playwright')` — and keep such scripts in the scratchpad.
**Never hardcode an environment-specific library or browser path into
application code, committed tests, or config** (`playwright.config.ts`
already honors `PLAYWRIGHT_CHROMIUM_PATH` for that purpose). If MCP tools
(`mcp__playwright__*`) are available in a future session they may be
preferred, but MCP availability must never be a requirement for map work.

A map change is browser-verified only when this checklist has been exercised
(see `references/testing.md` for the ready-made script skeleton):

- Chromium launches; app page loads
- Desktop viewport (1440×900) and mobile viewport (390×844)
- Kurdish (ckb), Arabic (ar), and English (en) locales
- Map visible — a real, painted MapLibre canvas, not a blank/grey box
- Map correctly sized after navigation (including hidden-tab / SPA nav cases)
- Markers render; marker click works; project navigation works
- Polygons render when boundary data exists (mind the zoom gates)
- Map controls work; mobile touch interaction works
- Zero console errors; zero failed network requests (except deliberately
  hermetic external hosts)
- Screenshots captured for visual review

## I. Debugging checklist

Read `references/debugging.md` for the full deterministic orders. Symptom →
first suspects (each maps to a documented incident):

| Symptom | Check first, in order |
|---|---|
| Blank/grey map | MapLibre CSS in `app.ts` (RC1) → style URL config (RC2) → worker asset 404 (RC8) → container 0×0 (RC3) → WebGL |
| Wrong size | hidden container at construction → ResizeObserver alive → resize after SPA nav |
| Missing style/tiles | `MAPLIBRE_STYLE_URL` set? demotiles fallback in play? network blocked (hermetic tests)? |
| Missing markers | **worker first** (map loads, sources stay empty) → `/features` response → layer filters → cluster swallowing |
| Broken polygon | zoom gate → coordinate order ([lng,lat]!) → WKT validity → winding (Google) |
| Wrong project on click | `feature.properties.id` → `queryRenderedFeatures` layer set → cluster vs point |
| Stale price | latest-row grouping in `withPriceTrends` → price_type/currency of newest row |
| Fake −100% / absurd trend | comparability gate: null `price_from`, mixed currency, mixed price_type |
| Worker failure | `?worker&url` import intact → built assets contain `maplibre-gl-worker-*.js` |
| RTL/overlay problems | plugin status/double registration → postcss-rtlcss side effects → `.numeral` bidi |
| Mobile touch failure | tab-switch rebuild? touch targets? overlay intercepting events |
| Console errors | diagnostics fixture output; treat every console error as a failure |

## J. Definition of done

Compiling is not done. A map task is complete only when ALL of these hold:

1. Relevant automated tests pass — the suites from §H that cover the changed
   layer, including the pinned regression tests (`MapTrendSemanticsTest`,
   `MapRtlTextPluginTest`, `MapZeroStateTest`, `GeometryWorkflowTest`,
   `MapExplorerTest` regressions). New behavior gets a new test in the
   matching suite.
2. Browser verification per §H's checklist — desktop AND mobile viewports,
   all three locales for user-facing changes.
3. Console and network inspected: no errors, no unexpected failed requests.
4. **No regression to the other map surfaces.** There are eight:
   `/map` (Explorer), `/invest` (Invest), homepage `HomeProjectMap`,
   `ErbilMapPreview` (detail pages), both admin MapPickers,
   Install Wizard map settings step, Admin SystemSettings integrations tab.
   A change to shared code (`lib/map/*`, `MapExplorerController`, geometry
   helpers) requires at least smoke-checking every surface it feeds.
5. `php scripts/frontend-guard.php` passes after any frontend build.
6. Screenshots attached where a visual claim is made.
7. A `code-reviewer` agent pass over the diff, with findings addressed.
