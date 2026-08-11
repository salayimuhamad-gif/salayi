# Map System — Production Completion (after repair)

Companion to `docs/MAP_PRODUCTION_AUDIT.md`, which was written before the
first edit and names seven root causes (RC1–RC7); its §6 addendum records
two more (RC8 missing MapLibre worker, RC9 shared throttle bucket) that
only became visible once the deterministic browser suite drove a WORKING
style in CI. This document records what
changed, the architecture that now exists, what production must configure,
the exact trend semantics the markers claim, and what was verified at each
test layer. Base: `4f7e1f779d177275ca2dbe0324068092ed511c0d` (origin/main),
branch `feature/map-production-completion`.

## 1. Files changed

### Shared map foundation
- `resources/js/app.ts` — **RC1 fix**: `import 'maplibre-gl/dist/maplibre-gl.css'`
  in the single shared Vite entry, so every MapLibre surface is laid out by
  the stylesheet the widget requires. Included exactly once.
- `resources/js/lib/map/types.ts` — the adapter contract grows the pieces the
  defects traced to: required `resize()`, optional draggable `setPin()`,
  `onMarkerClick`, `PriceTrend` on point features, bounded
  `readyTimeoutMs`, and an explicit `fallbackStyle: 'demo' | 'plain'`.
- `resources/js/lib/map/maplibre.ts` — **RC3/RC6/RC7/RC8 fixes**: the
  MapLibre worker becomes a real emitted asset (`?worker&url` import —
  bundled self-contained, because the dist worker statically imports
  `./maplibre-gl-shared.mjs` — plus `setWorkerUrl()` before the first
  construction). v6 resolves the worker at runtime relative to the emitted
  chunk, Vite never copied it, and every environment 404'd
  `/build/assets/maplibre-gl-worker.mjs`, leaving every GeoJSON/vector
  source permanently empty behind a map that still fired `load`. Also: a
  ResizeObserver on the container self-heals maps built inside hidden
  `v-show` boxes (no rebuild); `ready()` has three bounded exits (load /
  pre-load style failure / deadline) so no page can wait forever; post-load
  resource errors no longer flip a working map into the failed state (DEV
  console diagnostics distinguish style / tile / container / timeout
  categories; production UI stays human-readable); canvas-drawn trend marker
  icons registered as images (no sprite/glyph dependency, so they render
  under any style); a `trend-markers` symbol layer with marker-click events
  and a surface-click guard so clicking a marker selects instead of
  clearing; a draggable pin API for the picker.
- `resources/js/lib/map/trend.ts` — NEW, the single trend presentation
  table: icon name, colour, direction glyph, claim gating, normalisation.
  Pure and node-testable; the adapter, /invest and the homepage all read it.
- `resources/js/lib/map/google.ts`, `index.ts` — contract kept in step
  (documented `resize()` no-op; `PriceTrend` export).

### Surfaces
- `resources/js/Pages/Public/Home.vue` +
  `resources/js/Components/Public/HomeProjectMap.vue` (NEW) — **RC5 fix**:
  the CSS teaser is replaced by a real, bounded, live instance of the shared
  infrastructure: Erbil box, published projects only, one flag-gated fetch
  (`/invest/features` when `map.investment` is on, else `/map/features`,
  projects layer only), IntersectionObserver-deferred construction, lazy
  MapLibre chunk, marker selection with name/area/qualified price/trend and
  a project link, truthful empty state, compact stated failure state.
  Renders only when at least one map flag is on.
- `resources/js/Pages/Public/Map/Invest.vue` — **RC6 fix**: markers carry
  the trend ON THE MAP (id + normalised trend feed the symbol layer);
  marker click selects the project; selection card and list badges render a
  claim only for `up`/`down`/`flat` and state the absence for `unknown`
  (screen-reader text included).
- `resources/js/Components/MapPicker.vue` — **RC4 fix**: rewritten onto the
  shared adapter (same props/emits contract). Bounded readiness with a
  human-readable failure message and a retry button; ResizeObserver heals
  the hidden Location tab; click-to-place, draggable pin, two-way
  lat/lng synchronisation (typed coordinates move the pin; cleared fields
  remove it); polygon ring drawing, existing-geometry restore and
  fit-to-polygon preserved; with no style configured it runs on a
  zero-network plain background — the picker never requires an external
  provider to be usable. Serves Admin Projects (Form), Areas, Places, Offers.

### Backend
- `app/Modules/Geography/Providers/GeographyServiceProvider.php` +
  `app/Modules/Geography/Routes/web.php` — **RC9 fix**: the map endpoints
  move from inline `throttle:60,1`/`throttle:30,1` (which key every guest
  by `sha1(domain|ip)` — one bucket shared across routes, so a minute of
  panning starved the search box into 429s) to named limiters with their
  own keys: `map-features` 60/min, `map-search` 30/min. Budgets unchanged,
  buckets separated.
