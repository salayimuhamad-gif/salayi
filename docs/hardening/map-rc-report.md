# Map Release-Candidate Hardening — Implementation Report

Branch: `hardening/map-release-candidate`, from exactly
`main@b611c0245b50dccc25a1b9a901ffbacb215b6806`. Companion audit:
`docs/hardening/map-rc-audit.md`. Nothing from any prior discussion was
assumed applied; every change below was implemented and verified in this
repository.

## What changed

### F-1 — Reproducible packaging Composer cache (fixed)

`.github/workflows/ci.yml`, `package` job: an `actions/cache@v4` step for
`~/.composer/cache` with key `composer-8.3-${{ hashFiles('composer.lock') }}`
— byte-for-byte the php-8.3 matrix entry's cache identity, inserted
immediately before the job's existing `composer install`. No dependency
file, no `composer update`, no retry/suppression logic, and no
Final-delivery determinism command, release hash, baseline identity,
previous-production ref, `audit_archive.py` constant or release semantic
was touched.

### F-2 — `/map/market` and `/map/compare` HTTP 429 honesty (fixed)

`resources/js/Pages/Public/Map/Explorer.vue`:

- `marketPhase` and `comparePhase` gain an explicit `rate_limited` member;
  both fetches branch on `response.status === 429` before the generic
  `!ok` error branch.
- Market: the last painted heat and `marketData` stand; the notice slot
  speaks `map.market.rate_limited`; the error toast and its Retry never
  appear for throttling.
- Compare: the A/B/C slots, the last `compareData` and every shared
  filter stand; `CompareAreasPanel.vue` renders a dedicated banner
  (`map.compare.rate_limited`) OUTSIDE the state chain, so the last
  comparison keeps rendering beneath it; the error card and its Retry
  never appear for throttling.
- Throttling is never presented as empty/no-data/insufficient evidence.

### F-5 — search / location-intelligence 429 honesty (already complete)

Verified present in the base commit (Phase 5 search `rate_limited` phase +
`map.discovery.rate_limited`; Phase 3 resolve `rate_limited` phase in
`AreaIntelligenceCard`; `home.location.rate_limited` notice). No change in
this branch.

### F-6 — `/map/features` HTTP 429 honesty (found and fixed)

`resources/js/Pages/Public/Map/Explorer.vue`:

- new `featuresRateLimited` state, written only under the existing
  generation-token guard — **no AbortController added**, per the approved
  scope; the token protection is unchanged.
- 429 keeps every piece of loaded state (features, boundaries, list,
  layers, categories, radius, drawn ring) and shows a compact "wait" toast
  (`map.states.rate_limited`); `loadError` remains the voice of real
  failures only; success clears the throttle voice, a real failure
  replaces it.
- the zero-state map overlay and the list's empty block are gated on the
  throttle state, so a throttled first load never reads as an empty city.
- when the active layer set becomes empty, the existing no-request path in
  `load()` clears the throttle state explicitly without issuing a request.

### Localization

`lang/{ckb,ar,en}/map.php` gain three neutral throttle strings:
`map.states.rate_limited`, `map.market.rate_limited`,
`map.compare.rate_limited`. The Arabic and Sorani Compare strings are the
approved wordings verbatim:

- ar: «طلبات كثيرة — انتظر قليلاً؛ ستبقى المناطق التي اخترتها كما هي.»
- ckb: «داواکاری زۆرە — کەمێک چاوەڕوان بە؛ ناوچە هەڵبژێردراوەکان هەر دەمێننەوە.»

The Sorani Market/features strings were composed to match the product's
existing concise throttle voice («داواکاری زۆرە — کەمێک چاوەڕوان بە؛ …»,
consistent with the Phase 5 «کەمێک چاوەڕوان بە» precedent).

## Test changes (existing specs only — no new spec file)

- `tests/Browser/map-production.spec.ts` — one new test: an injected
  `/map/features` 429 shows the throttle toast (never the error alert or
  retry), keeps the stale seeded list row and the pressed layer chips;
  switching the layers off proves the empty-set path clears the throttle
  state with **zero** requests (request-counted); un-routing recovers.
