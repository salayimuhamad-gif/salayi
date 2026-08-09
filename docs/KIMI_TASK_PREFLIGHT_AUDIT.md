# Kimi Task Pre-Flight Audit and Implementation Matrix

Date: 2026-08-09. Branch: `claude/myhawler-auth-safety-rzynih`, created from `main`
at `61599ce` (the merge of PR #1 — simplified registration + one-time Telegram
verification). This document is the audit-first deliverable required by the
MyHawler project safety overrides (large-change control): a complete architecture
audit and a concrete implementation matrix, produced **before** any feature work.

## 1. What arrived, and what is still missing

Delivered to this session:

1. The **MyHawler Project Safety Overrides** (eleven numbered rules — baseline
   protection for the merged auth flow, separate-mechanism Telegram recovery,
   extend-don't-replace for map and admin users, Kimi package as visual reference
   only, derived-artifact discipline, full CI, no production deployment,
   audit-first large-change control).
2. Two **amendments to the master task prompt** (recorded verbatim in §7 below):
   a replacement for the opening of its Section 16 (map workflow audit) and a
   replacement for a Section 29 item (admin phone privacy).
3. The **Kimi Agent reference package** (ZIP) — verified accessible and audited
   in §5.

**Still missing: the master task prompt itself.** The amendments refer to
"Section 16" and "Section 29" of a larger numbered task document that was never
delivered to this environment — it is not in the message, the ZIP, the
repository, or its git history. The overrides and amendments constrain *how* the
work must be done; the full *what* (the complete requested scope) is only
partially inferable from them. Implementation beyond this audit is therefore
blocked pending that document. Everything inferable is captured in the matrix
(§6) so the audit is immediately actionable once the prompt arrives.

## 2. Precondition verification (overrides rule 1)

- `origin/main` fetched; this branch's tip **is** `origin/main`'s tip
  (`61599ce`) — a fresh branch, no old Claude-branch history, nothing to revert.
- Working tree clean at audit start.
- Main CI status: the `CI` workflow run for commit `61599ce` completed with
  conclusion `success`; the most recent `MyHawler final release` dispatch (on
  `a89654b`) also succeeded.

## 3. Accepted authentication baseline (overrides rule 2) — verified facts

The merged flow behaves exactly as the overrides describe, and must be preserved:

- **Password-based registration**: `RegistrationController@store`
  (`app/Modules/Identity/Http/Controllers/Auth/RegistrationController.php`)
  creates the account immediately (name, Iraqi phone, strong password, locale,
  terms), signs the user in, and redirects to the Telegram verification page.
  Phone stored encrypted plus keyed blind index; duplicate refusals are
  non-committal (anti-enumeration).
- **Normal login**: one `login` field (phone or email, shape-dispatched) +
  password (`AuthenticatedSessionController`); digest-keyed throttles; generic
  failure message; session regeneration on success. Telegram is never required
  again for ordinary logins.
- **One-time initial Telegram verification**: permanent-until-used-or-revoked
  token in `telegram_verification_tokens` (hash for lookup + encrypted copy for
  re-render; deliberately **no expiry column**), minted at registration, resumed
  on revisit, redeemed one-press via the bot webhook
  (`TelegramVerificationService::redeem` — single transaction, row locks,
  idempotent same-sender replay, refusal audit trail).
- **Token inventory** (all live types, for the rule-3 "never reuse semantics"
  boundary): T1 permanent verification token (above); T2 `telegram_login_intents`
  (10-minute, purpose-scoped: login / registration-legacy / account_link;
  browser-confirmation step for account_link is ON by default); T3
  `telegram_return_handoffs` (5-minute, strictly one-time, identity-rebound at
  redemption); T4 Laravel password-broker reset token (email-bound, 60-minute);
  T5 remember token (rotated only on password reset); T6 admin MFA TOTP
  material; T7 webhook replay ledger; T8 derived HMAC candidate handle.
- **Multilingual behavior**: ckb default/unprefixed, ar/en prefixed; auth routes
  registered once (unprefixed) but locale-resolved per session/user; bot replies
  localized from the token/intent locale.
- **Middleware/authorization**: `account.active` (kills suspended sessions),
  `telegram.linked` (gates member surfaces on `telegram_verified_at`), `mfa`
  (mandatory admin TOTP, session-id-bound marker), per-route `permission:`
  middleware backed by `PermissionRegistry`.

Facts relevant to rule 3 (recovery as a separate mechanism):

- A password recovery path **exists today but is email-only** (standard Laravel
  broker: `forgot-password` / `reset-password` routes,
  `PasswordResetController`, anti-enumeration send). Customers register with
  `email = null` and email stays optional at onboarding — an account that never
  adds one has **no self-service recovery**. This is the real gap Telegram
  recovery would close.
