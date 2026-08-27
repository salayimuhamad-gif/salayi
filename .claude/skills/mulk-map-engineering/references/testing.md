# Mulk map testing — suites, commands, and MCP-free browser verification

## 1. Test inventory

### PHP Feature (PHPUnit) — `php artisan test --filter <Name>`

| Test | Pins |
|---|---|
| `tests/Feature/MapExplorerTest.php` | `/map` + `/map/features` end to end: flag gating, layer filtering, provider resolution + Google→MapLibre `missing_key` fallback, privacy (no phones/portfolio keys in payloads), viewport/radius/polygon filtering, 422 input safety, boundary GeoJSON `[lng,lat]` order regression, zoom gating, malformed-WKT-skips-one-boundary, holes/MultiPolygon rendering regressions, price-layer N+1 query-count guard, honest `truncated` at 40 boundaries. |
| `tests/Feature/MapTrendSemanticsTest.php` | The four-valued trend contract: comparable observations → up/down/flat; single/zero observations → unknown (never flat); mixed currencies → unknown (fake thousand-percent regression); mixed price types → unknown; **null previous figure → unknown (the fabricated −100% regression)**; `/invest/features` layer allowlist; admin `map_ready` flag. |
| `tests/Feature/InvestmentMapTest.php` | `/invest` promises: projects+areas only (server-enforced even when other layers are requested by name), published+coordinated only, price/trend enrichment belongs to invest alone (explorer payload must not gain it), search validation, project boundaries zoom-gated + published-only. |
| `tests/Feature/MapZeroStateTest.php` | Zero data ≠ blank page: pages render, features endpoints return valid empty FeatureCollections (envelope never omitted), flags default false, admin flag toggle opens the route end to end. |
| `tests/Feature/MapSearchTest.php` | Map Phase 5 `GET /map/search`: flag gating (404 off), 2–80 length bounds (422), a query folding to noise answers honest empty groups; Sorani/Arabic/English/alias and keyboard-variant Sorani spellings all find the entity through the ONE `search_key`; `%`/`_` can never act as wildcards; the three row shapes are exact key lists (no WKT, no phone anywhere in the payload); every exclusion gate (unpublished area/project/place, draft-parent ancestry, null-coords project, non-public/duplicate-secondary/closed places, `places.database` off); rank beats alphabetics (exact > name-prefix > contains, alias-exact outranks name-contains); caps 5/5/7; query echo. Runs on both CI database lanes. |
| `tests/Feature/MapRtlTextPluginTest.php` | RTL plugin structural contract: exact-pinned dependency, `?url` import, Vite alias, registration guarded and before Map construction, no CDN references, built assets contain both `mapbox-gl-rtl-text-*.js` and `maplibre-gl-worker-*.js` ("its absence was the previous map outage"). |
| `tests/Feature/GeometryWorkflowTest.php` | Admin geometry HTTP round trips preserve lat/lng/WKT verbatim; door parity (bow-tie polygon rejected at every door; a valid boundary cannot be replaced by an invalid one). |
| `tests/Feature/AreaHierarchyTest.php` | Materialised-path hierarchy: cycle prevention, depth updates on moves, atomic refusals. |
| `tests/Feature/ReleaseBrowserSpecClosureTest.php` | Every `tests/Browser/*.spec.ts` must be registered in `scripts/release/release_gates.py` — **adding a new map spec requires extending that registry** or final release refuses to package. |

### JS unit — `node tests/js/run.mjs`

Framework-free by design (no vitest/jest — do not add one). The runner
type-checks with `tsc --noEmit` first, then builds via a dedicated Vite config,
then executes:

