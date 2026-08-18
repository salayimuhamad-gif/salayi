# Master Prompt Reconciliation — Requirement-by-Requirement Gap Matrix

Date: 2026-08-09. Branch: `claude/myhawler-auth-safety-rzynih`. The full master
prompt (62 sections) arrived AFTER the pre-flight audit
(`docs/KIMI_TASK_PREFLIGHT_AUDIT.md`) and after four implementation slices had
been completed and proven green in CI. This document reconciles every master
prompt section against that work. Statuses:

- **DONE** — already implemented and proven by tests/CI on this branch.
- **PARTIAL** — core in place; named additions still needed.
- **MISSING** — genuinely absent; to be implemented now.
- **INTENT-MET** — not implemented as literally described because an existing,
  safer architecture already satisfies the intent (per the safety overrides,
  which take precedence over literal mockup fidelity).

| § | Requirement | Status | Evidence / gap |
|---|---|---|---|
| 1 | Kimi = reference only; production project = source of truth | DONE | Audit §5; nothing copied from the prototype's stack |
| 2 | Premium AI-assisted Erbil investment map inside the real app | PARTIAL | `/invest` exists on the real core; AI companion + visual depth below |
| 3 | Investment content only; no generic POIs | DONE | Server-enforced layer allowlist; pinned by InvestmentMapTest |
| 4 | Homepage map preview + Open Map CTA, lazy, light | MISSING | Home has a plain map CTA button, no preview section |
| 5 | Full map: nav/zoom/pan/touch, markers, polygons, clustering, search, filters, previews, prices, highlighting, links, sheets; Erbil-bounded | PARTIAL | Navigation/markers/clustering/links/previews done; ADD: search, type filters, price+trend in payload and cards, project polygons, Erbil bounds+zoom limits |
| 6 | Premium markers; optional price/trend on markers; zoom-adaptive | INTENT-MET | Gold pins + clusters; per-marker price text deliberately omitted (declutter clause honoured); trend lives in cards/preview |
| 7 | Admin draws/edits real project boundaries; validated structured geometry | DONE | Existing MapPicker (point/draw/edit/vertex/holes), strict `ValidWkt` topology validation on every door (aligned in Slice 1); WKT is the project's structured format, emitted as GeoJSON publicly. ADD (under §5): public rendering of project polygons on /invest |
| 8 | Clustering with counts; click-to-expand; efficient | PARTIAL | GPU clustering + counts done; ADD: cluster click-to-zoom in the adapter |
| 9 | Reuse existing project records; map fields via safe migrations | DONE | `/invest` reads the real `projects` table; no duplicate model; geometry columns pre-existed |
| 10 | Price trend indicators (up green / down red / stable neutral, arrows + %) | MISSING | `ProjectPrice` history exists; trend computation + display absent from the map |
| 11 | Price history backend that can grow | DONE | `project_prices`: dated rows, type, currency, confidence — pre-existing |
| 12 | Debounced search over approved content; select → fly + highlight + preview | MISSING | No search on /invest |
| 13 | Filters (type at minimum), mobile-friendly | MISSING | No filters on /invest; project type enum is the existing category system |
| 14 | Compact premium preview card | PARTIAL | Name/area/link done; ADD: type, price, trend, status |
| 15 | Admin map management behind existing permissions | DONE | `projects.*` permission set + admin forms/wizard + publication workflow (existing conventions, as §15 itself instructs) |
| 16 | Fix existing map workflow root causes; full cycle proof | DONE | Slice 1: real `created_by` mass-assignment defect found and fixed; GeometryWorkflowTest proves the full HTTP cycle |
| 17 | Kimi visual language: dark/gold/ice/glass/glow | PARTIAL | Glass/gold/vignette shipped; ADD: ice accents on the invest surface |
| 18 | AI Agent: friendly, premium, culturally appropriate, subtle | PARTIAL | Existing `AiAvatar` (the product's own AI identity) to be integrated as a lightweight map companion. Bespoke Kurdish-clothing character artwork is a design-asset request the repository cannot generate; flagged for the design owner — the Kimi robot PNGs are NOT culturally-specific either and are reference-only |
| 19 | Agent responsive/reduced-motion discipline | PARTIAL | Applied with §18 integration |
| 20 | Existing translation system, ckb first-class | DONE | Every new string in `lang/{ckb,ar,en}` with parity enforced by CI |
| 21 | Mobile-first map quality | PARTIAL | Native MapLibre gestures, list/map toggle; ADD: E2E spec exercising the surface |
| 22 | Native gesture system; no blocking overlays | DONE | No custom touch handlers; overlays are pointer-events-none |
| 23 | Performance techniques | DONE | Lazy maplibre chunk, viewport fetch, caps, debounce, clustering — pre-existing core; new work follows the same rules |
| 24 | Lightweight map payloads | DONE→guarded | Flat rows, per-layer cap; price/trend added as scalars only |
| 25 | Bounds-scoped geographic queries + indexes | DONE | `/invest/features` requires bounds; `projects_bbox_index` exists |
| 26 | Visual effect restraint; prefers-reduced-motion | DONE | Global reduced-motion neutralizer + audit'd discipline |
| 27 | Mobile Lighthouse target | PARTIAL | Architecture supports it (code-split, lazy map). Not measurable in this environment; noted for the deploy owner |
| 28 | Inspect existing admin users; extend, don't duplicate | DONE | `/admin/users` + detail existed; privacy contract pinned in Slice 4 |
| 29 | List: search/pagination/filters/sorts + safe fields | PARTIAL | Search, pagination, status/locale filters, telegram status, counts exist; ADD: registration-date + recently-active filters, sorts, last-seen. Masked phone → INTENT-MET: the amended Section 29 (recorded in the audit doc) supersedes — phone presence boolean only, audited reveal preserved |
| 30 | Detail page: safe info, no secrets | DONE | Existing Show page + `$hidden` defence; ADD last-seen with §33 |
| 31 | Admin actions: suspend/reactivate/force-logout/trigger-reset, audited | PARTIAL | Suspend/reactivate audited + tested; ADD: force logout (`identity.sessions.revoke` — registered but unrouted until now) and trigger-recovery (Telegram challenge to the user's own chat), both audited |
| 32 | Usage analytics on admin dashboard, efficient | MISSING | Dashboard shows operational health only; ADD activity metrics from existing columns + last-seen (no heavy event logs — §32's own constraint) |
| 33 | Online status via last-seen, throttled writes | MISSING | ADD `last_seen_at` + cache-throttled touch middleware |
| 34 | Privacy-conscious analytics | DONE (constraint) | Metrics are aggregate counts; nothing public |
| 35 | Registration/login/session flows work | DONE | The accepted auth baseline; untouched per the overrides |
| 36 | Forgot Password exists, secure | DONE | Email broker (existing) + Telegram recovery (Slice 3) |
| 37 | Telegram recovery flow, exactly the safe shape | DONE | Slice 3 implements the listed flow point-for-point |
| 38 | Recovery security properties | DONE | TTL, one-time, rate+attempt limits, binding, audit, neutral answers, session invalidation — all tested |
| 39 | Password hashing; admin can never read passwords | DONE | `hashed` cast; no retrieval path; trigger-reset never reveals |
| 40 | Secrets never exposed | DONE | secret-scan gate + `$hidden` + no secrets in payloads |
| 41 | Server-side authorization everywhere | DONE | `permission:` middleware + pinned 403/404 tests |
| 42 | Validate everything | DONE | Strict geometry/coords/price validation on all doors |
| 43 | Image upload safety | DONE | Existing media services (untouched) |
| 44 | Hostinger compatibility | DONE | No new infrastructure; no Redis/Node/daemons added |
| 45 | Database safety, reversible migrations | DONE | New migrations additive + reversible; rollback gate green |
| 46 | Map testing | PARTIAL | Endpoint/permission/geometry/workflow covered; ADD tests for search/filter/trend |
| 47 | Users testing | PARTIAL | Privacy suite done; ADD tests for new actions/filters/analytics |
| 48 | Recovery testing | DONE | Full suite incl. both separation directions |
| 49 | Mobile/viewport map testing | MISSING | ADD a Playwright spec for /invest (first E2E any map surface has) |
| 50 | Graceful errors | DONE | Neutral pages/flashes; no traces client-side |
| 51 | Loading states | DONE→apply | Existing patterns applied to new UI |
| 52 | Empty states | DONE | Invest page ships empty/retry/offline states |
| 53 | Accessibility incl. non-color trend signals | DONE→guarded | Trend badges use arrow + sign + text, not color alone |
| 54 | SEO: map links to real project URLs | DONE | Cards link to `/projects/{slug}` |
| 55 | Component architecture | PARTIAL | Extract invest sub-components as the page grows this slice |
| 56 | No duplicate systems | DONE | Everything extends existing modules |
| 57 | Phased approach | DONE | Audit → matrix → slices, in order |
| 58 | Not a mockup — real backend | DONE | Live data, real admin flows, persisted |
| 59 | Coherent product | DONE | Additive luxury layer; nothing replaced |
| 60 | Priority order | DONE (constraint) | Applied throughout |
| 61 | Definition of done | PARTIAL | Outstanding items are exactly this document's MISSING/PARTIAL rows |
| 62 | Final technical report | PENDING | Delivered when the remaining slices land green |

## Remaining implementation set (only the genuine gaps)

- **Slice 5 — investment map depth**: price+trend in the `/invest` payload
  (from existing `ProjectPrice` history), project type filter, debounced
  search endpoint with fly-to, zoom-gated simplified project polygons,
  cluster click-to-zoom in the shared adapter, Erbil bounds/zoom limits,
  richer preview card, ice accents. Tests for each server behavior.
- **Slice 6 — homepage preview**: flag-gated, CSS-only (no map library on
  the homepage), CTA into `/invest`. Test: renders only with the flag.
- **Slice 7 — admin activity**: `last_seen_at` (throttled), date/activity
  filters + sorts on the users list, force logout behind
  `identity.sessions.revoke`, admin-triggered Telegram recovery, dashboard
  activity metrics behind `identity.users.view`. Tests.
- **Slice 8 — companion + E2E**: existing `AiAvatar` as a reduced-motion,
  non-blocking invest-page companion; Playwright spec for `/invest`.

Everything already merged stays as is; the auth baseline is not touched
beyond the additive admin actions above.
