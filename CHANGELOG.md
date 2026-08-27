# Changelog

All notable changes to this project. Format follows Keep a Changelog;
versioning is `MAJOR.MINOR.PATCH-step<N>` until the roadmap completes.

## [Unreleased] — Map Phase 5: unified trilingual map search

**Status: NOT DEPLOYED.** No schema change, no migration, no new
dependency; authentication, Telegram/WhatsApp, feature-flag semantics,
the OSM import architecture, market calculation and release tooling
untouched. There is ONE text normalizer: the incoming query folds
through the same `SoraniText::searchKey()` that built every stored
`search_key` — no parallel index, no second Kurdish normalization, no
external geocoder.

### Added
- `GET /map/search` — unified search over MULK's own Areas, Projects and
  Places (gated `feature:map.explorer`, its own `map-explorer-search`
  limiter at the interactive 30/min so explorer autocomplete neither
  spends nor is starved by the invest search or the viewport budgets).
  `q` is required, 2–80 chars; a query that folds to fewer than two
  meaningful characters answers honest empty groups, not an error.
  Response rows carry navigation-safe fields only: areas
  (`slug/name/type/type_label/breadcrumb/lat/lng` + the cached bbox as
  `bounds` — never boundary WKT), projects
  (`slug/name/project_type/area_name/area_slug/lat/lng`), places
  (`slug/name/category/category_name/area_name/lat/lng`); no phone
  fields, no OSM review metadata. Caps 5 areas / 5 projects / 7 places.
- `MapSearchService` — bounded, portable, deterministic: per type, two
  `search_key LIKE` passes with explicit `ESCAPE '!'` (identical on
  SQLite and MariaDB; `!`/`%`/`_` escaped so wildcards can never act as
  wildcards), prefix candidates filling a 3×cap budget before contains
  candidates may, then a small PHP re-rank — exact name/alias > name or
  alias starts-with > combined-key starts-with > contains, ties on
  display name then slug. Visibility is each surface's own public rule:
  areas published WITH fully published ancestry (one bulk ancestor query
  that doubles as the breadcrumb), projects published with real
  coordinates, places published + public + operating + duplicate-primary
  with the map pin's reliability rule (`confidence != 'low'` — a search
  choice flies to the coordinate and promises the pin, which the places
  layer refuses to draw for a low-confidence coordinate) and the whole
  group only while `places.database` is enabled.
- A premium glass **search combobox** on `/map` (compact above the map,
  full-width on phones): 300ms debounce, AbortController + attempt-token
  race guard (older answers can never overwrite the newest query),
  grouped results labelled with the existing layer vocabulary, real
  keyboard support (ArrowDown/Up walk `aria-activedescendant`, Enter
  selects, Escape closes), localized empty/error/rate-limit states
  inside the dropdown — the map is never replaced, nothing `alert()`s.
- Search selection is **navigation**: an Area result funnels into the
  Phase 3 canonical `selectArea()` — same polygon highlight, same Area
  Intelligence card — using the response's cached bbox/centroid as the
  camera fallback when the polygon is outside the loaded viewport (the
  geometry then arrives through the ordinary viewport fetch). A Project
  or Place result clears any open Area card (no contradictory selection),
  enables its layer (a Place also enables ONLY its own category — never
  all, never disabling others), flies to the stored coordinate (zoom
  15/16), and leaves a compact glass context strip with the real
  `/projects/{slug}` / `/places/{slug}` route. Explore/Market mode, every
  market filter, and the visitor's radius/drawn-area state stay exactly
  as they were — text search is city-wide by design and sends no spatial
  parameters.
- `map.discovery.*` strings (ckb/ar/en) for the control's label,
  placeholder, states and actions.
- Tests: `MapSearchTest` (trilingual + alias + keyboard-variant matching,
  wildcard escaping, every exclusion gate, exact row shapes, caps,
  deterministic ranking, both CI database lanes) and
  `map-search.spec.ts` (the three navigation contracts across ckb/en/ar,
  the stale-response race, the keyboard combobox, Market-mode
  preservation, and both phone widths), with its registry entry in
  `scripts/release/release_gates.py`. Browser fixtures gain `name_en`
  on `browser-invest-tower` and a `search_key` sync pass for the
  raw-upserted fixture projects.

## [Unreleased] — Map Phase 4: market heatmap & visual market intelligence

**Status: NOT DEPLOYED.** No schema change, no migration, no new
dependency; authentication, Telegram/WhatsApp, feature-flag semantics,
the OSM import architecture and release tooling untouched. No second
market calculation engine exists: every painted direction is
`IndexCalculator::change()`'s verdict, reached through
`MarketMovementService`.

### Added
- A **Market mode** on the `/map` explorer (rendered only where
  `market.intelligence` is enabled): the area polygons themselves become
  the heatmap — the movement engine's trend colours (`trend.ts`, the same
  greens/reds/ambers the markers speak) at a restrained wash, with
  "unknown or insufficient evidence" staying the untinted dark base,
  never a pretended colour. Explore mode is untouched; Phase 3 selection,
  hover and the Area Intelligence card keep working under the heat.
- Compact Market filters reusing the movement product's own vocabularies
  and strings: Sale XOR Rent, the exact `7d/30d/1m/3m/6m/1y/all` window
  list with the pulse panel's honest-disabling convention (a window the
  stored evidence cannot support disables with the reason), and a
  single-select property category straight from the `PropertyType` enum —
  where "All categories" means the spanning `property_type NULL` index
  ONLY, the product's existing honest definition, never an average of
  incomparable typed indices. A legend pairs every swatch with a word.
