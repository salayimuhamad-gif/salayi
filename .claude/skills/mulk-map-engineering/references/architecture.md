# Mulk map architecture — file map, endpoints, data shapes

Derived from direct codebase analysis (2026-08). File paths are repo-relative.
When code and this document disagree, the code wins — then update this document.

## 1. Frontend file map

### Adapter core — `resources/js/lib/map/` (authoritative; never duplicate)

| File | Responsibility |
|---|---|
| `types.ts` | `MapAdapter` interface (`ready()`, `getBounds()`, `getZoom()`, `setPoints()`, `setBoundaries()`, `setPin?()`, `flyTo()`, `resize()`, `destroy()`), `AdapterOptions`, `AdapterEvents`, `PointFeature`, `PriceTrend = 'up'|'down'|'flat'|'unknown'`. |
| `index.ts` | `createMapAdapter(provider, options)` — the only sanctioned constructor. Converts Google construction failure into MapLibre fallback with `fallbackReason`. |
| `maplibre.ts` | `MapLibreAdapter`. Worker URL wiring (`?worker&url` import + `setWorkerUrl` before first Map), RTL plugin registration (guarded by `getRTLTextPluginStatus() === 'unavailable'`), style resolution (options.styleUrl → `plain` inline style → demotiles default), clustered `features` source + separate `boundaries` source, layers `clusters`/`cluster-count`/`unclustered`/`trend-markers`/`point-labels`/`point-names`/`boundary-fill`/`boundary-line`, canvas-drawn trend icons, draggable pin, ResizeObserver, bounded `ready()` (load vs error vs 20s deadline). |
| `google.ts` | `GoogleMapsAdapter` (optional provider). Script injection with timeout, `gm_authFailure` save/restore with ownership guard, `tilesloaded`-gated readiness, custom grid clusterer (parity with MapLibre budgets), MultiPolygon via `toGooglePolygons`, exhaustive `destroy()`. Never emits `onMarkerClick` (documented gap). |
| `geojson.ts` | Pure, unit-tested GeoJSON↔Google conversion: `toPolygonComponents` (MultiPolygon → per-component `{exterior, holes[]}`), `normaliseComponent` (RFC-7946 winding for Google's even-odd hole rendering), `signedArea`, `isClockwise`, `ringToPath` ([lng,lat] → {lat,lng}). |
| `trend.ts` | Single source of trend presentation: `trendIconName`, `trendColour`, `trendArrowGlyph`, `trendHasClaim`, `normaliseTrend` (garbage/null → `unknown`, never `flat`). Colours: up `#15803d`, down `#b91c1c`, flat `#b45309`, unknown `#0f3e59`. |

### Geometry helpers (two JS WKT implementations + one PHP — keep in sync)

| File | Responsibility |
|---|---|
| `resources/js/lib/geometry.ts` | Simple picker's WKT: `parsePolygonRing`, `polygonToWkt` (POLYGON only, single ring, no holes/MultiPolygon), `ringCentroid` (shoelace, must match PHP), `ringBounds`. |
| `resources/js/lib/wizard/geometry.ts` | Wizard picker's structural editor: `Component = {exterior, holes[]}`, `fromWkt`/`toWkt` (POLYGON + MULTIPOLYGON with holes; `toWkt` returns `null` for invalid geometry — refuses to serialise), `validateGeometry` (`too_few_vertices`, `duplicate_vertices`), vertex/hole CRUD, `toRenderedFeatures`, plus unrelated `moveItem`/`createThrottle`/`createRequestGate` utilities in the same file. |

### Map surfaces (consumers)

| File | Surface |
|---|---|
| `resources/js/Pages/Public/Map/Explorer.vue` | `/map` public explorer: multi-layer, viewport fetch of `/map/features` (250ms debounce on moveend), always-rendered list, radius search, polygon draw-search, one-shot geolocation (`getCurrentPosition`, denial = recoverable notice), provider fallback UI, mobile map/list tabs. List is the navigation path (no `onMarkerClick` wired — intentional). |
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

## 2. Backend file map

### Geography module — `app/Modules/Geography/`

| File | Responsibility |
|---|---|
| `Http/Controllers/Public/MapExplorerController.php` | The core. `/map`, `/map/features`, `/invest`, `/invest/features`, `/invest/search`. Layer composition, caps, zoom gates, price-trend enrichment (`withPriceTrends`), boundary GeoJSON (`ringsToCoordinates` — the ONE lat/lng→[lng,lat] polygon conversion site), provider resolution (`resolveProvider` — Google without key silently downgrades to MapLibre with `fallback_reason: 'missing_key'`; `providerPageProps` is the only place the Google key is ever emitted). |
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
