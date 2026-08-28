# Mulk map architecture — file map, endpoints, data shapes

Derived from direct codebase analysis (2026-08). File paths are repo-relative.
When code and this document disagree, the code wins — then update this document.

## 1. Frontend file map

### Adapter core — `resources/js/lib/map/` (authoritative; never duplicate)

| File | Responsibility |
|---|---|
| `types.ts` | `MapAdapter` interface (`ready()`, `getBounds()`, `getZoom()`, `setPoints()`, `setBoundaries()`, `setPin?()`, `setPois?()`, `flyTo()`, `fitBounds?()` (per-side pixel padding + `maxZoom`; fit-once at explicit selection, never from a watcher), `setSelectedBoundary?()`, `setBoundaryInteractive?()`, `resize()`, `destroy()`), `AdapterOptions` (incl. `labelScheme: 'light'\|'dark'` for adapter-drawn text/pin paint), `AdapterEvents` (incl. Phase 3 `onBoundarySelect?(BoundaryIdentity)` — emitted only for a click no record layer claimed), `BoundaryIdentity {slug,name,type}`, `PointFeature`, `PoiFeature` + `PoiCategory` (Phase 2 places hook, consumed by the Explorer), `PriceTrend = 'up'\|'down'\|'flat'\|'unknown'`. |
| `poiCategories.ts` | Map Phase 2: the ONE explicit map from open DB place-category keys into the closed `PoiCategory` union (`poiCategoryFor()`, unknown → `'other'`; church/hotel deliberately `'other'`). Pinned by `tests/js/poi.test.ts`; consumed by `Pages/Public/Map/Explorer.vue`. |
| `index.ts` | `createMapAdapter(provider, options)` — the only sanctioned constructor. Converts Google construction failure into MapLibre fallback with `fallbackReason`. |
| `maplibre.ts` | `MapLibreAdapter`. Worker URL wiring (`?worker&url` import + `setWorkerUrl` before first Map), RTL plugin registration (guarded by `getRTLTextPluginStatus() === 'unavailable'`), style resolution (options.styleUrl → `plain` inline style → same-origin `/map-styles/mulk-dark.json` default — CARTO Dark Matter raster over a near-black ground, no glyphs entry so adapter-drawn labels render via local TinySDF + the RTL plugin), clustered `features` source + separate `boundaries` source, layers `clusters`/`cluster-count`/`unclustered`/`trend-markers`/`point-labels`/`point-names`/`boundary-fill`/`boundary-line` plus the POI pair `poi-dots`/`poi-labels` (zoom-gated 13/15, subdued grey with amber `active` voice, both inserted with `beforeId: 'clusters'` so POIs render beneath every project layer and project symbols keep collision priority; fed through `setPois()` — since Map Phase 2 the /map Explorer routes its `places` layer here, mapped by `poiCategories.ts`, while every record layer stays on the clustered source), canvas-drawn trend icons, draggable pin, ResizeObserver, bounded `ready()` (load vs error vs 20s deadline), direction-aware control corners (Phase 6: reads the container's computed direction; zoom top-END, scale bottom-START, manually added compact attribution bottom-END — physical corners resolved per dir, because the vendor stylesheet is excluded from rtlcss in `postcss.config.js` and no CSS side effect mirrors controls any more). Map Phase 3 boundary interaction (the sanctioned exception to "boundaries are non-interactive", Explorer only): `boundaries` source gains `promoteId:'slug'`; `boundary-line` opacity is a hover feature-state case-expression (0.9/0.6); `boundary-selected-fill`/`boundary-selected-line` (restrained amber `#f3c56f`) sit right after `boundary-line`, beneath every marker, filtered by the buffered selected slug (`' '` sentinel matches nothing); the generic click handler enforces the priority order — project marker > POI > polygon > empty map — by querying the reserved record layers first and emitting `onBoundarySelect` only when none hit, then falling through to `onClick`; hover + pointer cursor register only when `onBoundarySelect` is wired and only while `setBoundaryInteractive(true)` (the Explorer disables it during centre-pick/draw). `fitBounds()` animates 650ms with per-side padding and a `maxZoom` cap. Map Phase 4 market heat: `market-fill`/`market-line` layers between `boundary-line` and the selection layers, painted via `setMarketHeat(Record<slug, 'up'\|'down'\|'flat'> \| null)` — one slug-matched match-expression rebuilt whole per update (stateless, ≤40 slugs), colours from `trend.ts`, everything unmatched fully transparent (unknown = the untinted dark base). |
| `google.ts` | `GoogleMapsAdapter` (optional provider). Script injection with timeout, `gm_authFailure` save/restore with ownership guard, `tilesloaded`-gated readiness, custom grid clusterer (parity with MapLibre budgets), MultiPolygon via `toGooglePolygons`, exhaustive `destroy()`. Never emits `onMarkerClick` (documented gap). |
| `geojson.ts` | Pure, unit-tested GeoJSON↔Google conversion: `toPolygonComponents` (MultiPolygon → per-component `{exterior, holes[]}`), `normaliseComponent` (RFC-7946 winding for Google's even-odd hole rendering), `signedArea`, `isClockwise`, `ringToPath` ([lng,lat] → {lat,lng}), `boundaryBounds` (Phase 3: geometry → `{north,south,east,west}` for the selection camera fit; `null` for degenerate geometry → the caller falls back to the centroid). |
| `trend.ts` | Single source of trend presentation: `trendIconName`, `trendColour`, `trendArrowGlyph`, `trendHasClaim`, `normaliseTrend` (garbage/null → `unknown`, never `flat`). Colours: up `#15803d`, down `#b91c1c`, flat `#b45309`, unknown `#0f3e59`. |

### Geometry helpers (two JS WKT implementations + one PHP — keep in sync)

| File | Responsibility |
|---|---|
| `resources/js/lib/geometry.ts` | Simple picker's WKT: `parsePolygonRing`, `polygonToWkt` (POLYGON only, single ring, no holes/MultiPolygon), `ringCentroid` (shoelace, must match PHP), `ringBounds`. |
| `resources/js/lib/wizard/geometry.ts` | Wizard picker's structural editor: `Component = {exterior, holes[]}`, `fromWkt`/`toWkt` (POLYGON + MULTIPOLYGON with holes; `toWkt` returns `null` for invalid geometry — refuses to serialise), `validateGeometry` (`too_few_vertices`, `duplicate_vertices`), vertex/hole CRUD, `toRenderedFeatures`, plus unrelated `moveItem`/`createThrottle`/`createRequestGate` utilities in the same file. |

### Map surfaces (consumers)

| File | Surface |
|---|---|
| `resources/js/Pages/Public/Map/Explorer.vue` | `/map` public explorer: multi-layer, viewport fetch of `/map/features` (250ms debounce on moveend), always-rendered list, radius search, polygon draw-search, provider fallback UI, mobile map/list tabs. No `onMarkerClick` wired — intentional. Map Phase 3: ONE canonical `selectedArea` state feeding `AreaIntelligenceCard` (desktop glass float at start-3/top-3, `MobileBottomSheet` below lg) from `/location/resolve` (`?area=slug` for polygon/list selection, `?lat&lng` for live location — the SAME endpoint as the homepage card; abortable, attempt-token race-guarded); polygon click via `onBoundarySelect` (interaction disabled while centre-pick/draw are armed); area list rows select in place; empty-map click clears the selection only; fit-once camera (`fitBounds` with card-side padding, centroid `flyTo` fallback); geolocation ONLY inside the click handler with timeout+watchdog, coordinates used transiently and never persisted; card service groups toggle the real places layer + category filters. Map Phase 4: an Explore/Market mode switch (rendered only when `market.intelligence` is on — page prop `market.available`); Market mode auto-enables the areas layer, fetches `GET /map/market` (bbox + transaction + period + single property_type; own `map-market` limiter) under the shared moveend debounce with its own generation token, paints via `setMarketHeat`, mirrors the pulse panel's window-disabling convention, reuses `market.movement.*`/`market.property_types.*` strings + `map.market.*` for mode/legend/zoom-hint, and states loading/zoom-gate/honest-empty through the map-toast voice. Heat data comes from `MarketMovementService::areaMovement()` — never computed client-side. Map Phase 5: a premium glass search combobox above the map (`map-search` input, 300ms debounce, attempt-token + AbortController race guard, ArrowUp/Down/Enter/Escape with `aria-activedescendant`, grouped results labelled by `map.layers.*`, states from `map.discovery.*`); an area result funnels into the SAME canonical `selectArea()` with the response bbox/centroid as camera fallback (`AreaFocusHint`); a project/place result clears the area selection (§25), enables its layer (+ only its own category), flies to the stored coordinate (15/16) and leaves the `map-search-context` glass strip with the real `/projects/{slug}` / `/places/{slug}` route; Market mode/filters and radius/draw state are never touched — search is city-wide navigation. Map Phase 6: a third **Compare** mode (always offered; Market chip only where `market.intelligence` is on) — ONE canonical `comparedAreas` set (max 3) drives the slot chips, the adapter's `setComparedBoundaries` outlines and `/map/compare` (attempt-token + AbortController); the Phase 5 search doubles as the picker (areas-only groups, `chooseArea` adds a slot), Phase 4's filter refs are SHARED (§37 — one filter state, both fetches no-op outside their mode), entering Compare clears the Phase 3 card and boundary clicks focus panel columns instead (§33), the camera fits the compared bboxes only on add/remove/Show-all (§32), and `CompareAreasPanel.vue` renders the payload (desktop metric grid / stacked cards below lg) in the list pane. |
| `resources/js/Pages/Public/Map/Invest.vue` | `/invest` investment map: `/invest/features` + `/invest/search`, type filters, boundary toggles, trend badges, marker click → in-page selection card + flyTo, gold accent `#c9a227`, mobile tabs + `MobileBottomSheet`. |
| `resources/js/Components/Public/HomeProjectMap.vue` | Homepage pricing map. Lazy via IntersectionObserver; reuses `/invest/features` (or `/map/features`) with fixed Erbil bbox `{north:36.42,south:35.95,east:44.32,west:43.7}`, client-capped 60 rows; client-side filters; desktop popover / mobile bottom sheet. |
| `resources/js/Components/Public/ErbilMapPreview.vue` | Read-only single-pin preview on detail pages; lazy; silently degrades to nothing on failure. |
| `resources/js/Components/Public/InvestMapTeaser.vue` | **NOT a map.** Pure CSS/SVG homepage teaser (3 pulsing dots + CTA). |
| `resources/js/Components/MapPicker.vue` | Simple admin picker (point + single-ring polygon; three v-models `latitude`/`longitude`/`boundaryWkt`). Consumers: `Pages/Admin/{Areas,Offers,Places,Projects}/Form.vue`. Uses `fallbackStyle: 'plain'` (zero-network inline style). |
| `resources/js/Components/map/MapPicker.vue` | Advanced wizard picker (point + MultiPolygon with holes, vertex-level editing; v-models `point`/`boundary`). Sole consumer: `Pages/Admin/Projects/Wizard.vue`. Blocks emit + shows named alerts on invalid geometry. |
| `resources/js/Pages/Install/Wizard.vue`, `resources/js/Pages/Admin/SystemSettings.vue` | Map **settings forms** (provider, style URL, Google key) — render no map. |

### Build/config wiring

- `vite.config.ts` — regex alias for `@mapbox/mapbox-gl-rtl-text/dist/...?url`
  (string alias never matches a `?url`-suffixed id; package `exports` rejects
  the deep path). MapLibre worker chunk handled by the `?worker&url` import in
  `maplibre.ts`.
- `resources/js/app.ts` — imports `maplibre-gl/dist/maplibre-gl.css`
  (frontend-guard check 2b enforces this) and syncs `document.documentElement`
  `dir`/`lang` from `page.props.locale`.
- `postcss.config.js` — `postcss-rtlcss` combined mode (global CSS RTL; no
  map-specific RTL CSS exists).
- `scripts/frontend-guard.php` — runs after every build: Inertia page
  existence, manifest presence, **MapLibre CSS reached the bundle**
  (`maplibregl-map` rule in built CSS), build freshness.
- Dependencies (locked): `maplibre-gl` 6.0.0, `@mapbox/mapbox-gl-rtl-text`
  0.4.0 exact. No supercluster (clustering ships inside maplibre-gl), no draw
  library, no @types/google.maps.

### Map status chrome (Map Phase 1)

- `.mh-map-toast` (+ `--error`) in `resources/css/app.css` — the ONE compact
  status voice floating over a map (loading, empty view, dropped refresh);
  no backdrop blur by design. Positioning stays with each surface (the
  measured `bottom-24`/`lg:bottom-4` slots that clear the mobile tab bar).
- `.mh-map-ground` — near-black ground under every public map canvas so
  style/tile loading never flashes a light void; constant across UI themes.
- The public basemap default is `public/map-styles/mulk-dark.json`
  (CARTO Dark Matter raster; attribution flows through the compact
  AttributionControl). `MAPLIBRE_STYLE_URL` still overrides. The browser
  specs' `STYLE_HOST` constants point at this same-origin path.

## 2. Backend file map

### Geography module — `app/Modules/Geography/`

| File | Responsibility |
|---|---|
| `Http/Controllers/Public/MapExplorerController.php` | The core. `/map`, `/map/features`, `/map/search` (Phase 5 — a thin delegate to `MapSearchService`), `/invest`, `/invest/features`, `/invest/search`. Layer composition, caps, zoom gates, price-trend enrichment (`withPriceTrends`), boundary GeoJSON (`ringsToCoordinates` — the ONE lat/lng→[lng,lat] polygon conversion site), provider resolution (`resolveProvider` — Google without key silently downgrades to MapLibre with `fallback_reason: 'missing_key'`; `providerPageProps` is the only place the Google key is ever emitted). |
| `Concerns/HasCoordinates.php` | Shared trait (Area/Place/Project/CompanyBranch): `setCoordinates()` (writes `number_format(...,7)` — decimal casts read back as strings), boundary parsing, bbox cache sync (`syncDerivedGeometry`), `scopeWithinBox`/`scopeWithinRadius`. |
| `ValueObjects/Coordinates.php` | Immutable lat-first VO. `make($lat,$lng)`, range/null-island checks, `looksSwapped()` (fails Erbil bbox but swap passes), `toWkt()` (lng-first), `toGeoJson()` ([lng,lat]), `jsonSerialize()` ({lat,lng}). |
| `ValueObjects/BoundingBox.php` | `operatingArea()` reads Erbil bounds from `config/mulkihawler.php`: lat 35.90–36.50, lng 43.70–44.40. |
| `Support/Wkt.php` | PHP WKT parser/writer (POINT/POLYGON/MULTIPOLYGON/LINESTRING), longitude-first per OGC. |
| `Support/Polygon.php` | Point-in-polygon, centroid, area, RDP simplification (metre-based), winding normalisation. |
| `Support/Topology.php` | Self-intersection/overlap/containment predicates for `ValidWkt`. |
| `Support/Geodesy.php` | Haversine, bearing, destination projection. |
| `Models/Area.php` | Materialised-path hierarchy (`path`,`depth`), cycle/containment guards in `booted()`, boundary storage, `decimal:7` casts. |
| `Models/Place.php` | POI; coordinates NOT nullable; encrypted phone (never in map payloads). |
| `Services/AreaResolver.php` | Point→area resolution (bbox prefilter + ray-cast, most-specific published match). |
| `Services/AreaComparisonService.php` | Map Phase 6: the `/map/compare` composition — publication+ancestry gate (Phase 5's bulk shape, rows doubling as breadcrumbs), per-area AreaServiceSummary / AreaPriceIntelligence / one bulk areaMovement(), the §44 compatibility signature (exact-equality pre-gate on transaction/property_type/price_type + `IndexCalculator::change()` as arbiter AND arithmetic for price differences), `shared_source` honesty when two areas price from the same ancestor, and the deterministic facts list ({key, params}, Decimal-computed server-side). NO score, NO winner, NO weights. |
| `Services/MapSearchService.php` | Map Phase 5: the ONE unified trilingual search behind `GET /map/search`. Folds the query through `SoraniText::searchKey()` (the SAME normalizer that built every stored `search_key`; <2 meaningful chars → honest empty groups, not 422); per type, two bounded LIKE passes (prefix `key%` fills the 3×cap candidate budget before `%key%` may) with explicit `ESCAPE '!'` for SQLite/MariaDB parity and `!`/`%`/`_` bang-escaped; PHP re-rank exact(0) > name/alias-starts-with(1) > search_key-starts-with(2) > contains(3), ties on display name then slug; visibility = each surface's own public rule (area published+published ancestry via one bulk ancestor query that doubles as the breadcrumb; project published+real coords; place published+public+operating+duplicate-primary+`confidence != 'low'` — the PIN's reliability rule, since a search choice flies to the coordinate and expects the pin — whole group behind `places.database`). Caps 5 areas / 5 projects / 7 places. Never a geocoder. |
| `Services/AreaServiceSummary.php` | Map Phase 3 extraction of the Phase 2 grouped service counts (published+public+operating over DIRECT `area_id`; fixed group order; locale label chains) — the ONE counting implementation behind both the public Area profile and the `/location/resolve` payload's `area.services` key. |
| `Http/Controllers/Public/LocationResolveController.php` | `GET /location/resolve` (throttle `location-resolve` 30/min, `Cache-Control: no-store, private`): `lat`+`lng` OR `area={slug}` (mutually exclusive) → `{state: resolved\|no_data\|outside_coverage, area{slug,name,type,type_label,breadcrumb,services}, prices, prices_reason}`. Never persists coordinates; no nearest-area fallback. Consumed by the homepage location card AND (Phase 3) every /map area-selection entry path. |
| `app/Modules/Market/Services/AreaPriceIntelligence.php` | Map Phase 6 extraction of `LocationResolveController::priceIntelligence()` — the Wave 3 current-price lookup (market.indices gate, most-specific containment chain, published area-scoped indices valued by LatestReliableIndexValues, separate rows, absence never zero) behind ONE implementation with two layers: `resolve(Area)` keeps the (index, latest) models for the comparison's identity checks; `publicPayload()`/`for()` render the EXACT legacy row shape `/location/resolve` has always served. LocationResolveTest pins the extraction. |
| `app/Modules/Market/Http/Controllers/Public/MarketMapController.php` | Map Phase 4: `GET /map/market` (feature `market.intelligence`, own `map-market` limiter 60/min). Scopes to published areas WITH a boundary intersecting the bbox (the boundaries() intersection rule + the same 40 ceiling with detected `truncated`), then delegates wholesale to `MarketMovementService::areaMovement(transaction, window, ?property_type, areaIds)` — the movement engine's rules (reliable series, exact window pairing, `IndexCalculator::change`) with heat semantics: ONE deterministic first-by-key claim per area carrying its full identity; `property_type` absent = the spanning NULL-typed index ONLY (the honest "all"); an incomparable area has NO row — absence is "unknown", never flat/zero. |
| `Observers/ProjectGeometryObserver.php`, `PlaceObserver.php` | Re-resolve area / mark nearby-place snapshots stale on geometry change. |
| `Http/Requests/AreaRequest.php`, `PlaceRequest.php` | Coordinate + WKT validation; authorize via `geography.*` permissions. |
| `Rules/PublishableArea.php` | Manually chosen area must be published with published ancestry. |
| `Providers/GeographyServiceProvider.php` | Observers + named rate limiters: `map-features` 60/min, `map-search` 30/min (separate buckets — RC9 fix). |
| `Routes/web.php`, `admin.php` | Public routes (feature-gated `map.explorer` / `map.investment`) and admin CRUD. |

Related outside the module: `app/Modules/Projects/Models/Project.php`
(`hasRequiredGeometry()`, publish-transition geometry enforcement),
`ProjectPrice.php` (asking/verified price claims, `requiresQualifier()`),
`app/Modules/Projects/Rules/ValidWkt.php` (REJECT-NEVER-REPAIR, shared by all
doors), `app/Modules/Market/Models/MarketIndex(Value).php` (area price layer),
`app/Http/Middleware/HandleInertiaRequests.php` (shared `map` prop:
`{provider, style_url}` only), `app/Modules/Operations/Services/EnvironmentSettings.php`
+ `SystemSettingsController` (allow-listed `.env` writer for `MAP_PROVIDER`,
`MAPLIBRE_STYLE_URL`, secret `GOOGLE_MAPS_API_KEY`),
`app/Modules/Install/Services/StepValidator.php` (install-wizard map step).

## 3. API endpoints

| URI | Method → response | Notes |
|---|---|---|
| `GET /map` | `MapExplorerController::index` → Inertia `Public/Map/Explorer` | Gated `feature:map.explorer`. Props: style_url, provider, layers, categories, limits; google_key only if Google resolved. |
| `GET /map/features` | `::features` → JSON | `throttle:map-features`. Required `north/south/east/west`; optional `layers[]`, `categories[]`, `types[]`, `zoom`, `center_lat/center_lng/radius_km`, `polygon[]` (3–200 points). Point layers = plain `{lat,lng,...}` arrays; `boundaries` = GeoJSON FeatureCollection; `truncated` flag; `distance` km straight-line. Caps: 300/layer, 40 boundaries. Missing bounds → 422. |
| `GET /map/compare` | `MapCompareController` → `{filters, windows, property_types, movement, areas[], market_comparison, facts[]}` | Map Phase 6, gated `feature:map.explorer`, own `throttle:map-compare` (30/min). `areas[]` 2–3 DISTINCT public slugs (422 on 1/4+/duplicates; 404 for any missing/unpublished/unpublished-ancestry slug, undisclosed which), `transaction/period/property_type` = MarketMapRequest's vocabularies. Composes ONLY existing authorities via `AreaComparisonService`: AreaServiceSummary counts (null + `services_reason` when `places.database` off — never zero), AreaPriceIntelligence payloads, one bulk `areaMovement()` (movement `feature_disabled` envelope when `market.intelligence` off). Direct comparison requires the full evidence identity (transaction, property_type, exact price_type, family, currency, basis, methodology_version) verified through `IndexCalculator::change()` — asking≠verified, no FX, no winner/score; `facts` are localization keys + server-computed Decimal params. Navigation-safe area rows (cached bbox, no WKT/ids/phones). |
| `GET /map/search` | `::search` → `{query, groups:{areas,projects,places}}` | Map Phase 5, gated `feature:map.explorer`, own `throttle:map-explorer-search` (30/min — NEVER the invest `map-search` bucket). `q` required 2–80 chars; delegates to `MapSearchService`. Area rows: `kind/slug/name/type/type_label/breadcrumb/lat/lng/bounds` (cached bbox — never WKT); project rows: `kind/slug/name/project_type/area_name/area_slug/lat/lng`; place rows: `kind/slug/name/category/category_name/area_name/lat/lng`. No phones, no OSM review fields. Caps 5/5/7. City-wide by design — no spatial params. |
| `GET /invest` | `::invest` → Inertia `Public/Map/Invest` | Gated `feature:map.investment` (independent of map.explorer). |
| `GET /invest/features` | `::investFeatures` → JSON | Same shape + `project_boundaries` GeoJSON + per-project `price_from`, `price_currency`, `trend`, `trend_percent`. Layers server-forced to `projects`+`areas` regardless of request. Project boundaries zoom < 14 → `[]`. The explorer payload must NEVER gain these enrichments (pinned). |
| `GET /invest/search` | `::investSearch` → `{results:[{id,slug,name,area,lat,lng}]}` | `throttle:map-search`. Published + coordinates required, cap 8, 422 on 1-char term. |
| Admin `/admin/areas...`, `/admin/places...`, `/admin/places/categories...` | Inertia CRUD | `geography.*` permissions; publish transition separately checks `geography.areas.publish`. |

No dedicated homepage endpoint — `HomeProjectMap.vue` reuses the above.

## 4. Data contracts

- **Coordinate order**: lat-first in DB/VO/requests/point-JSON; lng-first only
  in WKT + GeoJSON. Conversions live in `ringsToCoordinates()` (polygons) and
  `Coordinates::toGeoJson()` (points) — nowhere else.
- **Storage** (dual-engine): DECIMAL(10,7) lat/lng (indexed:
  `projects_bbox_index`, `places_bbox_index`, areas `(latitude,longitude)`),
  `boundary_wkt` LONGTEXT source of truth, bbox cache columns on areas/projects,
  MySQL `GEOMETRY` columns declared but unpopulated/unindexed (do not query).
- **Nullability**: projects lat/lng nullable (geometry enforced at publish);
  places NOT nullable; a coordinate-less project never appears on a map.
- **Trend pipeline** (`withPriceTrends`, invest only): one query per viewport,
  newest `project_prices` row = current; next older row **of the same
  price_type** = candidate previous; accepted only if currency matches AND
  both `price_from` non-null AND previous > 0. `flat` iff |change| < 0.05%.
  Everything else → `unknown` + null percent. Area `prices` layer is separate
  (MarketIndex/MarketIndexValue, two-query grouped-MAX, reliability filters).
- **Feature flags**: `map.explorer` and `map.investment` both default OFF
  (`config/features.php`); routes register regardless (flag gates requests,
  not registration). Zero seeded areas/GeoJSON fixtures ship with the app —
  test geometry is inline in tests and `seed-browser-fixtures.php`.

## 5. Known constants scattered in components (not centralised)

Erbil centre `{lat:36.19, lng:44.009}` and various bboxes are independently
hardcoded in `Explorer.vue` (fallback box `36.35/36.05/44.15/43.85`),
`Invest.vue` (same + maxBounds), `HomeProjectMap.vue` (`ERBIL_BOX`
`36.42/35.95/44.32/43.7`), both MapPickers (fallback centres), and
`config/mulkihawler.php` (operating area `36.50/35.90/44.40/43.70`). They are
intentionally slightly different per use (fetch viewport vs validation area) —
do not "unify" them without checking each consumer, but do not add new
hardcoded copies either.