- `GET /map/market` — the smallest read-only market-map endpoint
  (feature `market.intelligence`, its own `map-market` limiter): bbox +
  transaction + period + property_type in; per-area heat rows out, scoped
  to published areas WITH boundaries intersecting the viewport (the same
  intersection rule and 40 ceiling as the boundary payload, `truncated`
  detected). Geometry and market intelligence stay separately fetchable;
  `/map/features` carries no market history.
- `MarketMovementService::areaMovement()` — the movement engine opened to
  per-area questions under its existing rules (same eligibility, reliable
  series, exact window pairing, calculator-verdict comparisons): ONE
  deterministic first-by-key claim per area with its full identity
  (currency, basis, price type, family, category, periods, sample size,
  confidence, qualifier) on the row; an area with no honest pair has NO
  row — absence is the wire form of unknown, and nothing turns missing
  into flat or zero, or compares across sale/rent, currencies, bases or
  methodology versions.
- Adapter capability `setMarketHeat()`: `market-fill`/`market-line`
  layers between the boundary wash and the Phase 3 selection layers,
  painted through one slug-matched expression rebuilt per update.
- Tests: `MarketMapTest` (15 scenarios pinning the flag gate, vocabulary
  validation, viewport/boundary scoping, honest directions with exact
  figures, unknown-by-absence, methodology/gap/short-window refusals,
  sale/rent isolation, the spanning-index "All" in both directions,
  deterministic one-claim-per-area, publication gates, the detected
  ceiling) and `map-market-heatmap.spec.ts` (per-locale pixel-sampled
  paint + clear, honest filter answers, Phase 3 selection under the heat,
  360 layout), with one year-apart fixture value added to
  `browser-ankawa-sale` — analyzed to leave every existing movement-panel
  assertion untouched.

## [Unreleased] — Map Phase 3: interactive area selection & live-location intelligence

**Status: NOT DEPLOYED.** No schema change, no migration, no new
dependency; authentication, Telegram/WhatsApp, feature-flag semantics, the
OSM import architecture and release tooling untouched. The public map still
never calls Overpass or any external service from the browser.

### Added
- Clickable area polygons on the `/map` explorer (the sanctioned exception
  to boundaries being decorative, adapter-owned as always): hover firms the
  outline on desktop, a restrained amber selected state
  (`boundary-selected-fill`/`-line`) sits beneath every marker, and the
  adapter enforces the click priority — project marker > POI > polygon >
  empty map — so a polygon can never steal a record click. Interaction is
  gated off while centre-pick/draw modes are armed. New optional adapter
  surface: `onBoundarySelect(BoundaryIdentity)`, `setSelectedBoundary()`,
  `setBoundaryInteractive()`, `fitBounds()` (per-side padding + maxZoom;
  fit-once at explicit selection, never from a watcher), plus the pure
  `boundaryBounds()` helper (unit-tested; degenerate geometry → centroid
  fallback).
- ONE canonical selected-area state on the explorer: polygon click, the
  area list rows (now select-in-place buttons with a stated pressed state)
  and live location all resolve into the same compact Area Intelligence
  card — a desktop glass float on the start side, the existing bottom
  sheet below `lg`; the map stays dominant, never a full-screen modal. The
  card renders ONLY authoritative `/location/resolve` data: identity +
  breadcrumb, real published price indices through the same
  `MarketMetricCard` contract as the homepage (honest absence, never a
  zero), the Phase 2 service counts as live controls (activating a group
  enables that POI category through the real layer + category filters),
  and a "view the full area" action.
- Upgraded "My location" on `/map`: explicit tap → browser geolocation
  (timeout + wall-clock watchdog, ported from the homepage card) → centre
  + camera as before → the SAME `/location/resolve` coordinate mode
  selects the containing Area. Coordinates are used transiently and never
  persisted, logged, or sent anywhere else; denial keeps the map fully
  usable (existing notice), outside-coverage is stated honestly with no
  nearest-area guess, `no_data` still selects the area.
- `/location/resolve` payload gains `area.services` — the same grouped
  counts the Area profile shows, now extracted into ONE implementation
  (`AreaServiceSummary`) shared by both surfaces; `serviceIcons` moved to
  a shared module so the two surfaces cannot drift.
- Tests: `LocationResolveTest` services contract (both modes, empty list),
  `boundaryBounds` unit cases, and a new `map-area-selection.spec.ts`
  browser suite (all five viewports + three locales where it matters;
  geolocation fully MOCKED — a probe proves zero requests before the
  explicit click; hit-test priority proven against a seeded in-ring
  project marker).

### Changed
- `tests/Browser` fixtures: the `browser-ankawa` ring widened (both Wave 3
  geolocation coordinates keep their meaning), the two seeded places are
  now assigned to it (real service counts), and one price-less published
  project sits inside the ring for the priority test.

## [Unreleased] — Map Phase 2: OSM POI ingestion & area service intelligence

**Status: NOT DEPLOYED.** No schema change, no migration, no new
dependency; authentication, Telegram/WhatsApp, feature-flag semantics and
release tooling untouched. Public request paths never call Overpass.

### Added
- Server-side Overpass client (`Services/Osm/OverpassClient`): admin-only,
  one bounded request per category group over the Erbil operating bbox (or
  one area's bbox + exact polygon post-filter through the existing
  `AreaResolver`), identified User-Agent, connect/request timeouts, typed
  failures (timeout / 429 with honored Retry-After / 5xx / malformed), and
  a 24h cache of successful responses keyed on endpoint+bbox+group+query
  version. Failures are never cached and never retried. Config:
  `services.overpass` / `OVERPASS_ENDPOINT` (documented in `.env.example`;
  not a secret).
