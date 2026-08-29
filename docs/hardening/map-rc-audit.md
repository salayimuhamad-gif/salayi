# Map Release-Candidate Hardening — Audit

Scope: the public map stack shipped by Map Phases 1–6, audited on
`main@b611c024` ("Merge pull request #69"). The finding numbering follows
the operator's approved hardening list; every finding below was
**re-derived and verified directly against this repository** — nothing was
assumed applied from any prior discussion.

## Findings

### F-1 — Reproducible packaging job re-downloads Composer dependencies

`ci.yml`'s `package` job (Reproducible packaging) ran
`composer install --no-interaction --no-progress --prefer-dist` with **no
Composer download cache**, while the PHP matrix job restores/saves
`~/.composer/cache` under `composer-${{ matrix.php }}-${{ hashFiles('composer.lock') }}`.
Every packaging run therefore re-downloaded the full dependency set — pure
wall-clock and registry-availability exposure, with zero determinism
benefit: `composer.lock` decides every installed byte either way.

**Status: fixed in this branch.** The packaging job now carries the same
`actions/cache@v4` step with key `composer-8.3-…` — exactly the php-8.3
matrix entry's key, so packaging restores the cache that job already
saved. No `composer.json`/`composer.lock` change, no `composer update`,
no retry/sleep logic, and no packaging-determinism command, release hash,
baseline identity, previous-production ref, `audit_archive.py` constant or
release semantic was touched.

### F-2 — `/map/market` and `/map/compare` treated HTTP 429 as generic failure

Both fetches branched on `!response.ok` alone. A throttled visitor —
entirely legitimate under the per-IP interactive budgets — was shown the
generic **error** state:

- Market mode raised the `market-error` toast with a Retry button
  (inviting more requests into a closed window) instead of saying "wait";
- Compare mode replaced the panel body with the error card, hiding the
  last valid comparison beneath a failure it wasn't.

Neither surface lost data (both already preserve the last payload and the
adapter heat), but both **misnamed throttling as failure**.

**Status: fixed in this branch.** Both fetches now branch on
`response.status === 429` into an explicit `rate_limited` phase before the
generic `!ok` branch. The last heat stays painted, `marketData` /
`compareData`, the A/B/C slots and every filter stand untouched; dedicated
neutral ckb/ar/en messages state the wait. Real failures keep the existing
error states and retries.

### F-5 — Search and location-intelligence 429 honesty (already complete)

Verified **already satisfied in the base commit**, predating this branch:

- the Phase 5 unified search (`runSearch`) carries
  `searchPhase = 'rate_limited'` with `map.discovery.rate_limited`;
- the Phase 3 Area-Intelligence resolve (`fetchAreaIntel`) carries
  `areaIntelPhase = 'rate_limited'`, rendered distinctly by
  `AreaIntelligenceCard`;
- the live-location flow surfaces `home.location.rate_limited` through the
  location notice.

No change was needed or made for F-5 in this branch.

### F-6 — `/map/features` treated HTTP 429 as generic load failure

`load()` threw on any `!response.ok`, so a throttled viewport fetch raised
`loadError`: the danger-variant alert, the "Could not load the data"
overlay chip with a Retry that spends the same closed window, and — when
nothing had loaded yet — the **empty state**, reading a rate limiter as an
empty city.

**Status: found and fixed in this branch.** A dedicated
`featuresRateLimited` state, set only by the newest generation (the
existing attempt-token protection is unchanged — deliberately **no
AbortController was added** for this):

- stale features, boundaries, the list, active layers, POI categories,
  radius and drawn-ring state all stand exactly as they were;
- a compact "wait" toast (`map.states.rate_limited`) speaks instead of the
  error chip; the zero-state overlay and the list's empty block are gated
  so throttling never reads as emptiness;
- a later success clears the state; a later real failure replaces it with
  the ordinary `loadError`;
- when the active layer set becomes empty, `load()`'s existing no-request
  path now clears the throttle state explicitly **without issuing a
  request**.

## Follow-ups deliberately NOT executed in this branch

### Privacy — `/location/resolve` coordinates in the query string

The endpoint is a GET whose URL carries `lat`/`lng`. The application layer
is already privacy-minimal (no persistence, no deliberate logging,
`Cache-Control: no-store, private`), **but URL query parameters MAY be
recorded by normal HTTP access logging depending on server
configuration.** The production Hostinger access-log configuration is
**NOT VERIFIED HERE**, and this PR deliberately makes **no API contract
change** (no GET→POST migration).

**PRIVACY FOLLOW-UP REQUIRED: YES.**

### Release engineering — Final Release inputs baseline

`final-inputs/e075317` and the Final Release previous-production baseline
predate the merged Map Phases and are **not modified in this PR**; the
baseline is **not rolled** here. This is to be handled in a separate PR
after this hardening merges and main CI is green.

**RELEASE-ENGINEERING FOLLOW-UP REQUIRED BEFORE FINAL RELEASE: YES.**