- `app/Modules/Geography/Http/Controllers/Public/MapExplorerController.php` —
  trend derivation hardened (see §5): previous observation must match price
  type AND currency with both figures present; `unknown` is the explicit
  no-claim value; flat requires two comparable observations within ±0.05%.
- `app/Modules/Projects/Http/Controllers/Admin/ProjectController.php` +
  `resources/js/Pages/Admin/Projects/Index.vue` — admin map-readiness
  signal: each row carries `map_ready` (both coordinates present), a
  not-map-ready badge with an explanatory hint, and a ready/not-ready
  filter. No schema was added; latitude/longitude were sufficient.

### Language
- `lang/{en,ar,ckb}/{map,home,projects}.php` — unknown-trend statement,
  homepage live-map strings, map-readiness strings. All three locales.

### Tests and gates
- `tests/Feature/MapTrendSemanticsTest.php` (NEW, 8 tests) — the derivation
  contract from the data up (§5), plus publication/coordinate gates, invest
  layer restriction, and the admin readiness signal.
- `tests/Feature/InvestmentMapTest.php` — updated to the explicit `unknown`
  contract (previously pinned `null`).
- `tests/js/trend.test.ts` (NEW) + `tests/js/run.mjs` + `vite.config.mjs` —
  the marker presentation table: four distinct colours, four distinct
  shapes, direction glyphs, claim gating, "missing history degrades to
  unknown, never flat".
- `tests/Browser/map-production.spec.ts` (NEW) + seeder — the working-map
  E2E suite (§6). `tests/Browser/support/seed-browser-fixtures.php` seeds
  `map.explorer` and one published project per trend, each from real
  persisted observations (or a deliberate single observation for unknown).
- `scripts/frontend-guard.php` — a regression tripwire: fails when
  `app.ts` loses the MapLibre CSS import or the built assets contain no
  `maplibregl-map` rule.
- `scripts/release/release_gates.py` — the new spec registered in the
  canonical Playwright inventory with its reviewed viewport skips.
- `.github/workflows/ci.yml` — the frontend job now runs the framework-free
  unit suite (`node tests/js/run.mjs`).

## 2. Architecture after repair

One MapLibre foundation. Every surface — /map, /invest, the homepage map,
and all four admin pickers' host forms — builds through
`createMapAdapter()`, which owns style resolution, bounded readiness,
error categorisation, resize self-healing, clustering, trend marker
rendering and the pin. Pages own only their data and their UI states. The
Wizard's `Components/map/MapPicker.vue` already sat on the adapter and
now shares the repaired foundation with everything else; the two picker
components still exist (their form contracts differ) but there is no longer
a second map stack beneath either of them.

Style resolution order, adapter-wide: configured URL → (`fallbackStyle:
'plain'`) inline zero-network background → historical demo default. The
admin picker opts into `plain`, so admin geometry editing works on a fresh
install with nothing configured and no reachable provider.

## 3. Production configuration requirements

- `MAP_PROVIDER=maplibre` (current production value — correct).
- `MAPLIBRE_STYLE_URL` — **must be set to a real street-level style**.
  The audit's RC2 stands: `demotiles.maplibre.org` is MapLibre's
  demonstration world style (country-scale, low max zoom, no streets at
  Erbil zoom); it renders as a flat rectangle at city zoom even when
  everything else works, and demo infrastructure carries no availability
  commitment. It remains the code's last-resort default only so an
  unconfigured install still boots a map.

  Recommended, in order (all credential-free to adopt; verify terms before
  committing production traffic):
  1. **Self-hosted style + tiles** (OpenMapTiles/Protomaps PMTiles behind
     the existing host) — no third-party runtime dependency; the strongest
     answer for a market where client connectivity is variable.
  2. **OpenFreeMap** (`https://tiles.openfreemap.org/styles/liberty`) —
     hosted, keyless, OSM-based, browser-CORS enabled.
  3. Any commercial MapLibre-compatible style (MapTiler, Stadia, …) — only
     as a deliberate, documented decision; requires keys, which this
     repository does not embed or require.

  Whatever is chosen, verify the FULL dependency chain from a browser, not
  just the style JSON: `sources.*.url`/`tiles`, `sprite` (`.json` + `.png`),
  `glyphs` ranges — all must be HTTPS + CORS-readable. The style JSON being
  HTTP 200 proves almost nothing about the map painting (that is exactly
  how production shipped broken).
- No Google key is required anywhere. `MAP_PROVIDER=google` remains
  optional and falls back to MapLibre, unchanged.

## 4. Feature flags and data requirements