- **No Telegram recovery flow exists** — the contract doc
  (`docs/simplified-telegram-verification.md` §5) explicitly defers it.
- Password reset rotates the remember token but does **not** invalidate other
  active sessions (`AuthenticateSession` is not registered;
  `logoutOtherDevices` is never called; no code touches the `sessions` table on
  reset). There is also **no in-app password change UI** at all.
- The password reset flow currently has **no automated test** (no test file
  references the broker, the routes, or the controller).
- Admin MFA recovery codes are generated and shown once at enrolment, but no
  code path can consume them (challenge verifies TOTP only).

## 4. Existing map infrastructure (overrides rules 4–5) — verified facts

The map stack is substantial and working; nothing here justifies a parallel
system.

- **Map Explorer** (public): `MapExplorerController` +
  `resources/js/Pages/Public/Map/Explorer.vue`, behind feature flag
  `map.explorer` (default off). Layer toggles, category chips, radius and
  free-polygon spatial filters, always-rendered results list, truncation
  honesty, provider-fallback notices.
- **Adapter core** (`resources/js/lib/map/`): provider-agnostic `MapAdapter`
  interface; MapLibre default (lazy-loaded, clustered GeoJSON source); Google
  adapter with script/auth lifecycle management and automatic fallback to
  MapLibre; node-testable GeoJSON utilities. Server resolves the provider and
  only emits a Google key when Google is actually resolved.
- **Viewport/bounds querying**: `GET /map/features` — validated bounds, layer
  flags server-enforced, per-layer cap with `truncated` flag, haversine radius +
  point-in-polygon refinement, zoom-gated simplified area boundaries (holes and
  multipolygons preserved).
- **Geography module**: `Area` (materialised-path hierarchy, cached bbox,
  derived geometry sync), `Place` + `PlaceCategory` (admin-entered POIs:
  schools, hospitals, etc.), WKT/polygon/geodesy/topology support classes,
  `AreaResolver`, nearby-places pipeline with observers, queued recalculation
  and a scheduled refresh.
- **Pickers**: the adapter-based `Components/map/MapPicker.vue` (point + draw +
  edit, multipolygon, holes, vertex editing) used by the project wizard and
  admin forms; an older `Components/MapPicker.vue` appears orphaned (no import
  of that path found).
- **Create → Save → Reload → Edit → Update → Reload** traced end-to-end for
  projects (legacy form and wizard), areas, places and offers: validation
  (`ProjectRequest` + strict `ValidWkt` topology rule), observer-driven area
  resolution and nearby recalculation, symmetric round-trip with no lossy
  conversion found. **The path works; the current map must not be presumed
  broken** (matches amended Section 16).
- **Layer content today**: projects, areas (+boundaries), places/POIs, offers,
  company branches, market-price dots — each flag-gated. Portfolio properties
  and private data are excluded by design and covered by a negative test.
- **An investment-only mode does not exist yet** — no filter, flag, page or
  configuration limits the map to approved investment content. This is the
  genuine gap for the Investment Map requirement, and it is a *mode/page over
  the existing core*, not a new stack.

Observations recorded for the reproduce-first protocol (candidate defects; none
confirmed as runtime breakage — each needs reproduction before any fix):

1. Old `Components/MapPicker.vue` watches only `boundaryWkt` — typed lat/lng
   edits don't move the placed marker. Likely moot if the component is truly
   orphaned; verify and either remove or fix.
2. `AreaRequest` validates boundaries with parse-level `Wkt::validate` only,
   not the strict `ValidWkt` topology rule used for projects — self-intersecting
   area boundaries are accepted at entry, against the "one table, one rule"
   comment in `ProjectRequest`.
3. The project wizard passes `googleKey: null` unconditionally, so a
   Google-configured deployment always falls back to MapLibre inside the wizard
   (commented as deliberate; confirm intent before treating as a bug).
4. `lang/*/map.php` `results` label lacks the `:count` placeholder Explorer
   passes — the count is silently never shown.
5. Stale docblocks/comments contradict shipped code (`GeographyServiceProvider`
   "explorer not implemented"; `config/features.php` "public /areas unbuilt").
6. No Playwright spec visits `/map` or exercises any picker; the pure-TS map
   tests (`tests/js/`) are wired into no CI job. Map UI has never been rendered
   by an automated test.

## 5. Kimi reference package (overrides rule 7) — accessible; inventory

The ZIP was extracted and audited. It is a React 19 + Vite + **Leaflet** +
tRPC/Drizzle prototype ("HAWLER AI MAP") — a single fullscreen dark map of Erbil
with Kurdish Sorani RTL chrome. Per rule 7 it is **visual/interaction reference
only**; its React architecture, Leaflet stack, persistence and component kit
must not be copied.