- Explicit OSM→MULK mapping (`OsmPlaceMapper`): whitelisted tags only into
  the existing 31 place categories (bus stops become `bus_station` +
  `subcategory=bus_stop`; worship maps only with a stated muslim/christian
  religion; everything unconfident is skipped with a counted reason).
  Multilingual names are never machine-translated: `name:ckb` first,
  canonical/ar/en fallbacks flagged in `tags.name_fallback` for review,
  alternates preserved in `aliases`. Provenance: `source=openstreetmap`,
  per-object `source_url`, no editor usernames/changesets anywhere.
- Idempotent importer (`OsmPlaceImporter`) writing into the EXISTING
  `places` schema by unique `external_id` (`osm:{type}:{id}`): new rows
  land as draft + unverified + medium confidence with slug, search key and
  resolver-assigned area (provenance trio, manual assignments protected);
  refresh touches only unreviewed OSM-sourced drafts — verified, reviewed,
  published, differently-sourced or admin-deleted rows are counted as
  protected and left byte-identical.
- Admin "Import from OpenStreetMap" flow under the existing Places admin
  (flag `places.database`, permissions `geography.places.*`): scope +
  category-group picker, session preview that writes NOTHING, explicit
  confirm bounded to 1000 candidates per run in chunked transactions (the
  summary reports the remainder; re-running continues idempotently).
- Bulk review on the Places index: source/publication/verification/area
  filters, page-local selection, publish/unpublish walking each row's
  LEGAL transition path (publish gated on `geography.places.verify`,
  archived rows never resurrected, ≤200 rows per request). The final
  publish hop fires the existing `PlaceObserver` →
  `RecalculateNearbyPlaces` hand-off on the `maintenance` queue.
- Public area pages: a "Services in this area" premium-glass summary —
  grouped counts (expandable per category) from published + public +
  duplicate-primary + operating places assigned to the area; zero-count
  groups hidden; new service-group glyphs in the shared icon set.
- `resources/js/lib/map/poiCategories.ts`: the one explicit, tested map
  from open DB category keys into Phase 1's closed `PoiCategory` union
  (unknown → `other`; church/hotel deliberately `other` so the union and
  the mosque semantics stay untouched).

### Changed
- `/map` Explorer renders the places layer through Phase 1's `setPois()`
  overlay (subdued, zoom-gated, beneath project markers) instead of mixing
  places into the clustered record source; the list keeps its place rows.
- Admin Place create/edit now stamps a hand-picked area as manual
  (`area_is_manual` + provenance), making the existing protection columns
  enforceable against importer refreshes.

## [Unreleased] — Map Phase 1: premium dark basemap

**Status: NOT DEPLOYED.** Map presentation only: no backend behavior,
schema, migration, feature-flag or release-tooling change.

### Added
- `public/map-styles/mulk-dark.json` — the MULK dark basemap: official
  CARTO Dark Matter raster tiles (keyless, "© OpenStreetMap contributors
  © CARTO" attribution built in) over a near-black ground. The adapter's
  no-config public fallback now serves this same-origin style instead of
  third-party `demotiles.maplibre.org`; `MAPLIBRE_STYLE_URL` still
  overrides (see `.env.example` for the production adoption note). The
  style deliberately names no glyph server, keeping every MULK-drawn label
  on maplibre-gl's local TinySDF path where the bundled RTL plugin renders
  Sorani/Arabic names with correct joined shaping.
- `labelScheme` adapter option: adapter-drawn text and the picker pin gain
  a dark-basemap paint scheme (near-white ink on night halos, amber pin);
  the four public map surfaces opt in, admin pickers keep their exact
  light rendering.
- Phase 2 POI preparation: `PoiCategory`/`PoiFeature` types and an
  optional `setPois()` adapter capability rendering a separate, subdued,
  zoom-gated overlay pair (`poi-dots`/`poi-labels`, amber active voice) —
  a hook only; no surface feeds it and no browser ever calls Overpass.

### Changed
- Map status messages moved onto one compact `.mh-map-toast` voice:
  Explorer's full-surface loading veil became a small edge status over the
  new `.mh-map-ground` dark canvas ground, the wide loading/empty/refetch
  pills tightened, and Invest's standing AI-companion glass panel shrank
  to its avatar chip — nothing large covers the lower map any more.
- The detail-page map preview's locator dot went amber (petrol sank into
  the dark basemap).
- `.mh-invest-vignette` pinned to the basemap's night family instead of
  the palette surface token: the basemap is dark in both UI palettes now,
  and the day palette's ivory vignette read as glare over dark tiles.
  Night rendering is unchanged (the token resolved to the same values).
- Browser specs' `STYLE_HOST` moved to the same-origin fallback path, with
  the one hermetic-reliant failure test now aborting the style explicitly.

## [Unreleased] — Premium glass UI unification

**Status: NOT DEPLOYED.** Frontend/design refinement only: no backend
behavior, schema, migration or feature-flag semantics changed.

### Added
- Public light/dark appearance choice: the existing
  `branding.dark_mode_enabled` setting (previously stored but consumed by
  nothing) now gates a compact theme toggle in the public header. The choice
  persists via localStorage through the shared `useTheme` mechanism, and an
  inline boot script applies it before first paint so neither shell flashes
  the wrong scheme. Night (the approved Midnight Amber direction) remains
  the default for visitors who have not chosen.
