# Map System — Production Forensic Audit (before any code change)

Audited at branch point `4f7e1f779d177275ca2dbe0324068092ed511c0d` (origin/main)
on `feature/map-production-completion`, before the first edit. Production
probes provided by operations: `MAP_PROVIDER=maplibre`, style URL set to
`demotiles.maplibre.org`, style JSON reachable (HTTP 200, v8, 2 sources,
8 layers), `map.explorer` and `map.investment` ON. Flags and the top-level
style are therefore NOT the defect; everything below is.

## 1. Current architecture

```
Server
  config/services.php: services.maps.{provider, maplibre_style_url, google_key}
  HandleInertiaRequests.php:225 shares map.style_url to every Inertia page
  MapExplorerController (Geography): /map + /invest pages, /map/features,
    /invest/features (+search) — viewport-scoped, layer-flag-gated, published-only,
    coordinate-required (whereNotNull latitude/longitude), truncation caps
Client
  resources/js/lib/map/
    types.ts     MapAdapter contract (ready/getBounds/getZoom/setPoints/
                 setBoundaries/flyTo/destroy + onMoveEnd/onClick/onError)
    maplibre.ts  MapLibreAdapter — clustered GeoJSON points, boundary source,
                 bounded ready() that REJECTS on pre-load style failure
    google.ts    GoogleMapsAdapter (optional, key-gated)
    index.ts     createMapAdapter(provider) with stated Google→MapLibre fallback
  Pages/Public/Map/Explorer.vue  full explorer on the adapter
  Pages/Public/Map/Invest.vue    curated investment surface on the adapter
  Components/MapPicker.vue       ADMIN picker #1 — direct `new maplibre.Map`,
                                 used by Admin Projects/Form, Areas, Places, Offers
  Components/map/MapPicker.vue   ADMIN picker #2 — adapter-based, used by
                                 Admin Projects/Wizard only
  Components/Public/InvestMapTeaser.vue  homepage "map" — pure CSS decoration
```

## 2. Observed production symptoms → root causes, with evidence

### RC1 — MapLibre's stylesheet is not in the Vite graph at all (confirmed)

`grep -rn "maplibre-gl.css|maplibre-gl/dist" resources/ vite.config.*` returns
**nothing**. `resources/js/app.ts` imports no CSS for MapLibre;
`resources/css/app.css` has no `@import`; no page or component imports it.
`maplibre-gl` v6 ships every rule the widget needs in
`maplibre-gl/dist/maplibre-gl.css` — container/canvas positioning
(`.maplibregl-map`, `.maplibregl-canvas-container`), controls, markers,
popups, attribution. Without it the canvas and control DOM render unstyled
and mispositioned: exactly the "blank/grey map area" class of symptom, on
every MapLibre surface at once (public maps, both admin pickers). CI passes
because no test ever asserted a *painted* map.

### RC2 — the configured style is the demo world style (structurally blank at city zoom)

`demotiles.maplibre.org/style.json` is MapLibre's demonstration style: 2
sources / 8 layers (matches the production probe exactly) of country-level
vector data with low maximum zoom — no streets, no buildings, no labels at
district scale. The public surfaces pin the camera to Erbil at zoom 10–18
(`Invest.vue` minZoom 10, maxBounds around Erbil; Explorer similar), where
this style has essentially nothing to draw. A fully *working* map therefore
still renders as a flat grey/beige rectangle. The style being HTTP-200/valid
is precisely why this was hard to see from the server side. Demo
infrastructure is also explicitly unsuitable as a production dependency
(availability is best-effort). Repair: keep the configurable style URL,
recommend a documented production style (see completion doc), and make the
client distinguish "style failed" from "style fine but empty at this zoom"
only insofar as honesty requires — the real fix is configuration guidance,
not code that pretends.

### RC3 — no resize handling anywhere in the map stack (confirmed)

