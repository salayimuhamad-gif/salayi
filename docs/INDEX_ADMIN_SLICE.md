# Index administration and rebuild triggers

The two gaps named at the end of the previous slice.

## What this completes

`IndexBuilder` could not be used, for two reasons: `market_indices` rows could
only be created in SQL, and nothing ever called `rebuild()`. Both are closed,
so the market chain now runs unattended:

```
publish a price record -> observer -> queued rebuild -> index values
```

## Decisions worth reviewing

**An index's price type is locked once published.** Changing it would silently
redefine every historical value beneath it — the same figures, now claiming to
measure something else. The model throws and the request converts that into a
field message rather than a 500.

**Changing the methodology forces a version bump.** `IndexCalculator::change()`
already refuses to compare across methodology versions, which is correct — but
that refusal only protects anything if the version actually moves when the
basis or the minimum sample does. Otherwise two incomparable figures sit under
one version and the comparison guard passes them.

**The rebuild dispatch is scoped by price type, scope and property type.** An
apartment price cannot affect a land index, and a verified price must never
rebuild an asking-price index. Both are asserted, because this is a place where
spec 14.1 could be violated by a queue rather than by arithmetic.

**Only publication status and the outlier flag trigger a rebuild.** Correcting a
note does not change the arithmetic, and reviewing four hundred rows must not
queue four hundred rebuilds.

**The creation form warns on the price type family.** An asking-price index is
not a market rate — it is what sellers are advertising — and the warning appears
while the analyst is choosing, not after the index exists.

## Verification

| Check | Result |
| --- | --- |
| `php -l`, whole tree | **PASS** 293/293 |
| `vue-tsc --noEmit` | **PASS** — 0 errors, first pass |
| `vite build` | **PASS** — 44 entries |
| Standalone logic suite | **PASS** — 1090 assertions, 0 failures |
| Translation parity | **PASS** — 786 keys, ckb/ar/en |
| Secret scan / migration guard | **PASS** |

114 feature tests exist; 9 are new. **None has run.**

## Remaining issues

1. **Nothing has run.** Observer registration and queue scoping are runtime
   behaviours that either work or fail silently.
2. **Index values land as draft** and there is no screen to publish them, so the
   chain still stops one step short of a public figure.
3. **No market dashboard.** Spec 15.1 lists eighteen elements; nothing displays
   any of them.
4. **No default indices.** Spec 15.1 names six (overall, apartment, house, land,
   commercial, rental); an analyst must create each by hand. I did not seed them
   because the minimum sample and basis for each are product judgements, and
   seeding them would present my guesses as decisions someone made.
5. **No index editing UI.** The update route exists and the interface only
   creates and rebuilds.

## Rollback

Additive; no migration.

```bash
rm -f resources/js/Pages/Admin/Market/Indices.vue       app/Modules/Market/Http/Controllers/Admin/MarketIndexController.php       app/Modules/Market/Http/Requests/MarketIndexRequest.php       app/Modules/Market/Jobs/RebuildMarketIndex.php       app/Modules/Market/Observers/PriceRecordObserver.php       tests/Feature/MarketIndexAdminTest.php
git checkout -- app/Modules/Market/Routes/admin.php                 app/Modules/Market/Providers/MarketServiceProvider.php                 app/Modules/Core/Support/AdminNavigation.php lang/*/market.php
npm run build
```