- `.mh-day` — the daylight counterpart of the midnight palette: same glass
  material and the same atmosphere grammar (fields, blobs, horizon, the
  Erbil skyline silhouette) in ivory and champagne, with its own AA-tuned
  component voices (`DayAtmosphere.vue`, day token set in `public.css`).
- Priority navigation in the public topbar: the horizontal nav measures its
  items in an inert mirror row and moves what does not fit into a "More"
  disclosure — the bar can no longer grow a horizontal scrollbar or clip a
  destination in any locale or at the owner's 120% type scale.
- Published-projects search surfaced on `/projects`: the index now renders
  the same `q` filter the homepage hero already submits (existing server
  contract; no backend change), with honest search-specific empty-state copy
  in all three languages.

### Changed
- Every public page now renders under the one theme-driven glass system —
  the per-page `palette` prop is gone, so Market, Projects, Areas, Offers,
  Companies, Places, Advisor, Lifestyle, the map surfaces, Unsubscribe and
  Offline share the homepage's identity instead of falling back to the
  legacy white admin look. The shared `.mh-card`/`.mh-panel` kit becomes a
  (blur-free) glass pane inside the public scopes; admin screens are
  untouched.
- The desktop rail is a glass navigation surface; the drawer scrim uses the
  tokenized `.mh-scrim`; map Explorer chrome (frame, status overlays, layer
  chips, mobile tabs) joins the invest-surface glass kit with the shared
  amber selected voice.
- Contrast hierarchy pass: night muted/faint inks lifted one step; search
  placeholder promoted to the muted voice; secondary buttons, alert tints,
  the sparkline stroke and champagne fills corrected for night glass;
  Arabic-script hero display lines get proper leading; the AI dock's ground
  is near-solid so the control no longer sinks into the atmosphere.
- The Kurdish language option now displays simply «کوردی» (product
  decision); the formal name in `config/localization.php` is unchanged.

## [Unreleased] — Simplified registration and Telegram verification

**Status: NOT DEPLOYED.** Product change; needs the CI host for PHPUnit and
Playwright. See `docs/simplified-telegram-verification.md` for the full model.

The customer-visible journey is now
`REGISTER → OPEN TELEGRAM → PRESS START → VERIFIED`, and every later visit is
`LOGIN → ACCOUNT`. Telegram is required once, for verification, not repeatedly
for sign-in.

### Added
- `telegram_verification_tokens`: a registration verification token that is
  **permanent until used or revoked**. No `expires_at` column and no clock in
  the usability rule — registering tonight and pressing START next month
  verifies successfully. Stored as a SHA-256 digest for lookup beside an
  encrypted copy, so the owner's own link can be re-rendered weeks later
  instead of being silently replaced.
- `TelegramVerificationService` — mint, resume, revoke and redeem, with the
  three refusals that matter enforced under row locks and again by the UNIQUE
  index on `users.telegram_id`: a spent token cannot be claimed by a second
  Telegram account, an identity in use elsewhere is never reassigned, and a
  linked account is never silently re-pointed.
- Password on registration, and sign-in by **phone number or email**. The
  throttle key is now a digest of the identifier rather than the identifier.
- Optional profile details on onboarding: city, area, email, gender, date of
  birth. City and area come from the admin-managed `areas` table (published
  only); nothing is hard-coded, and the city is derived from the hierarchy
  rather than stored in a second column.
- `PhoneNumber::toE164()` — one definition of "the same phone number", replacing
  three private copies that did not agree.

### Changed
- A Telegram START now completes registration verification on its own. The
  browser-confirmation step is retained, unchanged, for the separate operation
  that re-points an account which already HAS a Telegram identity.
- The verification screen shows a button, an instruction and "you can do this
  later" — no token, no code, no countdown. The Telegram control is a real
  anchor so a mobile popup blocker cannot swallow the tap.
- `PruneUnlinkedAccounts` no longer reclaims an account that is reachable by
  password or holds a live verification link. The pre-existing unreachable
  population is still reclaimed on the same schedule. Consequence: a mistyped
  number is no longer auto-released after 72 hours; the self-service "cancel my
  registration" button releases it immediately.

### Fixed
- `TelegramAuthenticator::applyVerifiedContact()` set `telegram_id` without
  `telegram_verified_at`. The gate reads the timestamp, so a Share-Contact
  sign-in produced an account every personal surface refused and bounced to a
  verification page that could not help it — a redirect loop with no exit. All
  three branches now stamp it, an already-verified account keeps its original
  timestamp, and redemption repairs an affected row on the next START.
- `AppAlert` was being given a `tone` prop it does not declare, so every success,
  warning and danger alert on the Telegram pages rendered as neutral info.

## [4.0.0-step31] — Post-seal trust-chain repair (authoritative)

**Status: NOT READY FOR PRODUCTION** — offline tooling verified; the real
final-release runner has not executed this tree. No product code changed in
this round: `app/`, `resources/`, `lang/`, `database/`, `config/` and `routes/`
are byte-identical to step30.

### Fixed — post-seal trust chain (01:36 independent audit)
- `record_command.py` no longer has a `--allow-nonzero` mode. The wrapper's
  exit code is now ALWAYS the child's, so a post-seal verifier that exits 7 is
  recorded as 7, received as 7 by the runner's capture, and stops the release.
  The runner additionally cross-checks every captured post-seal exit against
  the ledger's measured `exit_code` (`assert_recorded_exit`), so a wrapper
  failure can never impersonate a child result.
