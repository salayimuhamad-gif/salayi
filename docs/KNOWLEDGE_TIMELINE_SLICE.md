# Knowledge timeline

Step 7's seven-state workflow and three-condition AI gate, driven by a request
for the first time — and the sharpest thing a market-intelligence product can
put on a project page: what has actually happened at this location.

## The three AI conditions, and why they are separate

An event may inform an AI answer only when **all three** hold:

1. **Status is Approved or Published.** A draft has not been reviewed.
2. **The per-event permission is set.** Not everything reviewed is something the
   platform wants an assistant repeating.
3. **The expiry has not passed.** A road closure that ended is not evidence
   about today.

Each has a plausible failure mode the others do not cover, which is why each is
asserted on its own. The admin list shows the three as a single answer —
"AI uses this" or "AI does not" — because an editor who set the permission and
sees it inactive needs to know it is the status or the expiry, not wonder
whether the toggle saved.

`ai_usage_permitted` defaults to **false**: an editor opts an event in rather
than forgetting to opt it out.

## One rule added here

**AI permission requires a source.** An event the assistant may use is one it
will cite, and permitting an unsourced event means the AI states something with
nothing to point at — the exact failure the retrieval guard exists to prevent.
The form disables submission while that combination is set, rather than
rejecting it afterwards.

## Approved and published are different thresholds

Approved makes an event usable as **AI evidence**. Published makes it a **public
statement**. The public timeline reads only published events, and there is a
test asserting an approved one does not appear — the two are deliberately not
the same act, because the bar for "our assistant may reason from this" is lower
than for "we are telling Erbil this happened".

## Display decisions

**Direction drives the marker colour, strength its size.** A road opening and a
road closure are both `road` events; rendering them identically would make the
timeline a list of things that happened rather than an account of what they
meant.

**An expected date is labelled as expected**, not placed alongside the effective
date as if both had occurred.

**Every entry names its source**, as every other public fact on that page does.

## Verification

| Check | Result |
| --- | --- |
| `php -l`, whole tree | **PASS** 326/326 |
| `vue-tsc --noEmit` | **PASS** — 0 errors, first pass |
| `vite build` | **PASS** — 58 entries |
| Standalone logic suite | **PASS** — 1090 assertions, 0 failures |
| Translation parity | **PASS** — 967 keys, ckb/ar/en |
| Secret scan / migration guard | **PASS** |

205 feature tests exist; 12 are new. **None has run.**

## Remaining issues

1. **Nothing has run.**
2. **Nothing consumes `aiUsable()`.** The gate is enforced and no AI exists to
   be gated — the advisor has no provider adapter. This is the last piece of
   Step 4 and Step 7 that remains entirely theoretical.
3. **No area or place timelines.** Events can be attached to either and only
   the project profile renders one.
4. **No expiry job.** An event past `expires_on` stops being AI-usable via the
   scope but its status stays Published, so it remains on the public timeline.
   This is now the second expiry job I have flagged as missing.
5. **No related-entity linking.** `related_project_ids` and its siblings are
   stored as JSON and nothing writes or reads them.
6. **No bulk import**, which for two years of Erbil infrastructure history is
   how the timeline would actually be populated.

## Rollback

Additive; no migration.

```bash
rm -rf resources/js/Pages/Admin/Knowledge
rm -f  resources/js/Components/KnowledgeTimeline.vue        app/Modules/Knowledge/Http/Controllers/Admin/KnowledgeEventController.php        app/Modules/Knowledge/Http/Requests/KnowledgeEventRequest.php        app/Modules/Knowledge/Routes/admin.php tests/Feature/KnowledgeTimelineTest.php
git checkout -- app/Modules/Core/Support/AdminNavigation.php                 app/Modules/Projects/Http/Controllers/Public/ProjectProfileController.php                 app/Http/Middleware/HandleInertiaRequests.php                 resources/js/Pages/Public/Projects/Show.vue lang/*/knowledge.php
npm run build
```