- `tests/Browser/map-market-heatmap.spec.ts` — one new test: with the
  +5.04% heat painted, an injected `/map/market` 429 shows the dedicated
  notice, no error toast/retry, filters and mode stand, and the paint is
  pixel-sampled unchanged; recovery clears the notice.
- `tests/Browser/map-compare.spec.ts` — one new test: with a two-area
  comparison rendered, an injected `/map/compare` 429 shows the verbatim
  Sorani banner, no error card/retry, slots + last grid + filters stand;
  recovery clears the banner.

### Diagnostics strategy

The `diagnostics` fixture is kept on every new test. Each spec defines a
local `RATE_LIMIT_CONSOLE` constant pinned to the **measured** Chromium
line for a route-fulfilled 429
(`Failed to load resource: the server responded with a status of 429 (Too Many Requests)`)
and a `consumeRateLimitConsole(diagnostics, expectedCount)` helper that
asserts the exact count of injected entries and splices exactly those out
of `diagnostics.consoleErrors` before the fixture's teardown assertion.
The shared `IGNORED_CONSOLE` allowlist is **unchanged**; any other console
error, Vue warning or page error still fails the test.

## Privacy follow-up

`/location/resolve` remains a GET in this PR — **no API contract change**.
Its `lat`/`lng` query parameters MAY be recorded by normal HTTP access
logging depending on server configuration; the production Hostinger
access-log configuration is **NOT VERIFIED HERE**.

**PRIVACY FOLLOW-UP REQUIRED: YES.**

## Release-engineering follow-up

`final-inputs/e075317` is not modified and the Final Release
previous-production baseline is not rolled in this PR; a separate PR after
this hardening merges (with green main CI) will handle it.

**RELEASE-ENGINEERING FOLLOW-UP REQUIRED BEFORE FINAL RELEASE: YES.**

## Exact files changed

- `.github/workflows/ci.yml` (F-1 cache step only)
- `resources/js/Pages/Public/Map/Explorer.vue` (F-2, F-6)
- `resources/js/Components/Public/CompareAreasPanel.vue` (F-2 banner + phase type)
- `lang/en/map.php`, `lang/ckb/map.php`, `lang/ar/map.php` (three throttle strings each)
- `tests/Browser/map-production.spec.ts` (F-6 test + local console consumer)
- `tests/Browser/map-market-heatmap.spec.ts` (F-2 market test + local console consumer)
- `tests/Browser/map-compare.spec.ts` (F-2 compare test + local console consumer)
- `docs/hardening/map-rc-audit.md`, `docs/hardening/map-rc-report.md` (this documentation)

No PHP application code, route, limiter, schema, dependency or feature
flag changed.

## Gates executed on this branch (local)

- `php scripts/lang-parity.php` — PASS
- `php scripts/lang-usage.php` — PASS
- `php scripts/frontend-guard.php --skip-freshness` — PASS
- `php scripts/security-audit.php` — PASS (45 checked, 0 failed)
- `python3 scripts/release/release_gates.py --verify-spec-closure tests/Browser` — PASS
- `php artisan test` focused map suites (MapExplorerTest, MarketMapTest,
  MarketMovementTest, MapCompareTest, MapSearchTest, LocationResolveTest,
  MapZeroStateTest, InvestmentMapTest, ReleaseBrowserSpecClosureTest) — PASS
- Vue template balance checks on both edited components — PASS
- Live browser probe against CI-built assets: injected-429 matrices for
  features / market / compare (state, preserved data, recovery, exact
  console entries) — executed after the asset-refresh round trip; results
  recorded in the PR conversation.

## NOT executed here (stated honestly)

- TypeScript (`vue-tsc`), ESLint and the frontend unit suite cannot run in
  this sandbox (no local `node_modules`; npm egress is blocked) — they run
  in the CI Frontend job, which must be green on the final head.
- The full Playwright matrix (5 viewports × 3 locales) runs only in the CI
  E2E job — the three new tests are validated there; locally only the
  direct-Playwright probes above were run.
- The F-1 cache step can only prove itself in CI (an Actions runner); it
  is asserted here by inspection against the PHP job's step it mirrors.
- ~125 known local PHP test failures caused by the sandbox's missing
  ext-bcmath are pre-existing environment noise; the affected suites were
  run through the bc polyfill harness instead and pass.
- Production Hostinger access-log configuration: NOT VERIFIED HERE.