- `write_attestation.py` was rewritten to derive every attestation gate from
  the MEASURED attestation ledger and its real raw logs. The duplicate
  `--*-exit`/`--*-log` arguments are gone; the runner's captured exits arrive
  only as `--observed-exit LABEL=CODE` cross-checks. Any contradiction —
  exit-code disagreement, raw-log SHA-256 or byte-size mismatch, an
  unresolvable or escaping log path, a missing gate, a detached checksum that
  contradicts the sealed bytes — refuses the attestation with a controlled
  nonzero exit before anything is written. Schema bumped to
  `myhawler-final-attestation/v2`; the sealed evidence ZIP is now bound
  alongside the master.
- The workflow's attestation artifact now uploads the real material: the
  attestation command ledger, the complete `attestation-evidence/` directory
  (all four post-seal raw logs), `final-attestation.json` and its detached
  hash. The two advertised log paths that were never created are gone, the
  runner asserts the whole upload set exists before reporting success, and the
  offline E2E proves every advertised path against a real run.
- Ledger entries now record the exact `argv` as a JSON array; the joined
  `command` string is a shell-quoted display form only. Each entry also
  records a redacted map of the actual child environment (secret-bearing keys
  — passwords, tokens, application keys, blind-index/PII keys, Telegram and
  database secrets — are stored as `[REDACTED]`, never by value), the
  clean-env flag and, when `--clean-env` applies, the exact environment-key
  allow-list used.
- The remaining Playwright suite is now executed from the canonical
  `PLAYWRIGHT_REMAINING_SPECS` file inventory (`release_gates.py
  --remaining-specs`) instead of `--grep-invert` title matching, and a new
  recorded gate `playwright-remaining-merge` validates the generated JSON
  report: every intended spec represented, every project executed, no
  account-first scenario, no spec outside the inventory, zero failures, zero
  flakes, and skips only from the reviewed
  `PLAYWRIGHT_REMAINING_INTENTIONAL_SKIPS` set. An absent report now fails
  instead of merging vacuously.
- `finalize_evidence.py` — the command that actually creates the authoritative
  evidence ZIP and its internal index — is now recorded through
  `record_command.py` under the canonical `evidence-finalizer` gate, in the
  attestation ledger (its log cannot live inside the archive it creates), and
  is bound by the external attestation.
- `release-evidence.json` names its artifact map for its exact scope:
  `pre_seal_component_artifacts`, with an in-document scope note. The sealed
  artifacts it cannot see (evidence ZIP, indexes, detached checksums, master)
  are bound by the external final attestation.

### Added — regressions
- 68 new behavioural checks in `tests/Standalone/release_tooling_test.py`
  (199 total) covering child-exit propagation through the runner's capture
  pattern, every attestation-refusal path, secret redaction, clean-env
  allow-list recording, canonical remaining-suite coverage and the recorded
  finalizer.
- 20 new assertions in `tests/Standalone/release_e2e_test.py` (52 total),
  including a workflow upload contract check that resolves every advertised
  attestation path against a real offline run.

## [4.0.0-step30] — Release-integrity round (authoritative)

**Status: NOT READY FOR PRODUCTION** — one mandatory gate is unresolved. See
`docs/RELEASE_DECISION.md`, which is generated from `docs/release-evidence.json`.

### Fixed — release integrity
- `docs/VERIFICATION.md`, `docs/ROADMAP_STATUS.md`, `docs/RELEASE_DECISION.md`
  and `docs/FINAL_RELEASE_VERIFICATION.md` are now GENERATED from the tree and
  from recorded evidence. The previous editions claimed 461 PHP files, 38
  migrations, 348 test methods, no `composer.lock` and no executed test, against
  a tree with 488 PHP files, 40 migrations and 413 test methods.
- The README roadmap said steps 2-7 were "registered, empty" while its own
  header claimed a fully verified build, and it described installer steps as
  returning HTTP 501 after they had been implemented.
- `deploy/DEPLOYMENT_README.txt` said the package does NOT contain `vendor/`
  when the deployment archive ships resolved production dependencies, and
  pointed at `HOSTINGER_INSTALLATION_GUIDE.md`, which does not exist.
- SQLite rollback regressed in the previous round: the MySQL-specific foreign
  key fix skipped constraint removal on SQLite, which then refused to drop the
  referenced column. The rollback path is now branched per engine.

### Fixed — static analysis (partial)
- PHPStan findings reduced 1747 -> 532 by generating truthful `@property`
  annotations from the real schema, making `HasCoordinates` tolerate models
  without `bbox_*` columns, and typing the migration objects the tests execute.
  Nothing was suppressed: no baseline, no lowered level, no excluded path.
- Pint now passes; 99 pre-existing files were reformatted to the project's own
  configured standard.

### Superseded
The verification statements in the historical entries below were true when
written and are retained for history. Where they conflict with this entry, THIS
entry and the generated documents are authoritative.

## [4.0.0-step30] — Reliability and portability repair (historical, superseded)

Measured, not estimated: 413 tests / 1528 assertions, 0 errors, 0 failures on
SQLite; 40 migrations verified through migrate -> rollback -> migrate on SQLite
AND on MySQL 8.0.46.

### Fixed — production defects
- MySQL could not run the schema at all. Two generated identifiers exceeded
  MySQL's 64-character limit (`company_project_associations_project_id_is_approved_display_priority_index`,
  74 chars, and `company_project_associations_created_via_project_draft_id_foreign`,
  65 chars), so `migrate:fresh` aborted with SQLSTATE[42000] 1059. Both are now
  named explicitly. SQLite has no such limit, which is why this survived.
