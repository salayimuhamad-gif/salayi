# Price review and index building — closing the Step 3 chain

Imported prices could not reach an index. This closes that.

## The chain, now complete in source

```
CSV upload -> preview (nothing written) -> accept -> DRAFT price records
           -> human review -> PUBLISHED price records
           -> IndexBuilder -> IndexCalculator -> index values with explanations
```

Every link existed except the last two. `IndexCalculator` was proven across 64
assertions in Step 3 and had never been handed a database row.

## Decisions worth reviewing

**Draft prices never reach an index.** Asserted directly. Without it the review
step is decorative — an imported spreadsheet would flow into published market
figures with nobody having confirmed anything.

**The index's declared `price_type` is the query filter.** Spec 14.1 is now
enforced in three places: the enum refuses to aggregate across families, the
calculator throws on a mixed batch, and the query never selects the wrong type.
The test seeds six verified and six asking records at very different prices and
asserts the asking ones do not move the result.

**Records flagged as outliers are excluded from bulk publish**, not merely from
the calculation. Bulk-publishing a filtered page would otherwise silently
readmit exactly the rows the detector caught; clearing that flag should be a
deliberate, separate act.

**A thin period records the attempt with a null value.** Otherwise "not enough
data" and "nobody has computed this" look identical on a dashboard, and the
first is a data problem while the second is an operations problem.

**Recomputing a published value creates a revision, never an overwrite.** A
published market figure that silently changes is worse than a stale one.

**The interface warns when a selection spans price-type families.** The records
are individually fine — this is a review error, not a publish error — so the
reviewer sees it before acting rather than after.

## Verification

| Check | Result |
| --- | --- |
| `php -l`, whole tree | **PASS** 288/288 |
| `vue-tsc --noEmit` | **PASS** — 0 errors, first pass |
| `vite build` | **PASS** — 42 entries |
| Standalone logic suite | **PASS** — 1090 assertions, 0 failures |
| Translation parity | **PASS** — 764 keys, ckb/ar/en |
| Secret scan / migration guard | **PASS** |

105 feature tests exist; 8 are new. **None has run.**

## Remaining issues

1. **Nothing has run.** `IndexBuilder` has never executed a query.
2. **No index administration.** `market_indices` rows must be created by hand —
   there is no screen to define an index, its scope, methodology or weights.
   Without one, `IndexBuilder` has nothing to build.
3. **No rebuild trigger.** Publishing prices does not queue a rebuild; nothing
   calls `rebuild()`. This is the same gap the nearby calculator had, and the
   fix is the same shape — an observer plus a queued job.
4. **No market dashboard.** Spec 15.1 lists eighteen elements; index values are
   computed and stored and nothing displays them.
5. **Index values land as draft** with no screen to publish them, so the chain
   still stops one step short of a public figure.

Items 2 and 3 are the next two gaps, in that order.

## Rollback

Additive; no migration.

```bash
rm -rf resources/js/Pages/Admin/Market
rm -f  app/Modules/Market/Services/IndexBuilder.php        app/Modules/Market/Http/Controllers/Admin/PriceRecordController.php        app/Modules/Market/Routes/admin.php tests/Feature/IndexBuilderTest.php
git checkout -- app/Modules/Market/Providers/MarketServiceProvider.php                 app/Modules/Core/Support/AdminNavigation.php                 app/Http/Middleware/HandleInertiaRequests.php lang/*/market.php
npm run build
```