Transferable as pure design guidance (re-implemented natively in Vue +
MapLibre + the existing token system):

- **Palette/tokens**: abyss/deep/midnight/elevated dark backgrounds; metallic
  gold triad with 160° gradient; ice secondary accent; glass fill
  `rgba(13,18,26,0.72)` with `blur(20px) saturate(140%)`, 1px light border,
  inset top highlight, gold-tinted border + glow for active surfaces; radii
  20/14/999/10; documented shadow recipes.
- **Layout pattern**: fullscreen map + floating glass chrome (pill navbar,
  top-center search, category/area chip strip, bottom control stack, gold FAB,
  thin status footer, explicit z-ladder, safe-area insets); desktop 420px
  non-modal side drawer vs mobile bottom sheet (0.35/0.85 snaps) for details.
- **Map look**: dark basemap treatment with vignette/top-fade overlays; gold
  teardrop SVG pins with category-tinted dot, base pulse ring, hover/active
  states, staggered drop-in; gold polygon fill at 0.07/0.12 with dashed
  marching-ants selected border; centroid pulse dots.
- **Interaction flows**: hover-intent glass cards (two-tap on touch); select →
  flyTo/fitBounds + scan-burst; placement mode (banner, point/polygon chips,
  rubber band, vertex dots, camera-restore cancel); delete → confirm → timed
  undo toast; debounced search with recents and add-from-empty-state.
- **Typography/RTL discipline**: Kurdish-first face with a Latin display font
  strictly inside isolated LTR spans (wordmark, coordinates, numerals); Eastern
  Arabic digits for user-facing counters; `ckb-IQ` Intl dates; logical
  properties; Kurdish aria-labels; every atmosphere effect paused under
  `prefers-reduced-motion` and during map gestures.

Explicitly not transferable: Leaflet/react-leaflet, tRPC/Drizzle/MySQL stack,
React state/event buses, shadcn/Radix kit, localStorage persistence, the AI
robot companion implementation, the placeholder login. MyHawler's existing
fonts (Noto Kufi Arabic primary) and admin-editable branding tokens stay
authoritative; Kimi values inform the public *luxury* layer only where the
master prompt asks for them.

## 6. Implementation matrix (overrides rule 11)

Requested capability below is what the overrides make inferable; the master
prompt must confirm scope before implementation starts.

| # | Requested capability | Existing capability | Gap | Files/modules affected | Migration? | Regression risks | Planned tests |
|---|---|---|---|---|---|---|---|
| R1 | Telegram password recovery (rule 3: dedicated short-TTL, one-time, rate-limited, account- and Telegram-identity-bound challenge; audit; session invalidation after reset) | Email-only broker reset; verified Telegram identity on file; rich audit/throttle infrastructure; token patterns T1–T3 to model *against*, never reuse | Entire dedicated recovery challenge service + bot entry point + reset UI; session invalidation on reset (currently absent even for email resets); recovery must never touch T1/T2/T3 semantics | New `password_recovery_challenges` (or similar) table + service + controller in `app/Modules/Identity`; `TelegramAuthController::dispatchUpdate` dispatch arm; `routes/auth.php`; Vue pages under `Pages/Auth`; `lang/*/identity.php` ×3 | Yes (new table) | Highest-sensitivity area of the app; regression risk to login/verification flows and webhook dispatch ordering; must not weaken anti-enumeration | Feature tests for mint/redeem/expiry/one-time/binding/rate+attempt limits/audit/session invalidation; negative tests proving T1 tokens cannot reset passwords; Playwright happy path in all three locales |
| R2 | Public Investment Map defaulting to approved investment/project content only (rule 5), Kimi-styled | Full Explorer + adapter core + `/map/features` with server-enforced flag-gated layers; projects layer already approved-content-only | Investment-focused mode/page/configuration over the existing core; investment-marker curation; Kimi visual layer; POI layers stay available but off by default here | `MapExplorerController` (or a sibling investment controller), Geography routes + feature flag, new/extended Vue page reusing `lib/map` adapter, `PublicLayout` nav definition, `lang/*/map.php` + `nav.php` ×3, `NavigationDestinationsTest` | Possibly none (flag + config may suffice; new columns only if "investment" curation needs marking projects) | Must not delete/degrade existing layers or Explorer behavior (rule 5); nav test drift; flag defaults | Feature tests for the investment mode's layer set and exclusion of generic POIs; existing Explorer suite stays green; new Playwright spec finally covering the map surface, ckb/ar/en |
| R3 | Admin Users extensions per amended Section 29 (phone present/absent; Telegram linked/unlinked; safe metadata; audited reveal preserved) | Already implemented: list payload exposes `phone_present` boolean + `telegram_linked`(+date), no digits in any form; consent-gated, ledgered, audited `PhoneRevealService` ceremony; role-holder non-enumeration (404); server-side permission enforcement | Mostly none in behavior — the amendment matches the shipped privacy contract. Gap is any UI extension the master prompt adds, plus missing regression tests for this surface | `UsersController`, `Pages/Admin/Users/*`, `PhoneRevealService` (read-only reference — do not weaken) | No | Weakening privacy contract by satisfying a mockup literally (explicitly forbidden); losing 404-non-enumeration ordering | New feature tests pinning the list payload (no phone digits), reveal authorization/consent/audit, role-holder 404s — none of this is covered today |
| R4 | Map workflow repairs per amended Section 16 (reproduce first; fix root causes; prove Create→Save→Reload→Edit→Update→Reload) | Path traced and functional (§4); candidate defects listed there | Reproduce each observation; fix only confirmed root causes; add the missing automated proof of the full workflow | Old `Components/MapPicker.vue` (verify orphan status), `AreaRequest`, wizard `googleKey` wiring, `lang/*/map.php`, stale comments | No | Touching validation rules affects existing stored geometries; picker changes affect four admin forms | The workflow-proof test itself (feature + Playwright); regression runs of the existing Explorer/wizard suites |
| R5 | Kimi visual fidelity for the new experience (rule 7) | Existing luxury public theme (`.mh-luxury-public`, brass accent, dark navy) + token system inlined from admin branding settings | Kimi glass/gold/ice layer as *additive* styles for the investment experience without breaking admin-editable branding or the existing public theme | `resources/css/app.css`, `tailwind.config.ts`, new Vue components for map chrome; no dependency additions | No | Global CSS bleed into existing public pages; RTL/reduced-motion regressions; accessibility (contrast on gold) | Playwright accessibility + no-overflow checks on the new page across the five viewports; existing specs stay green |