- `market_import_batches` was dropped before `price_records`, which references
  it, so rollback aborted on MySQL with SQLSTATE[HY000] 3730. Tables are now
  dropped dependants-first.
- The morph map omitted every media and lifecycle model. `enforceMorphMap()`
  throws on `getMorphClass()` and `AuditLogger` catches all throwables, so audit
  writes for media, drafts, staff, leads and outbox jobs failed SILENTLY —
  spec 26.1 requires every administrator mutation to be auditable.
- `OfferController::store()` passed the validated payload to `fill()`, and
  `company_id` is deliberately not fillable, so any client sending it received a
  500 instead of having the value ignored.
- `ProjectWizardController::nearby()` declared a `JsonResponse` return type that
  was never imported, resolving to a non-existent class: the endpoint returned
  500 on every call.
- `ProjectDraftMediaService::setCover()` read an undefined `$rows`, so setting a
  draft cover could never succeed.
- `MediaUploader::remove()` let an unresolvable disk escape as an exception,
  aborting the whole cleanup sweep instead of counting a failed attempt.
- `MapExplorerController` filtered on an ambiguous `market_index_id` after
  joining a derived table, so the entire price layer returned 500.
- The map explorer rejected a centre without a radius, making distance-sorted
  results impossible despite the payload advertising `distance.applied`.
- `PlaceCategory` lacked `HasTrilingualNames`, so every public place profile
  raised `Call to undefined method name()`.
- `orphaned_files.job_key` is now NOT NULL at the database level; a unique index
  alone does not constrain NULLs on any supported engine.
- The notification digest migration now reconciles each schema component
  independently instead of letting one component stand proxy for all of them.

### Added
- `tests/Concerns/InspectsSchema` — driver-aware schema introspection, so the
  migration suites run against MySQL as well as SQLite.
- `MfaGuardTest`, `AreaHierarchyTest`, `CleanupJournalReplayTest`,
  `CleanupJobKeyNotNullMigrationTest`, `NotificationDigestMigrationTest`.

### Known limitations
- PHPStan level 6 reports 1000+ pre-existing findings; the same count is
  present in the previous release, so none of it originates here. It is
  untouched debt, not a regression, and is not suppressed by a baseline file.
- The outbox concurrency suite drives SQLite directly because it needs a
  file-backed database shared by independent processes.

## [4.0.0-step30] — Reliability repair round (earlier in this cycle)

`config/mulkihawler.php` declares `4.0.0-step30` and that is the canonical
release identity. This changelog records releases through `4.0.0-step8`; the
entries for step9–step30 were never written. That gap is a documentation debt,
NOT a licence to invent history, so no entries have been fabricated for those
steps — see "Unresolved" below.

### Fixed
- `LocalizedRoutes::group()` called `RouteRegistrar::defaults()`, which does not
  exist. Every boot raised `BadMethodCallException`. Locale defaults are now
  applied to the routes the group registered.
- `Tests\TestCase::createApplication()` was `protected` against a `public`
  parent — a fatal that prevented the PHPUnit suite from loading at all.
- Migration `001700` `down()` called a one-parameter helper with three
  arguments (guaranteed `ArgumentCountError`) and destroyed the new identity
  before preflighting duplicate `(disk, path)` pairs. Rewritten as a
  fail-closed, idempotent state transition with separate
  `uniqueIndexNameExists()` / `uniqueIndexColumnsExist()` contracts and
  driver-accurate introspection that refuses engines it cannot inspect.
- `OrphanedFile::record()` lost concurrent attempts: the conflict branch touched
  only `updated_at`. Replaced with a single atomic UPSERT whose conflict clause
  performs the increment and the lifecycle reset.
- `Area` wrote its materialised `path` only in the `created` hook, after the
  INSERT, making every `Area::create()` a NOT NULL violation.
- Feature-flag toggles in tests used dotted config paths against literal dotted
  config keys, so every toggle was a silent no-op.
- Offer and association test fixtures contradicted the schema and the
  `AssociationLifecycle` invariant they were meant to exercise.

### Added
- `tests/Standalone/SignatureGuard.php` — a token-based signature analyser
  checking existence, visibility, static-vs-instance usage, argument counts,
  named arguments, inferred variable types, and Artisan option contracts, with
  ten fixtures proving it fails on each defect class.
- `tests/Feature/CleanupJobIdentityMigrationTest.php` — real up/down/up,
  duplicate preflight and interrupted-state coverage for `001700`.
- `tests/Feature/OrphanedFileConcurrencyTest.php` — multi-process contention
  proving exact attempt accounting.
- `composer.lock`, generated by Composer's solver.

### Unresolved
- Whether `4.0.0-step30` is the approved public release identity, and whether
  step9–step30 changelog entries should be reconstructed, are product-owner
  decisions.

## [Unreleased] — backlog clearance

### Added

- **`PlaceCategorySeeder`** — outstanding since Step 2. The 31 categories
  existed as an enum that later steps reference by name, but nothing wrote them
  to the table the admin edits, so a fresh install had an empty category list
  and no place could be created. Radius and weight are taken from the enum so
  the seeded rows and the ranking engine cannot disagree on day one. Verified
  to cover every enum case exactly, with no orphans.
- **`tests/Feature/ModelGuardsTest.php`** — 15 tests covering every model-layer
  guard written across the eight steps. Each guard throws to prevent a specific
  named failure and none had ever fired. **These have not been run**; they need
  a booted Laravel and a migrated schema.
