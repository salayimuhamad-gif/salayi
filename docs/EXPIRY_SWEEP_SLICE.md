# Expiry sweep — and a transition-table gap it exposed

I flagged the missing expiry job twice, once for offers and once for knowledge
events. This closes it, and writing it found a real defect.

## Why a query filter is not expiry

Both subjects stored an expiry date and relied on a query filter to hide the
consequence. Those are different things:

- **A lapsed offer** was excluded from the public browse query, so no buyer saw
  it — and its status stayed `published`, so the admin list, the company's own
  view and every count still reported it as live. The seller believes their
  listing is running.
- **A lapsed knowledge event** stopped being AI-usable via the scope and stayed
  `published`, so it remained on the public timeline indefinitely. A road
  closure that ended two years ago still reads as current.

The second is the worse failure: the first hides something that should be
visible, the second **shows something that should not be**.

## The defect this exposed

`KnowledgeStatus::Approved` had no transition to `Expired`.

That was a gap rather than a decision. `aiUsable()` already treats an approved
event past its expiry as unusable — so the state and the behaviour disagreed,
and no sweep could ever reconcile them. An approved event that has passed its
expiry **is** expired; the table simply omitted the edge.

Corrected, with six new assertions covering it. `Draft`, `Review` and `Rejected`
remain unable to reach `Expired`, because a record that was never live cannot
lapse. The full suite passed unchanged, meaning nothing had depended on the gap.

## Decisions in the sweep itself

**Transitions go through the state machine, not a bulk `UPDATE`.** The workflow
is what guarantees `Published` only reaches `Expired` legally, and a direct
update would step around the guard Step 5 exists to provide. It costs a query
per record and buys the invariant.

**A draft with a lapsed date is left alone.** It was never live, and expiring it
would tell a seller their listing ended when it never started.

**One illegal transition does not abort the sweep.** The remaining records still
need expiring, so failures are warned and skipped.

**Batched at 200 per kind.** On Hostinger the worker gets roughly fifty seconds
per cron tick; an unbounded update on a large table is killed mid-flight every
minute and never completes.

**Hourly, not per-minute.** Expiry is a date boundary rather than a moment — a
listing that lapsed at midnight need not vanish at 00:00:01, but it should not
still be live at lunchtime.

## Verification

| Check | Result |
| --- | --- |
| `php -l`, whole tree | **PASS** 329/329 |
| Standalone logic suite | **PASS** — 1096 assertions, 0 failures |
| Translation parity | **PASS** — 967 keys, ckb/ar/en |
| Secret scan / migration guard | **PASS** |

215 feature tests exist; 10 are new. **None has run** — and the sweep is
the kind of code where that matters most, since a scheduled command that throws
fails silently on a cron.

## Remaining issues

1. **Nothing has run.**
2. **No seller notification.** A listing expires and the company is not told,
   which for a paid placement is close to unacceptable. Notifications have no
   transport at all yet.
3. **No renewal.** An expired offer can be moved back through the workflow by an
   administrator; the company cannot renew its own.
4. **Index values and nearby snapshots have no expiry concept**, only staleness.
   Whether a market figure should age out is a product question nobody has
   answered.

## Rollback

Additive except the enum correction; no migration.

```bash
rm -f app/Modules/Operations/Console/ExpireLapsedRecords.php \
      app/Modules/Operations/Routes/console.php \
      tests/Feature/ExpirySweepTest.php
git checkout -- app/Modules/Knowledge/Enums/KnowledgeStatus.php \
                tests/Standalone/ContentAnalyticsTest.php
```

**Reverting the enum restores the state/behaviour disagreement.** Prefer keeping
that change even if the sweep itself is removed.
