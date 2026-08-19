# Project price history — connecting the market and project verticals

The project profile declared price history unavailable, and published price
records had no public surface. Both closed.

## The rule this protects

**Series are never merged.** Asking prices and verified transactions are
returned as separate series with separate lines, separate scales and separate
change percentages.

A single trend combining them would be the most misleading thing this page could
render. The two diverge most in a falling market — which is exactly when a buyer
depends on the difference, and exactly when a merged line would understate how
far real prices had moved.

Spec 14.1 is now enforced in five places: the enum refuses to aggregate, the
calculator throws on a mixed batch, the index query filters by declared type,
the index admin locks the type after publication, and this refuses to merge two
series into one chart. The fifth is the one a chart could have violated with no
arithmetic being wrong at all.

## Other decisions

**Currencies are kept apart too.** Plotting USD and IQD on one axis produces a
line that jumps three orders of magnitude and means nothing.

**Verified series sort first.** An asking line above a verified one reads as the
headline trend whatever its label says.

**An asking-only project is disclosed above the chart**, not beside it: if every
figure on the page is what sellers were hoping for, the reader is told before
they read it as a record of what the market did.

**A single data point is not shown.** One price is not a trend, and drawing it
as one implies a movement nobody measured.

**Change is computed within a series only.** A percentage derived across asking
and verified would look like a market movement and would actually be the spread
between hope and reality.

## Verification

| Check | Result |
| --- | --- |
| `php -l`, whole tree | **PASS** 311/311 |
| `vue-tsc --noEmit` | **PASS** — 0 errors |
| `vite build` | **PASS** — 51 entries |
| Standalone logic suite | **PASS** — 1090 assertions, 0 failures |
| Translation parity | **PASS** — 871 keys, ckb/ar/en |
| Secret scan / migration guard | **PASS** |

163 feature tests exist; 11 are new. **None has run.**

## Remaining issues

1. **Nothing has run.**
2. **Two of the project profile's four declared-unavailable sections remain**:
   ratings and media. `RatingAggregator` is proven across 67 assertions and has
   no surface, which is now the largest unexercised piece left.
3. **No price detail view.** The sparkline shows shape, not values per period;
   there is no table of the underlying records with their individual sources.
4. **Only project-scoped records are read.** An area-scoped price series would
   be relevant context on a project page and is not shown.
5. **The two-point minimum is arbitrary.** Defensible, and someone should decide
   whether three or four is the right floor for showing a trend at all.

## Rollback

Additive; no migration.

```bash
rm -f resources/js/Components/PriceHistory.vue       app/Modules/Market/Services/ProjectPriceHistory.php       tests/Feature/ProjectPriceHistoryTest.php
git checkout -- app/Modules/Projects/Http/Controllers/Public/ProjectProfileController.php                 app/Modules/Market/Providers/MarketServiceProvider.php                 resources/js/Pages/Public/Projects/Show.vue lang/*/market.php
npm run build
```