- `tests/TestCase.php` and `tests/CreatesApplication.php`, absent until now,
  which is why the Feature suite could not have run even with dependencies
  installed.

## [4.0.0-step8] — Production Hardening

### Added

- **Installer completion.** `migrate`, `seed`, `storage_link`, `cache`,
  `health_check`, `complete` and `lock` are implemented in `InstallRunner`.
  They returned HTTP 501 from Step 1 through Step 7 pending the backup and
  rollback that make them safe.
- **`BackupService`** — PHP/PDO dump and restore, streamed to a file handle.
  Does not shell out to `mysqldump`, which is commonly unavailable on shared
  hosting. Verifies a dump's end marker before any restore.
- **Rollback on failed migration**, with the backup verified *before* the
  migration begins; an unusable backup stops the migration.
- **`ReleaseDecision`** — the Appendix E verdict engine. Exactly two states,
  ten blocker categories, NOT READY by default, a failed gate auto-blocks.
- **`ProductionChecklist`** — 23 gates across code, database, security,
  infrastructure, language, legal and business approval.
- Mail transport reachability test.
- `docs/RELEASE_DECISION.md`, generated by running the checklist on this build.

### Not done in Step 8

- Performance work, accessibility audit, load tests, PWA completion.

## [4.0.0-step7] — Content, Knowledge and Advanced Admin

### Added

- **`AnalyticsGuard`** — refuses payloads containing any of the eight spec 32.2
  forbidden data categories rather than silently stripping them; substring key
  matching; value checking via the Step 1 redactor; event-scoped coordinate
  permission; consent checked first without overriding the data rules;
  day-scoped pseudonyms that are not linkable across days.
- **`KnowledgeStatus`** and **`KnowledgeEvent`** — seven-state workflow with a
  three-condition AI gate (status, per-event permission, live expiry) and a
  refusal to approve without a source.
- **`TerminologyResolver`** — glossary → translation → lang file → key
  precedence, approved-only glossary overrides, blocked-term enforcement via
  the Sorani search key, and coverage reporting.
- Knowledge events, content items with full-snapshot revisions, product events
  storing a pseudonym rather than a user id, and bulk operation records
  carrying the permission that was checked.
- 31 new trilingual keys per locale.

### Deferred within Step 7

- CMS UI, translation centre UI, branding centre, admin search, the import
  assistant, and the bulk operation executor.

## [4.0.0-step6] — Portfolio, Alerts and Leads

### Added

- **`ValuationEngine`** — spec 20.2 five-tier comparable ladder using the Step 2
  materialised path for the wider-area fallback. Excludes asking prices before
  any tier and discloses the exclusion; returns no valuation below three
  comparables at every tier; confidence tracks match relevance rather than
  volume; range is interquartile.
- **`LeadScorer`** — itemised, per-reason scoring with rule version and manual
  override that preserves the original calculation.
- **`ConsentGate`** — deny-by-default contact permission, resolving append-only
  consent history newest-first. Bound separately from the scorer.
- Portfolio schema with encrypted label and notes, optional GPS with stated
  precision, and no publication status at all; append-only valuation history;
  saved searches; demand profiles, signals, scores; alert subscriptions with
  unsubscribe tokens and deliveries carrying a NOT NULL reason.
- 33 new trilingual keys per locale.

### Deferred within Step 6

- Telegram adapter, alert delivery logic, sales workspace, portfolio UI.

### Fixed

- `LeadScorer`'s flat per-signal cap let a hundred page views out-score a
  callback request. The cap is now proportional to each signal's weight.

## [4.0.0-step5] — Companies and Marketplace

### Added

- **`OfferRanker`** — returns organic and sponsored as two separate lists;
  interleaving is unrepresentable. Sponsorship and bid are absent from the
  organic score's inputs. An undisclosed sponsored entry throws.
- **`OfferStatus`** — the eleven-state workflow as an explicit transition graph
  with a moderator gate on the four review states.
- **`CompanyScope`** — deny-by-default lead, offer and association boundary.
- **`AssociationRole`** — seven roles; the advertising partner carries the
  lowest display priority of the seven.
- Companies, branches, staff, admin-granted project associations, offers with
  append-only moderation history, offer media, ad campaigns with a NOT NULL
  disclosure label, creatives and hashed ad events.
- Model-layer guards: a sponsored offer or association cannot be saved without
  a disclosure label.
- 42 new trilingual keys per locale.

### Deferred within Step 5

- Company portal UI, moderation queue UI, ad serving and capping logic.

### Fixed

- A test asserted a hard-coded score instead of the invariance it was checking.

## [4.0.0-step4] — AI and Lifestyle Matching

### Added

- **`NumericGuard`** — deterministic post-hoc grounding of AI output against
  retrieved evidence (spec 17.5, "no invented prices"). Extracts money,
  percentage, distance and area claims in Latin and Arabic-Indic digits;
  permits correct rounding, refuses invention; exempts counts and years.
- **`LifestyleMatcher`** — deterministic, repeatable scoring across eight
  weighted components, each returning its own reason. Hard requirements
  disqualify rather than discount; unmeasured requirements disqualify too.
- **`RetrievalGuard`** — the spec 17.3 allowlist as a deny-by-default set of
  eleven source types, with user-scoped isolation and expiry checks.
- **`AiProvider`** contract with adapter-level credential isolation (spec 17.1).
- Advisor schema: lifestyle profiles and priorities, conversations, messages
  carrying all ten spec 17.4 evidence fields as columns, matches storing score
  and components separately from narrative, and versioned prompts.