Sequencing once the master prompt arrives: independent, reviewable slices in
this order — (1) R4 reproductions + fixes with the workflow proof, (2) R2+R5
investment map experience, (3) R1 recovery (most sensitive, reviewed alone),
(4) R3 test hardening. Each slice ends with the full local gate battery and
regenerated tree identity via the repository's own scripts.

## 7. Recorded master-prompt amendments (verbatim)

**Section 29 — replace the item "Masked phone." with:**

> - Phone present/absent status.
> - Preserve the existing audited phone-reveal mechanism for authorized access.
> - Do not expose masked phone digits in normal list payloads if the current
>   privacy contract intentionally withholds them.

Audit note: the current privacy contract *does* intentionally withhold them —
the list payload carries `phone_present` only. The amendment codifies shipped
behavior; no code change is required to satisfy it.

**Section 16 — replace the opening with:**

> # 16. Existing Map Workflow Audit
>
> Do not assume the current map is broken or incomplete.
>
> The project already contains substantial map infrastructure.
>
> First reproduce any actual failure in the current implementation.
>
> If a create/save/edit/reload workflow is genuinely broken, trace and fix the
> root cause.
>
> Do not build a parallel map system merely because an existing path requires
> repair.

Audit note: consistent with §4 — the workflow is functional; only the
enumerated candidate defects warrant reproduction.

## 8. CI and derived-artifact facts for the coming work (overrides rules 8–9)

- Pushes to `claude/*` branches trigger no workflow; the full five-job `CI`
  battery runs on pull requests (any base) and can be dispatched manually.
- The committed tree-identity files (`TREE_MANIFEST.txt`, `TREE_MANIFEST.sha256`,
  `SHA256SUMS.txt`) are regenerated with the repository's own
  `scripts/release/stage_tree.php` two-stage fixed-point recipe (as the
  `Refresh dependencies and built assets` workflow does); repository convention
  regenerates them in every commit that changes tracked files. Automated CI does
  not compare the committed copies, but the manual release path does.
- `public/build` is tracked; after any frontend change the reproducible-packaging
  job fails unless the committed assets equal a fresh production build — the
  approved refresh is `npx vite build` (or the refresh workflow) followed by the
  tree-identity regeneration above, never hand edits.
- Local mirrors: `composer ci` (style, static analysis, lang parity, test
  suite), `php scripts/collect-release-evidence.php` (closest single-command
  mirror of the package job), and the gate list in `WORKING_TREE_README.md`.

## 9. Decision

Architecture audit complete; preconditions verified; reference package present
and inventoried; amendments recorded and validated against the codebase.
Feature implementation is intentionally **not started**: the master task prompt
(the numbered document the amendments modify) has not been delivered to this
environment, and rule 11 forbids proceeding on an inferred scope. The next
session should supply that document; the matrix in §6 then maps directly onto
its sections.
