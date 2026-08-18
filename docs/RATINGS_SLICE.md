# Project ratings

`RatingAggregator` was proved across 67 assertions in Step 2 and had never seen
a database row. It now has one, and the project profile's ratings section is no
longer declared unavailable.

## Where the rules could have broken without the aggregator being wrong

**Only approved ratings are read.** The obvious attack on any rating system is a
company submitting five five-star ratings about itself; if entry fed the public
aggregate directly, review would be decorative. Entry and approval are separate
acts behind separate permissions, and everything arrives `pending`.

**A category where nothing cleared its minimum sample is omitted entirely**,
rather than rendered with an empty score. A category shown blank reads as "we
looked and it scored badly", which is a different and false claim.

**Types stay separate all the way to the template.** The aggregator returns them
apart and nothing here flattens them; the panel lists each provenance beneath
the official score with its own mean and sample count. An internal expert
judgement and an aggregate of anonymous users are different claims, and
collapsing them would hide which one a reader is actually trusting.

## Two decisions worth reviewing

**A rating that feeds the official score requires a source.** An expert
judgement with no stated basis is an opinion nobody can weigh, and spec 5's rule
applies to a score as much as to a price.

**Inverted categories drive the colour, not the number.** Traffic, noise and
construction disruption are better when lower. Drawing a low score on those in
the "poor" colour would tell a reader the exact opposite of the truth, so the
tone follows the category's own direction.

The admin screen also shows the **public preview** — the same aggregation the
public page performs — so a reviewer can see what approving a submission
actually changes before approving it, including that a single public-user
rating changes nothing.

## Verification

| Check | Result |
| --- | --- |
| `php -l`, whole tree | **PASS** 314/314 |
| `vue-tsc --noEmit` | **PASS** — 0 errors, first pass |
| `vite build` | **PASS** — 53 entries |
| Standalone logic suite | **PASS** — 1090 assertions, 0 failures |
| Translation parity | **PASS** — 888 keys, ckb/ar/en |
| Secret scan / migration guard | **PASS** |

173 feature tests exist; 10 are new. **None has run.**

## Remaining issues

1. **Nothing has run.**
2. **No public rating submission.** Only an administrator can enter one, so the
   `public_user` type — the one with the highest minimum sample and the most
   care taken over it — has no way to receive data.
3. **Media is now the only remaining declared-unavailable section** on the
   project profile.
4. **No rating history.** A score that moved is not distinguishable from one
   that was always where it is.
5. **No per-category minimum enforcement at entry.** The aggregator refuses to
   display below the minimum; nothing warns an editor that the rating they just
   entered will not appear until four more exist. The form states the number but
   does not track progress toward it.

## Rollback

Additive; no migration.

```bash
rm -rf resources/js/Pages/Admin/Ratings
rm -f  resources/js/Components/RatingPanel.vue        app/Modules/Projects/Services/ProjectRatingService.php        app/Modules/Projects/Http/Controllers/Admin/RatingController.php        tests/Feature/ProjectRatingsTest.php
git checkout -- app/Modules/Projects/Routes/admin.php                 app/Modules/Projects/Http/Controllers/Public/ProjectProfileController.php                 app/Modules/Projects/Providers/ProjectsServiceProvider.php                 app/Modules/Core/Support/AdminNavigation.php                 resources/js/Pages/Public/Projects/Show.vue lang/*/projects.php
npm run build
```