- Model-layer guards: an unvalidated assistant message auto-withholds; a match
  cannot be stored without its component breakdown.
- 54 new trilingual keys per locale.

### Deferred within Step 4

- Every concrete provider adapter, the advisor UI, prompt management, retrieval
  query construction, and narrative generation.

### Known limitation

- `NumericGuard` proves a figure was not invented, not that it was applied to
  the correct subject. Semantic review remains human.

## [4.0.0-step3] — Market Data

### Added

- **`PriceType`** enforcing spec 14.1: five types across three provenance
  families, each aggregating only with itself. Guarded on both provenance
  (asking / verified / official) and transaction basis (sale / rent).
- **`IndexCalculator`** — the index engine. Throws on a mixed-provenance batch
  rather than filtering; cannot return a value without its period, sample size,
  confidence, methodology version, source summary, outlier count and limitation
  warning (spec 15.3). Refuses period-over-period comparison across a changed
  family, methodology, currency or basis.
- **`OutlierDetector`** — Iglewicz-Hoaglin modified z-score over the median
  absolute deviation, plus period-jump detection (spec 14.3).
- **`Statistics`** — exact Decimal median, mean, weighted mean and nearest-rank
  percentiles. No floats anywhere in the price path.
- Market schema: price records with a duplicate-period unique key, index
  definitions that declare exactly one price type, revision-numbered index
  values, and import batch/row tables supporting partial acceptance and
  pre-publication rollback.
- 55 new trilingual keys per locale.
- `scripts/migration-guard.php` now checks foreign-key declaration order.

### Deferred within Step 3

- The spreadsheet importer, Excel template generation, the market dashboard and
  charts, and the recomputation job.

### Fixed

- `PriceType::mayAggregateWith()` allowed sale and rent figures to combine
  within one provenance family.
- A foreign key referenced a table created later in the same migration.
- The index source-quality confidence penalty sat on the exclusive side of its
  25% boundary.

## [4.0.0-step2] — Geography and Projects

### Added

- **Geometry core**, all pure PHP so it runs identically on MySQL and SQLite:
  `Coordinates` (with lat/lng swap and operating-area anomaly detection),
  `BoundingBox`, `Geodesy` (haversine, bearing, destination), `Wkt`
  (POINT/POLYGON/MULTIPOLYGON/LINESTRING reader and writer) and `Polygon`
  (ray-cast containment, area-weighted centroid, shoelace area, winding).
- **Geography module**: areas with a 6-level materialised-path hierarchy and
  cycle guards, 31 seeded admin-extensible place categories, places with
  per-dimension quality columns and duplicate grouping.
- **Projects module**: developers, projects with a publication workflow that
  refuses to publish without geometry, a Sorani name, a source, a verification
  date and an area; phases, unit types, media and documents.
- **Rating system**: 31 categories across 7 groups, 6 provenance types kept
  strictly separate, append-only change history, and an official score that no
  volume of anonymous or company-submitted ratings can move.
- **Nearby-place ranking**: quadratic proximity decay, per-category radii and
  amenity weights, a three-per-category display cap, manual pins that bypass
  the cap, and a breadth-rewarding amenity score.
- 136 new trilingual label keys per locale, generated from a single source
  table so ckb/ar/en parity holds by construction.

### Deferred within Step 2

- Map explorer UI, the 14-step creation wizard UI, media upload processing.
- Routing-provider travel distance and time (spec 10.5 step 3).
  `hasTravelData()` returns false rather than presenting a straight-line
  distance as a travel distance.
- Spatial indexes on the MySQL GEOMETRY columns, which require `NOT NULL` and
  therefore a backfill migration.

### Fixed

- `RatingAggregator::confidence()` made `moderate` unreachable for the
  commonest sound evidence pairing by applying a global sample floor on top of
  per-type minimums.

## [4.0.0-step1] — Foundation

### Added

- Modular monolith across 19 domains plus `Install`, with convention-based
  discovery of migrations, routes and translations.
- `Decimal` value object (bcmath) for all money and market arithmetic.
- `SoraniText` — spec §7.2 normalisation and search folding for Kurdish Sorani.
- `Totp` — in-house RFC 6238, verified against the published Appendix B vectors.
- Identity: 17 administrative roles, 4 public account types, fine-grained
  permission registry, mandatory administrator MFA, consent records.
- Localisation: ckb / ar / en with ckb as both default and fallback; languages,
  translations with the §7.4 review workflow, glossary terms.
- Branding: admin-editable settings, feature flags, versioned upload slots,
  runtime CSS custom properties.
- Operations: append-only audit log, secret redactor, scheduler heartbeat,
  release and backup record tables.
- Guided web installer — shell, resumable state, requirement checks, database
  connection test.
- CI: PHP 8.3/8.4 × sqlite/mysql matrix, frontend typecheck and build, advisory
  audits, secret scan.
- Guard scripts: `lang-parity.php`, `secret-scan.php`, `migration-guard.php`.
- Standalone test harness that runs without a `vendor/` directory.

### Deferred to Step 8 (returns HTTP 501, not a silent no-op)

- Installer steps `migrate`, `seed`, `storage_link`, `cache`, `health_check`,
  `complete`, `lock`, and the mail connection test.

### Known limitations

- Pint, PHPStan and PHPUnit are configured but have never been run; Composer
  was unreachable in the build environment. See `docs/ROADMAP_STATUS.md` §5.
