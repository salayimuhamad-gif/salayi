# Public Frontend Redesign — Phase 0 Reconciliation Report

Produced before any edit, per the master prompt §2. Base: branch
`feature/public-frontend-redesign` at `2719ae889632ea9c6e253a9c08b7ce5380079c4e`
(= origin/main, CI #74 green). Working tree was clean — nothing uncommitted to
preserve. No destructive git operation was or will be used.

Baseline (§0.8): PHP suite on this tree — 772 passed, 10 pre-existing
environment skips (bcmath, MySQL row-lock). `npm run typecheck/lint/build`
cannot execute in this sandbox (npm registry unreachable); their authoritative
baseline is CI run 31523290116 on this identical tree — TypeScript, ESLint,
frontend unit suite, production build, frontend-guard, and the full Playwright
suite (546 tests, 5 viewports × 3 locales) all green. All frontend checks for
the redesign run through that same CI pipeline.

## (a) Files that exist as the prompt expects

`resources/views/app.blade.php` (settings('branding.*') RGB-triple injection
before `@vite`, `html.dark` block, theme-color from `color_brand_hex`, PWA
manifest gate); `tailwind.config.ts` (rgb-var tokens, `darkMode:'class'`);
`postcss.config.js` (postcss-rtlcss `combined`); `resources/css/app.css`
(457 lines — the `.mh-luxury-public` palette block at lines 134–457 and the
`html:has(.mh-luxury-public){background:rgb(5 15 27)}` rule are exactly as the
prompt describes); all three locale trees under `lang/{ckb,ar,en}` with a
strict parity gate (`scripts/lang-parity.php --strict` in CI, ckb as
reference); every public page named in §12.1; `Pages/Error.vue` (status prop);
`Portal/` exists (Dashboard, Offers).

## (b) Paths/mechanisms that differ (real values, used everywhere)

| Prompt said | Repository reality |
|---|---|
| `public_html/build` | `public/build` (committed build output; legacy scripts at `public/advisor-live-chat-v5/6/7.js`, v7 loaded from Blade) |
| Laravel 12 | Laravel 11 modular monolith (do-not-touch either way) |
| `PublicTopbar.vue` etc. at `Components/` root | `resources/js/Components/Public/{PublicTopbar,PublicSidebar,PublicMobileNav,LocaleSwitcher,AiAvatar,AiAdvisorHero,MarketMetricCard,ProjectSummaryCard,AreaSummaryList,PublicQuickActions,HomeProjectMap,InvestMapTeaser}.vue` + `Public/navigation.ts` |
| `AppButton.vue` etc. | `resources/js/Components/ui/App*.vue`; `AppIcon` at `Components/Icons/AppIcon.vue` + `Icons/icons.ts` (20 single-path icons, `mirror` prop, no `size` prop — sizing is class-based and stays so) |
| `t()`, `f()` | `resources/js/lib/i18n.ts`: `t(key, replacements)` over the flattened `translations` shared prop; number formatting is `formatNumber()` (en-GB, Latin digits) — there is no `f()` |
| `AppButton` link mode | Does not exist — button-only. The redesign adds an **additive** optional `href` prop (renders an Inertia `Link`), plus `accent`/`ai` variants and `lg` size; all existing props/defaults unchanged so Admin/Auth callers compile untouched |
| Global `seo` shared prop | None; only `/market` (and detail pages) receive per-page `seo`. Canonical/hreflang are server-side and untouched |
| `market.latest_period`, home props | Confirmed exactly: `market{has_data,reason,indices≤6,latest_period}`, `projects{has_data,items×6}`, `areas{has_data,linkable,items×8}`, `cta{advisor,lifestyle,portfolio,map,invest,offers}` |
| Sparkline component | `/market` draws an inline `sparkPath()` polyline; Projects/Show uses `Components/PriceHistory.vue`. `IndexExplanation.vue` renders the §15.3 explanation block |
| PWA/service worker | `vite-plugin-pwa` (generateSW) emits `public/build/sw.js` + Workbox at build time, `injectRegister:null`, **no registration code exists anywhere** — the SW is built but never registered. `/offline` is an Inertia route. `InstallPrompt.vue` (2 visits / 30-day quiet) mounts behind the `pwa` flag. All untouched per R17 |
| Fonts | Bunny URL currently fetches Kufi + Noto Sans only; `font-display` stack says "Noto Serif" (not Serif Display) and `font-mono` (JetBrains) is referenced but never fetched — the §3.6 fix applies, with the display family updated to `"Noto Serif Display"` |
| Accent default | Blade default is `201 162 39`; §3.4 default `185 142 47` (champagne). Defaults are updated in place — same keys, same mechanism, same regex; DB-set operator values still win |

## (c) Referenced files that do not exist (to be created)

`resources/css/public.css`; `resources/js/Assets/` (no SVG exists anywhere
under resources/ today); every §12.2 NEW component/composable.

## (d) Unanticipated inventory the plan now accounts for

`Components/OfferCard.vue` already exists (the prompt's "PropertyCard
extraction" is done — it will be restyled, not re-extracted);
`Components/PriceHistory.vue`; `Components/IndexExplanation.vue`;
`Components/Public/InvestMapTeaser.vue` (currently unreferenced; retained,
not deleted, per R11); `Composables/useTheme.ts` (admin dark toggle —
untouched); `tests/Browser/account-first-registration.spec.ts`;
`Components/{MapPicker,map/MapPicker}.vue` (admin pickers — out of scope).

## Binding constraints discovered (each drives a documented deviation)

1. **`.mh-luxury-public` is an E2E-pinned anchor** (`public-home`,
   `production-assets` "Vue mounted", `auth` specs). Deviation D1: the class
   NAME stays on the PublicLayout root as an inert shell/hydration marker;
   every CSS rule bound to it (the dark palette, `html:has(...)`,
   `--mh-lux-on-accent`, `--mh-lux-rail`) is deleted. The design goal (no
   hardcoded dark public palette) is fully met with zero test churn.
2. **`public-home.spec` asserts `main img`, `main polyline`, `main canvas`
   are all zero** on the hermetic homepage. Consequences: the skyline/contour
   art ships as inline `<svg><path>` / CSS backgrounds (never `<img>`, never
   `polyline`); the reference snippet's `<img src=skyline.svg>` is replaced by
   an inline-SVG component. Because §5.4 makes the map homepage section 2
   (above the fold on tall viewports, where its IntersectionObserver fires
   without scrolling), the canvas assertion is updated TEST_ONLY to
   `main canvas:not(.maplibregl-canvas)` — the honesty heuristic (no chart
   canvases, no photography, no invented sparklines) is preserved verbatim;
   the one sanctioned canvas is the real map the spec itself demands elsewhere.
3. **`navigation.spec` pins the mobile drawer contract** (`header
   button[aria-controls="public-mobile-nav"]`, `#public-mobile-nav` as a
   modal dialog with Escape/focus-return/scroll-lock). Deviation D3: the
   drawer is KEPT (it carries the full 8-item nav + locale switcher) and
   `MobileBottomNav` is added alongside as the persistent 5-tab bar —
   `PublicMobileNav.vue` is not deprecated.
4. **`.mh-invest-*` / `.mh-lux-*` class names are load-bearing** (invest and
   map-production specs assert `button.mh-invest-chip[aria-pressed]`,
   `#invest-search`, `[role="status"]` cards, tab order, ckb state strings,
   `↑/↓/→` glyphs). The families are **re-pointed to the light token system**
   rather than deleted; the two literal non-token voices inside them (the
   ice-blue `rgb(127 168 201 …)` focus ring, the `rgb(0 0 0 …)` glass
   shadows) are replaced with tokens.
5. **`lang-parity --strict` + `lang-usage` gate CI.** New keys are added to
   all three locales in the same commit. Permitted additions stay inside the
   `home.*` group (§12.1): the six hero keys, plus a small
   `home.pricing_map.*` group for the §5.4 copy that has no existing key
   (movement-filter labels, "pricing layer unavailable", the derived-change
   qualifier). Everything else reuses existing `map.*`/`market.*`/`nav.*`
   keys. Existing `home.advisor_cta` serves as the hero CTA label
   (`home.hero_cta` is not added).
6. **§5.4 price-labelled pins are frontend-feasible with zero backend
   change**: `/invest/features` already ships
   `price_from/price_to/currency/price_type/price_at/trend/trend_percent`;
   maplibre-gl v6 renders `text-field` labels without a glyph server (TinySDF
   — the existing `cluster-count` layer proves it in CI). The adapter gains an
   additive `label?: string` on `PointFeature` and one price-label symbol
   layer; clustering, trend icons, click-guard, and all states are untouched.
   The `/map/features` fallback carries no prices — the map then renders
   plain pins plus the honest "pricing layer unavailable" notice (no price is
   ever invented; `trend='unknown'` renders no number).
7. **Hero search must target a real GET filter.** `/projects` index filter
   support is verified against the real page props before the form ships; if
   the backend filter does not exist, the search entry is omitted and
   reported under `BACKEND_CHANGE_REQUIRED` (R: no fabricated search
   backend).
8. **Progress-bar colour** in `app.ts` (`rgb(201 162 39)`) follows the new
   accent default — presentation-only.

## BACKEND_CHANGE_REQUIRED (running list, final version in the delivery report)

- Exposing `--mh-ai` / `--mh-accent-soft` as admin-editable branding keys
  (explicitly out of scope; shipped as hardcoded defaults).
- Server-side thumbnails for `ResponsiveImage` `srcset` (scaffold ships,
  `srcset` omitted until derivatives exist).
- Price enrichment on `/map/features` (would let the homepage fallback show
  prices when `map.investment` is off — until then the fallback is honest).
- Service-worker registration (the built SW is never registered today;
  wiring it is PWA behavior change, out of scope per R17).
- A lighter production `MAPLIBRE_STYLE_URL` remains an admin-setting handoff
  note, not a code change.

Phase 1 begins only from this report's real paths.