`grep -rn "resize" resources/js/lib/map/ …MapPicker… …Map/*.vue` → zero
matches. The adapter API has no `resize()`; nothing observes container size;
nothing calls `map.resize()`. Two concrete failure sites:

- **Admin Projects Form**: the Location card is `v-show="active ===
  'location'"` (`Form.vue:202`) while `MapPicker` builds its map in
  `onMounted` — i.e. inside a `display:none` subtree, where the canvas
  measures 0×0. When the tab becomes visible the canvas stays wrong until an
  accidental window resize. This is the "MapPicker displays a blank/plain
  surface" symptom's third contributor.
- **Inertia navigation / mobile map↔list switching**: `hidden lg:block`
  toggling on the map section re-shows a container the map sized earlier.

### RC4 — admin picker duplication and drift (confirmed)

Two unrelated pickers exist. `Components/MapPicker.vue` (Projects/Form,
Areas, Places, Offers) constructs MapLibre **directly**, duplicating style
resolution, error handling and marker logic — and none of the adapter's
bounded-readiness behaviour. `Components/map/MapPicker.vue`
(Projects/Wizard) is adapter-based. Fixes to one historically never reached
the other; RC1/RC3 bite both, and only one of them would have received a
repair.

### RC5 — homepage has no live map (confirmed)

`InvestMapTeaser.vue` states it plainly: "A PREVIEW, not a map: no map
library, no tiles, no fetch — pure CSS". Three decorative pulsing dots and a
CTA. The requested live, bounded, lazy homepage project map does not exist.

### RC6 — price trend is not on the markers, and its derivation has defects

Markers: `Invest.vue` `syncSource()` sends every project as a uniform gold
point (`colour: '#c9a227'`); `PointFeature` carries only
`{lat,lng,title,colour}`. Trend renders ONLY in the floating card and the
list. There is also **no per-marker click** — the adapter's map click
*clears* the selection, and the unclustered layer has no click handler, so
markers cannot be selected from the map at all.

Derivation (`MapExplorerController` ≈ lines 990–1055, from `project_prices`):
latest row per project = current; the next older row **with the same
price_type** = previous; `flat` when |Δ| < 0.05 %, else up/down; no previous
→ trend null. Defects:

- **Currency comparability is not enforced**: the previous row is matched on
  price_type only. A latest USD row against an older IQD row of the same
  type compares raw magnitudes and can emit a fabricated ±N-thousand-percent
  "trend". Violates the comparability rule outright.
- **Null prices are cast to 0.0**: `(float) $current['price_from']` with a
  null `price_from` compares 0 against the previous value and emits "down
  −100 %". A null observation is not an observation.
- **"unknown" is only implicit**: trend null means "no claim", which the
  frontend happens to render as nothing — acceptable, but the semantics are
  nowhere stated, `flat` genuinely requires two comparable observations
  today (good), and nothing pins any of this in a test, so the
  missing-history-is-not-flat rule survives by accident.

### RC7 — no WebGL/diagnostic story

A `new maplibre.Map()` throw (no WebGL) lands in a generic catch →
`mapFailed` on the public pages (acceptable), but nothing distinguishes
style-fetch vs tile vs WebGL vs zero-container failure even in development,
and `Components/MapPicker.vue` shows one generic warning. The bounded
`ready()` (resolve/reject) exists and is sound for the pre-load case; the
post-load tile-failure path funnels into `handleRuntimeFailure` which was
built for Google fallback and otherwise flips `mapFailed` even when the map
is still usable.

## 3. What is already correct (and must not regress)

- Feature flags gate `/map` and `/invest` server-side; layer restrictions are
  enforced by the server regardless of the request.
- Published-only, coordinates-required project selection; no fabricated
  coordinates anywhere; truncation caps and spatial safeguards.
- The always-rendered list beside the map; empty-state overlays; offline and
  provider-fallback messaging; clustered GeoJSON points (not DOM markers).
- `ready()` rejecting on pre-load style failure (no infinite wait for
  `load`) — the *pattern* is right; it needs a deadline added, not a rewrite.