- `tests/js/geojson.test.ts` — `lib/map/geojson.ts` conversions/winding/hole
  regressions + `boundaryBounds` (Phase 3 camera-fit extent: polygon /
  multipolygon / holes-don't-widen / degenerate→null) + Google adapter
  lifecycle (script load/failure/stall, `gm_authFailure` chain, listener
  cleanup).
- `tests/js/trend.test.ts` — `lib/map/trend.ts` (colours, glyphs,
  `trendHasClaim`, `normaliseTrend` never yields `flat` for garbage).
- `tests/js/wizard.test.ts` — `lib/wizard/geometry.ts` (imports the
  production module directly — a past version copied helpers and passed while
  the component was broken; never test a copy).

### Standalone PHP geometry — `php tests/Standalone/run.php`

Framework-free coverage of `Polygon`, `Wkt`, `Topology`, `Coordinates`
(`tests/Standalone/GeometryTest.php`, `SimplifierTest.php`,
`WktValidationTest.php`) — runs without Composer/Laravel bootstrap.

### Browser (Playwright) — `npx playwright test [tests/Browser/<spec>]`

| Spec | Pins |
|---|---|
| `map-production.spec.ts` | The working-style path (serves a deterministic zero-network style for `**/map-styles/mulk-dark.json` — the same-origin fallback path; the provider-failure test aborts that route explicitly): homepage map becomes a real painted canvas in ckb/ar/en; canvas `position:absolute` regression; `/map` loading veil leaves; honest failure + live list when style unavailable; all four trend semantics on `/invest` markers from the real features response; marker click on the actual canvas selects the project; admin picker recovers from `v-show`-hidden tab; click-to-place yields Erbil-plausible coords; mobile map/list tab switch with no rebuild/no loader. |
| `map-area-selection.spec.ts` | Map Phase 3 on `/map` (deterministic style; pixel geometry derived from the default camera 36.19/44.009 z11, documented in the file header): polygon click opens the Area Intelligence card from the seeded ring on every viewport (desktop float vs bottom-sheet dialog); ckb/ar/en identity + profile route; the in-ring project marker's click is never stolen by the polygon; area list rows select in place (aria-pressed sync); empty-map click clears only the selection; live location with MOCKED browser geolocation (probe counts `getCurrentPosition` — zero before the click) resolves through `/location/resolve`, denial keeps the map usable, outside-coverage answers honestly with no nearest guess; a card service group enables the real places layer + category chip. |
| `map-market-heatmap.spec.ts` | Map Phase 4 on `/map`: entering Market mode auto-enables the areas layer and fetches `/map/market`; the seeded ring's twelve-month +5.04% sale pair paints green (pixel-sampled per locale on desktop-1440x900, cleared again on mode exit); window chips disable exactly what monthly evidence cannot support (7d/1m off, 1y on); rent and a typed category answer with an honest localized reason and zero rows — never a tint; a painted polygon still opens the Phase 3 card; 360x800 layout holds. Fixture: one 2025-07 value (1190) added to `browser-ankawa-sale` — a year-apart pair, NULL property_type, so every exact-count assertion in market-movement.spec.ts is untouched. |
| `map-rtl.spec.ts` | RTL plugin behavioral half: registered exactly once across two map surfaces in one SPA session (zero console errors); plugin served same-origin from `/build/assets/`; harness page proves `getRTLTextPluginStatus() === 'loaded'` with Arabic + Sorani labels using the REAL built chunks. |
| `map-search.spec.ts` | Map Phase 5 on `/map`: the unified combobox finds the seeded area with Sorani (choice lands in the SAME Phase 3 card via `/location/resolve`), the seeded project with English (`Empire Investment Tower` on `browser-invest-tower` — the fly proven by the zoom-15 follow-up `/map/features` bracketing the stored coordinate; context strip carries `/en/projects/browser-invest-tower`) and the seeded pharmacy with Arabic (places layer + ONLY the pharmacy category enabled, zoom-16 follow-up, `/ar/places/browser-poi-pharmacy`); a fabricated delayed answer for the older shorter query never overwrites the newest; ArrowDown/Up walk `aria-selected` + `aria-activedescendant`, Enter selects, Escape closes; Market mode with a rent filter survives an area search untouched (legend + filters standing); at 360x800 and 390x844 the open dropdown adds no sideways scroll, rows meet touch-target size, a tap opens the bottom sheet and closing it hands the map back. Fixture: `name_en` on `browser-invest-tower` + a `search_key` sync pass for the raw-upserted projects (`syncSearchKey()` + `saveQuietly()`). |
| `invest.spec.ts` | `/invest` under fully hermetic network (tiles never load — the degraded path): map container occupies real space before any tile (blank-map defect class), list renders with trend badge, search opens floating card with real project link, filters narrow, no overflow/duplicate ids — 3 locales × 5 viewports. |
| `public-home.spec.ts` | Homepage: the only sanctioned canvas is the MapLibre canvas. |

Harness facts (`tests/Browser/support/`):

- `harness.ts` — `hermeticNetwork` auto-fixture (all non-origin requests →
  empty body) and `diagnostics` fixture (**any console error/Vue warning/page
  error fails the test**). Helpers: `LOCALES` (ckb/ar/en),
  `expectNoHorizontalOverflow`, `expectNoDuplicateIds`, `expectTouchTargets`.
- `seed-browser-fixtures.php --confirm-disposable-database` — seeds admin/MFA
  users, enables `map.explorer`/`map.investment`, creates 4 published projects
  (`browser-invest-tower/-villa/-bazaar/-court`) inside Erbil's bbox with
  price histories engineered to yield exactly one project per trend value
  (up +4.8%, down −10%, flat, unknown). Refuses non-local/non-empty DBs.
- `playwright.config.ts` — baseURL `http://127.0.0.1:8100` (or
  `PLAYWRIGHT_BASE_URL`), auto-starts `php artisan serve` with
  `PHP_CLI_SERVER_WORKERS=10` (`PLAYWRIGHT_NO_SERVER=1` to skip), `workers: 1`,
  five viewport projects (360×800, 390×844, 768×1024, 1366×768, 1440×900),
  honors `PLAYWRIGHT_CHROMIUM_PATH` for environments that can't download
  browsers, writes JSON report to `storage/browser-acceptance/`.
- `global-setup.ts` refuses a single-worker PHP server (map/asset loading
  starves and mimics product races).

CI (`.github/workflows/ci.yml`): `php` job runs full PHPUnit; `frontend` job
runs typecheck + `node tests/js/run.mjs` + build + frontend-guard; `e2e` job
seeds fixtures and runs the whole Playwright suite across all viewports.

## 2. Browser verification WITHOUT Playwright MCP

The project-scoped Playwright MCP (`.mcp.json` → `npx @playwright/mcp`) cannot
start in environments where npm egress is blocked, and MCP tools load only at
session start. **MCP availability must never gate map work.** Prefer
`mcp__playwright__*` tools when they exist; otherwise drive the browser with
direct Playwright scripts:

- This class of cloud environment ships a globally installed `playwright`
  library and a pre-installed Chromium with `PLAYWRIGHT_BROWSERS_PATH` already
  exported. Resolve the library **environment-relatively** — never hardcode an
  absolute library or browser path into application code, committed tests, or
  config:

```bash
# Throwaway verification script (keep in scratchpad, never commit):
NODE_PATH="$(npm root -g)" node map-check.cjs
```

```js
// map-check.cjs — skeleton covering the §H checklist
const { chromium } = require('playwright');

const BASE = process.env.PLAYWRIGHT_BASE_URL || 'http://127.0.0.1:8100';
const LOCALES = ['ckb', 'ar', 'en'];
const VIEWPORTS = { desktop: { width: 1440, height: 900 }, mobile: { width: 390, height: 844 } };

(async () => {
  const browser = await chromium.launch();           // 1. Chromium launches
  const failures = [];
  for (const [vpName, viewport] of Object.entries(VIEWPORTS)) {
    for (const locale of LOCALES) {
      const page = await (await browser.newContext({ viewport })).newPage();
      const consoleErrors = [], failedRequests = [];
      page.on('console', m => m.type() === 'error' && consoleErrors.push(m.text()));
      page.on('requestfailed', r => failedRequests.push(`${r.url()} ${r.failure()?.errorText}`));

      await page.goto(`${BASE}/${locale}/invest`, { waitUntil: 'networkidle' });
      // Map painted, not blank: canvas exists and has a real box
      const canvas = page.locator('.maplibregl-canvas');
      await canvas.waitFor({ timeout: 20000 });
      const box = await canvas.boundingBox();
      if (!box || box.width < 200 || box.height < 200) failures.push(`${vpName}/${locale}: blank/mis-sized map`);
      // Markers render (features source populated → rendered features queryable)
      // Marker click / navigation / polygons: interact via page.mouse + assert
      // the selection card and its /projects/{slug} link, per the repo specs.
      await page.screenshot({ path: `shot-${vpName}-${locale}.png`, fullPage: false });
      if (consoleErrors.length) failures.push(`${vpName}/${locale} console: ${consoleErrors.join('; ')}`);
      const realFailures = failedRequests.filter(u => u.includes(new URL(BASE).host));
      if (realFailures.length) failures.push(`${vpName}/${locale} requests: ${realFailures.join('; ')}`);
    }
  }
  await browser.close();
  if (failures.length) { console.error('FAIL\n' + failures.join('\n')); process.exit(1); }
  console.log('PASS');
})();
```

- The app must be running: `php artisan serve --host=127.0.0.1 --port=8100`
  with seeded fixtures (see harness facts above) and a built frontend
  (`npm run build`, or the committed `public/build`). If the full app cannot
  be booted in the current environment, verify what you can (unit + Feature
  suites, plus a Chromium smoke check) and say exactly which browser checks
  were not run — never claim browser verification you didn't perform.
- The repo's own suite (`npx playwright test`) needs `@playwright/test` from
  `node_modules`; if npm installs are blocked and `node_modules` is absent,
  fall back to the direct-script pattern above with the global library.
- Take screenshots for every visual claim (desktop + mobile, RTL + LTR) and
  attach them to your report.

## 3. What to run for which change

| Change touches | Minimum suites |
|---|---|
| `lib/map/*`, adapter behavior | `node tests/js/run.mjs` + browser checklist on all affected surfaces |
| Trend logic (either side) | `MapTrendSemanticsTest` + `tests/js/trend.test.ts` + invest browser check |
| Geometry/WKT (any of the three implementations) | `tests/js/run.mjs` + `php tests/Standalone/run.php` + `GeometryWorkflowTest` |
| `MapExplorerController` / endpoints | `MapExplorerTest` + `InvestmentMapTest` + `MapZeroStateTest` |
| Vite/worker/RTL/build wiring | `MapRtlTextPluginTest` + `npm run build` (includes frontend-guard) + `map-rtl.spec.ts` if runnable |
| Admin pickers / wizard | `tests/js/wizard.test.ts` + `GeometryWorkflowTest` + admin-picker browser checks |
| New Browser spec added | register it in `scripts/release/release_gates.py` (see `ReleaseBrowserSpecClosureTest`) |