- `map.explorer` gates /map; `map.investment` gates /invest (server-side,
  authoritative, unchanged). The homepage section renders only when at
  least one is on, and sources from the invest endpoint first.
- Public map projects require, as before: `publication_status=published`
  AND non-null latitude AND longitude. Nothing invents coordinates;
  projects without them are simply absent, and Admin Projects now shows
  which ones those are (badge + filter) so operators can fix data instead
  of guessing.

## 5. Exact trend semantics

Derived in `MapExplorerController::withPriceTrends()` for the investment
surface only, per project, deterministically:

1. Take the latest `project_prices` row (the current price).
2. Take the next older row **with the same `price_type` AND the same
   `currency`, where both `price_from` figures are non-null**. Rows failing
   any of that are not history — they are ignored.
3. With two comparable observations, `Δ% = (latest − previous) / previous`:
   `up` if Δ% > +0.05, `down` if Δ% < −0.05, `flat` otherwise —
   `trend_percent` formatted to one decimal.
4. In every other case — no rows, one row, currency mismatch, price-type
   mismatch, null figure — the trend is **`unknown`** with
   `trend_percent = null`. `unknown` is an explicit value, never a
   pretend-flat and never a fabricated percentage.

Presentation (single source: `resources/js/lib/map/trend.ts`, drawn as
canvas icons in the adapter):

| trend   | colour           | shape on marker    | accessible text        |
|---------|------------------|--------------------|------------------------|
| up      | green `#15803d`  | upward chevron     | "price increased"      |
| down    | red `#b91c1c`    | downward chevron   | "price decreased"      |
| flat    | amber `#b45309`  | horizontal bar     | "price steady"         |
| unknown | navy `#0f3e59`   | plain neutral dot  | "no trend available"   |

Colour is never the only signal: shape differs per trend on the marker
itself, the selection card adds the direction glyph + signed percentage,
and screen-reader text states the meaning (including the unknown absence).

## 6. Tests run and results

- Backend: `php artisan test` — **772 passed, 10 skipped** (skips are
  environment-conditional: bcmath, MySQL row-lock concurrency), including
  the new `MapTrendSemanticsTest` (8 tests) and the updated map suites
  (79 map-related feature tests green).
- Static analysis: `vendor/bin/phpstan analyse` — **no errors**. Pint clean.
- Frontend unit: `node tests/js/run.mjs` (tsc typecheck → bundle → run;
  geojson + wizard + NEW trend suites) — wired into CI's frontend job; not
  runnable in the authoring sandbox (npm registry blocked), verified in CI.
- Browser/E2E: `tests/Browser/map-production.spec.ts` — deterministic by
  construction: the harness's hermetic network blocks every external
  request, and the tests that need a WORKING style serve an inline
  background-only MapLibre style via route interception (zero further
  requests; nothing in CI ever contacts demotiles or any provider).
  Covers: homepage live map ready in ckb/ar/en (canvas laid out
  `position:absolute` — the CSS root cause pinned in a real browser),
  /map leaving its loading state, /map provider failure settling into the
  stated error with the list alive, all four trend semantics from
  persisted rows on /invest, per-trend selection cards (glyph + percent +
  accessible text, unknown claims nothing), a real marker click on the
  canvas selecting the project, the admin picker working after the hidden
  Location tab is revealed (click-to-place, field sync, manual override),
  existing-coordinate restore on edit, and the mobile map/list switch.
  Registered in the canonical spec inventory; desktop/mobile-only tests are
  reviewed intentional skips. Runs in CI's e2e job.
- Release tooling regressions: `python3 tests/Standalone/release_tooling_test.py`
  — **all 408 passed** (spec closure includes the new file).
  `php tests/Standalone/run.php` — passed.
- Secret scan over the full diff — no matches.

## 7. Known limitations

- **Style content still decides what production sees.** The code now lays
  out, sizes, loads and fails honestly under any style, but street-level
  imagery at Erbil zoom requires operations to set `MAPLIBRE_STYLE_URL`
  per §3. That is a configuration act this branch deliberately does not
  perform for production.
- The Google adapter remains keyless-optional and has no marker-click or
  pin support; under `MAP_PROVIDER=google` selection continues to run
  through the list/search path, and the admin picker is MapLibre-only.
- E2E asserts marker DATA, layout and interaction (payload trends, canvas
  presence/positioning, click-to-select) rather than reading pixels off
  the WebGL canvas; the icon pixels themselves are pinned by the unit
  suite that draws them. Rendering under a REAL street style is exercised
  manually, not in CI, by design (no external dependency in CI).
- The two admin picker components (Form-family vs Wizard) share one map
  foundation but keep separate Vue contracts; merging them is a UI
  refactor left out deliberately (different form-binding shapes, no
  remaining map-level duplication).