- Configurable style URL through `services.maps.maplibre_style_url` shared to
  every page; Google optional, never mandatory.

## 4. Data prerequisites

`projects.latitude/longitude` (nullable) + `publication_status` are
sufficient — no schema change needed. What is missing is operational
visibility: Admin Projects offers no way to see which published projects are
map-invisible for lack of coordinates. A coordinate-status signal/filter in
the admin list satisfies this without schema.

## 5. Repair plan (summary)

1. Import `maplibre-gl/dist/maplibre-gl.css` exactly once in `app.ts` (the
   single Vite entry both public and admin pages share); assert it in the
   frontend guard so it cannot silently vanish again.
2. Add `resize()` to the adapter contract + a shared `ResizeObserver`-based
   attachment; MapPicker resizes when its hidden tab becomes visible instead
   of rebuilding.
3. Bounded readiness with a deadline: ready() resolves, rejects, or times
   out into the explicit error state — never an indefinite spinner. Failure
   categories (style/tiles/webgl/container) surfaced in dev diagnostics,
   human-readable states in production.
4. Trend semantics hardened server-side: previous observation must match
   price_type AND currency and both values non-null; explicit
   `up|down|flat|unknown` with `unknown` never rendered as flat; markers
   carry trend + accessible text, encoded as colour + directional shape on
   the map itself; per-marker click selects.
5. Retire `Components/MapPicker.vue` usage in favour of one adapter-based
   picker with the resize/error/readiness behaviour, preserving polygon
   drawing and manual-coordinate editing.
6. Homepage: real bounded lazy-loaded project map on the shared adapter
   (projects layer only), IntersectionObserver-deferred, truthful empty
   state.
7. Deterministic Playwright setup serving a local style/tiles — no
   demotiles dependency in CI.

## 6. Addendum — found during verification, not during the initial audit

Dated honestly: the two findings below surfaced only when the deterministic
browser suite (repair step 7) ran against a WORKING style in CI — the
initial audit could not see them because at audit time no map on any
environment got far enough to need a worker or to sustain map traffic.

### RC8 — MapLibre's worker never shipped in the Vite build (confirmed, production-affecting)

maplibre-gl v6 resolves its worker as
`new URL('./maplibre-gl-worker.mjs', import.meta.url)` at runtime, relative
to whatever chunk the bundler emitted. Vite cannot statically analyse that
expression, so the worker file was never copied into `public/build` and
every environment requested `/build/assets/maplibre-gl-worker.mjs` → 404.
The map still fires `load` — which is why this hid behind RC1/RC2: the
surface looks "ready" while every GeoJSON and vector source, whose data is
parsed IN the worker, stays empty forever. No worker, no markers, no vector
tiles — a first-class contributor to the blank-map symptom even after the
CSS and style are fixed. And copying the file verbatim is not enough: the
dist worker is half of a split build that statically imports
`./maplibre-gl-shared.mjs`, so a `?url` copy dies on its own 404 one
request later. Repair: import the worker via Vite's `?worker&url` (bundled
self-contained, emitted as a real hashed asset) and call
`maplibre.setWorkerUrl()` before the first map constructs; the E2E asset
sweep now fails if any of it regresses.

### RC9 — one shared guest throttle bucket starved map search (confirmed)

`/map/features`, `/invest/features` (both `throttle:60,1`) and
`/invest/search` (`throttle:30,1`) used inline numeric throttles, which key
every guest request by `sha1(domain|ip)` — ONE counter shared across all
three routes. Panning the map fires a features request per moveend
(correctly allowed), and each one also advanced the search counter: after a
minute of ordinary browsing the search box answered 429 while features kept
flowing. The browser suite caught it as a deterministic failure (search dead
in exactly the locale block that ran after enough map traffic). Repair:
named limiters (`map-features` 60/min, `map-search` 30/min) with their own
`by()` keys — budgets unchanged, buckets separated.
