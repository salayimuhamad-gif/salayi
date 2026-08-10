# AI Advisor — Implementation Report

Branch `feature/real-ai-advisor-multiprovider`, based on main
`ab68a77eaa80588c86a164d90a588b115773aedb`. Companion to
`docs/AI_ADVISOR_AUDIT.md`, which was written before the first code change
and carries the file/line evidence for every claim about the old behaviour.

## 1. Root cause of the old "agent not working" experience

The product behaved like a questionnaire because it was one. The model was
invited only to re-phrase the next intake question — and only in Arabic and
English; understanding, memory, project selection and the final
recommendation were deterministic PHP. Meanwhile the provider architecture
ignored its own `AI_PROVIDER` selector (activation was an accident of which
credentials were non-empty), carried a dead `AI_FALLBACK_MODEL` setting,
priced all usage at zero so the monthly ceiling could never trip, and gave
the Super Admin no diagnostics at all.

## 2. Why it stopped after the intake sequence

`AdvisorConversationFlow::nextSlot() === null` meant simultaneously "intake
sufficient" and "conversation dead" in three places: the backend answered
**409 "already complete"** to any further message
(old `AdvisorController::reply()`), the live overlay disabled the composer
(`advisor-live-chat-v7.js`, old lines 996-998), and the Vue page swapped the
composer out for the recommend panel. The "~9 questions" figure is the
applicable-slot count (10 for residence, 12 for investment) minus the
opportunistic pickups.

## 3. Previous ckb behaviour

`AdvisorTurnComposer::compose()` returned the deterministic fallback for
`ckb` before the gateway was ever consulted — a hard bypass that made the
product's primary language the only one with no AI at all. Repaired: Sorani
turns go through the model like Arabic and English, and the safety the
bypass bought is enforced per-turn instead — generated text must pass the
language detector (plus the length/markup/number checks) or the
deterministic turn ships, recorded truthfully as `source=deterministic`.

## 4. Previous provider architecture

One adapter (`OpenAiCompatibleProvider`), constructed directly in
`AdvisorServiceProvider` whenever `AI_BASE_URL` and `AI_API_KEY` were both
non-empty. `AI_PROVIDER` was validated, stored, displayed — read by nothing.
`AI_FALLBACK_MODEL` likewise. `services.ai.rates` was read but never
defined, so every completion cost `0.000000` and
`AI_MONTHLY_COST_LIMIT_USD` was decorative. The gateway itself (budget-first
check, per-provider circuit breaker, typed failures) was sound and is kept.

## 5. New provider architecture

- `AiProviderFactory` is the single place a provider NAME becomes an
  ADAPTER: `AI_PROVIDER` really selects the primary, `AI_FALLBACK_PROVIDER`
  the optional second link, `null` means off regardless of credentials, and
  unknown names resolve to nothing rather than a guess.
- Three adapters, all over Laravel `Http` (no SDKs — audited
  composer.json/package.json; the HTTP client covers every contract
  cleanly, and shared-hosting compatibility stays trivial):
  - `OpenAiProvider` — hosted chat-completions, Bearer auth,
    `max_completion_tokens`, `response_format: json_object` on request; a
    400 is retried once with tuning parameters stripped so stricter
    reasoning models degrade to default sampling instead of failing; 404
    maps to `model_unavailable`.
  - `GeminiProvider` — `models/{model}:generateContent`, key in the
    `x-goog-api-key` HEADER (never the URL), `system_instruction` as its own
    field, `user`/`model` roles, `generationConfig` with
    `responseMimeType: application/json` on request; joined multi-part
    candidates; `thoughtsTokenCount` billed as completion output.
  - `OpenAiCompatibleProvider` — unchanged wire behaviour, base URL still
    configuration.
- `AiProviderException` now stamps an application-minted CATEGORY on every
  failure: `not_configured`, `auth_failed`, `rate_limited`,
  `timeout_or_unreachable`, `provider_error`, `model_unavailable`,
  `invalid_response`, `invalid_request`, `budget_exhausted`, `breaker_open`.
- `AiGateway` keeps budget-before-call, per-provider breaker (3 failures /
  300 s, one success closes), and adds telemetry (last success and last
  categorised failure in cache — identifiers and tokens only, never request
  content or bodies) plus a `status()` diagnostics readout.
- Cost: `AiCost` prices usage from configured USD-per-1M-token rates
  (`AI_PROMPT_COST_PER_MTOK` / `AI_COMPLETION_COST_PER_MTOK`), shared by all
  adapters, so the monthly ceiling can actually accumulate spend. Zero rates
  are reported as a visible warning on the diagnostics panel rather than
  silently recording zero.
- "Mini4": repository-wide search found **no evidence** of what it refers
  to (`docs/AI_ADVISOR_AUDIT.md` §3-K). Per instruction, MiniMax was NOT
  implemented and is not in configuration; the factory keeps any future
  provider a one-class + one-config-block addition.

## 6. Provider configuration matrix

| Env key | Read by | Notes |
| --- | --- | --- |
| `AI_PROVIDER` | AiProviderFactory | `null` / `openai` / `gemini` / `openai_compatible`; genuinely selects |
| `AI_FALLBACK_PROVIDER` | AiProviderFactory | optional; dropped if equal to primary or unconfigured |
| `OPENAI_API_KEY`, `OPENAI_MODEL` | OpenAiProvider | secret / string |
| `GEMINI_API_KEY`, `GEMINI_MODEL` | GeminiProvider | secret / string |
| `OPENAI_COMPATIBLE_BASE_URL`, `OPENAI_COMPATIBLE_API_KEY`, `OPENAI_COMPATIBLE_MODEL` | OpenAiCompatibleProvider | fall back to legacy `AI_BASE_URL` / `AI_API_KEY` / `AI_MODEL` |
| `AI_TIMEOUT`, `AI_MONTHLY_COST_LIMIT_USD` | all / gateway | unchanged semantics |
| `AI_PROMPT_COST_PER_MTOK`, `AI_COMPLETION_COST_PER_MTOK` | AiCost | new; price usage toward the ceiling |
| `AI_FALLBACK_MODEL` | nothing | **retired** — documented in `.env.example` and `docs/ENVIRONMENT_REFERENCE.md` |

Model names are validated configurable strings everywhere — no hard-coded
model lists. Every location updated consistently: `config/services.php`,
`.env.example`, `docs/ENVIRONMENT_REFERENCE.md`, installer
(`StepValidator` + `Wizard.vue`), `SystemSettingsController`,
`EnvironmentSettings` allowlists, `Admin/SystemSettings.vue`, and the three
locales' `system.php` / `install.php`.

## 7. Fallback behaviour

The chain is [primary, fallback]. An unconfigured link is skipped; a
breaker-open link is skipped and recorded; a rate-limited or failed primary
hands to the fallback immediately with `fell_back: true` stamped on the
result. **Budget exhaustion refuses BEFORE any provider is called and gates
the fallback too** — the ceiling is a policy, not a per-provider counter.
Model-level fallback no longer pretends to exist: the fallback model is the
fallback provider's own configured model.

## 8. Conversation-state design

Intake completeness and conversation life are now different facts:

- `complete` (existing meaning): every required slot answered.
- `stage`: `intake` (a question is pending) or `advisory` (recommendations
  stand and follow-ups are welcome). Derived from the criteria — **no
  migration**; `AdvisorConversation.status` keeps its open/closed lifecycle
  for reset/language-switch only.
- Session state: criteria (as before), the pending slot (now nullable
  without ending anything), and the last recommendation payload as the
  advisory stage's card context (invalidated on amend/reset).
- The visitor message is persisted in a transaction BEFORE any model call;
  understanding failures, provider outages and malformed JSON all degrade
  to the deterministic extractors that shipped before this work.

Structured understanding (`AdvisorTurnUnderstanding`): one strict-JSON
model call per turn — intent (10-token enum), criteria updates (allowlisted
slots; enums for purpose/currency/type; bounded control-stripped strings for
the rest), referenced card positions (1–10), clarification flag. Malformed
output → null → deterministic path. The model may propose; the flow's merge
rules dispose — an answered slot is only overwritten on explicit
correction/change intents or in the advisory stage. **The budget never
arrives through the model**: amounts are parsed exclusively by the tested
deterministic parser under the interview's own unit rules (explicit scale
word, literal marker, or ≥1000 with known currency).

## 9. Post-recommendation conversation behaviour

`AdvisorAdvisoryComposer` answers follow-ups from the standing
recommendation cards: explain (reasons/differences/price label of the
referenced card), compare (fact lines for the referenced pair),
project_question (card facts plus an honest "that is what the published
data shows"), ask_cheaper (asks for the new maximum with units unless one
was stated), finish, and a general continue hint. A criteria change re-runs
the deterministic matcher and returns fresh cards with the turn. Everything
works with the AI off via keyword intents and ordinal/position detection in
all three languages — the advisory quick-reply chips round-trip through
exactly that recognition. No fixed question count anywhere; the tests send
five consecutive post-completion messages and assert five persisted
answers.

## 10. Grounding / security design

- The model still never selects projects, prices or rankings.
  `AdvisorProjectMatcher` (published-only query, currency-safe budget
  comparison, honest empty states) is unchanged.
- `RetrievalGuard` and `NumericGuard` are preserved AND now genuinely wired
  into the live path: advisory model prose is composed against
  RetrievalGuard-admitted card facts, and ships only if NumericGuard traces
  every number in it to card evidence or the visitor's own words AND the
  language detector agrees on the conversation language. Anything else
  ships the deterministic answer as `source=deterministic`, truthfully.
- Prompt-injection posture: the visitor message travels as a JSON data
  field with the system prompt naming it as data; structurally the model
  can influence only a validated enum-and-string structure. No tools, no
  queries, no autonomous execution of any kind.
- Bounded memory: known criteria + card index (positions/names) + the last
  six messages truncated to 300 chars. Conversations are scoped by
  user/session exactly as before; no cross-user context can enter a prompt.
- Telemetry never logs prompts, bodies or secrets: `ai.provider_failed`
  carries provider key + category + this application's own message; message
  rows carry provider/model/tokens/cost/latency/validation as before.

## 11. Files changed

Backend (Advisor): `Contracts/AiProvider.php`,
`Exceptions/AiProviderException.php`, `Providers/OpenAiProvider.php` (new),
`Providers/GeminiProvider.php` (new), `Providers/OpenAiCompatibleProvider.php`,
`Providers/AdvisorServiceProvider.php`, `Services/AiProviderFactory.php`
(new), `Services/AiGateway.php`, `Services/AiConnectionTester.php` (new),
`Services/AdvisorTurnUnderstanding.php` (new),
`Services/AdvisorAdvisoryComposer.php` (new),
`Services/AdvisorConversationService.php`, `Services/AdvisorTurnComposer.php`,
`Services/AdvisorLanguage.php`, `Support/AiCost.php` (new),
`Http/Controllers/AdvisorController.php`.

Backend (Operations/Install/Identity): `EnvironmentSettings.php`,
`SystemSettingsController.php`, `Operations/Routes/admin.php`,
`Install/Services/StepValidator.php`, `Identity/.../UsersController.php`,
`Identity/Routes/admin.php`.

Config/docs: `config/services.php`, `.env.example`,
`docs/ENVIRONMENT_REFERENCE.md`, `docs/AI_ADVISOR_AUDIT.md` (new), this
report.

Frontend: `resources/js/Pages/Public/Advisor/Chat.vue`,
`public/advisor-live-chat-v7.js`, `resources/js/Pages/Admin/SystemSettings.vue`,
`resources/js/Pages/Install/Wizard.vue`,
`resources/js/Pages/Admin/Users/Index.vue`,
`resources/js/Pages/Admin/Users/Show.vue`.

Lang: `advisor.php`, `system.php`, `install.php`, `identity.php` × ckb/ar/en.

Tests: see §13.

## 12. Migrations added

**None.** The advisory stage derives from existing state; no schema change
was required, so none was made.

## 13. Tests added

- `tests/Unit/Advisor/AiProviderFactoryTest.php` — 10 tests: selection by
  name, null-means-off-with-credentials-present (the legacy trap), fallback
  chain rules, unknown names (incl. literally `mini4`) resolving to
  nothing, legacy env compatibility.
- `tests/Unit/Advisor/AiProviderAdaptersTest.php` — 19 tests: per-adapter
  wire contracts (auth style, request shape, JSON switches, usage parsing)
  and every failure status mapped to its category; transport failures never
  leak URLs or keys; unconfigured adapters refuse before any request.
- `tests/Unit/Advisor/AiGatewayTest.php` — 10 tests: fallback with
  provenance, budget-before-call gating both links, spend accumulation,
  breaker open/close, telemetry contents.
- `tests/Unit/Advisor/AdvisorTurnUnderstandingTest.php` — 7 tests: the
  validation boundary (malformed JSON → null, unknown intents/keys dropped,
  enum enforcement, bounded control-stripped strings, bounded positions,
  budget key ignored, unconfigured gateway understands nothing).
- `tests/Feature/AdvisorConversationTest.php` — 14 tests: the Sorani
  multi-fact single message ("شوقە دەمەوێت بۆ خێزانەکەم لە نزیک عەنکاوە،
  بودجەم نزیکەی ١٨٠ ملیۆن دینارە و قیست باشترە" fills seven slots and the
  next question is `workplace`), deterministic multi-slot pickup, no re-ask,
  advisory stage instead of 409, no question limit, post-recommendation
  budget change (unit-worded accepted / bare number refused), comparison
  naming real projects, model-driven type change re-running matching, ckb
  model provenance, foreign-language rejection recorded as deterministic,
  Arabic and English multi-turn, provider failure losing nothing, orphaned
  turn recovered once not twice, truthful `ai_available`.
- `tests/Feature/AdvisorGroundingTest.php` — 6 tests: draft never
  recommended, invented price/percent discarded on the live path, clean
  prose shipping as `source=model`, RetrievalGuard contracts (drafts,
  foreign ownership, unknown sources), NumericGuard rounding-vs-invention.
- `tests/Feature/SystemSettingsAiTest.php` — 8 tests: configured-booleans
  only, SA-only endpoints even with the permission, whole-provider
  validation, new-schema writes with secrets absent from audits,
  keep/replace/clear, connection-test categories + audit + rate limit,
  unknown provider rejected.
- `tests/Feature/AdminUsersPhoneVisibilityTest.php` — 8 tests covering the
  12 required proofs (see the section below).
- Updated: `AdminUsersPrivacyTest` (amended contract),
  `AdminUsersWorkspaceTest` (prop rename), `SuperAdminCoverageTest`
  (per-provider secret assertions).

No test makes a real provider call: every HTTP interaction is `Http::fake`,
every credential an obvious fake.

## 14. Exact commands run

```
php artisan test                       # full suite
php artisan test tests/Unit/Advisor …  # targeted slices during development
vendor/bin/pint --dirty                # after every slice
APP_ENV=testing APP_KEY=… vendor/bin/phpstan analyse --memory-limit=1G
php tests/Standalone/run.php
python3 tests/Standalone/release_tooling_test.py
composer validate
```

Frontend build and browser tests run in CI (`refresh-built-assets.yml` on
this branch, then `ci.yml`), because the sandbox's egress policy blocks the
npm registry; the CI run linked from the PR is the build evidence.

## 15. Test results

Local: **764 passed, 10 skipped** (the skips are pre-existing plus one
bcmath-requiring guard test that runs in CI, where bcmath is installed),
4,271 assertions. Standalone suite: PASSED. Release tooling: all 408
checks. CI: see the checks on the PR for this branch.

The CI MariaDB matrix additionally exposed a PRE-EXISTING production bug
the moment a test asserted a persisted model turn:
`advisor_messages.prompt_version` is `VARCHAR(32)` and the v6 version
tokens were 35–36 characters, so under MariaDB strict mode every
model-generated turn (and the recommendation turn) failed its insert and
shipped as an ephemeral payload — silently unpersisted, exactly the
failure mode the persist-or-payload catch was designed to survive but not
to hide forever. Fixed by shortening the version tokens to fit the column
and bounding `prompt_version`/`model`/`provider` to their column widths at
the persist boundary (an operator-configured model name can be arbitrarily
long); the persistence of the recommendation turn is now asserted
directly.

## 16. Build result

`composer validate`: valid. PHPStan: no errors. Pint: clean. Vite build:
executed by the branch CI (see §14); no build artifacts were hand-edited.

## 17. Remaining limitations

- Cost accounting needs the two rate variables set; unset, spend records as
  zero (the diagnostics panel says so out loud).
- The monthly spend counter is cache-backed (as before); a cache flush
  resets the month's tally.
- Turn understanding is one model call per turn on top of the phrasing
  call; with both enabled a turn costs two small completions.
- The Sorani quality gate is per-turn language validation, not a formal
  evaluation corpus; a native-reviewed corpus remains future work.
- `AdvisorAnswerComposer` (the lifestyle-ranking explainer) still has no
  live caller; its guards are exercised via the advisory path instead.

## 18. Configuration still required from the Super Admin

Under Admin → System Settings → AI: choose `AI_PROVIDER` (and optionally a
fallback), enter the provider's API key and model (plus base URL for a
compatible endpoint), set the timeout and the monthly ceiling, and — for
the ceiling to bite — the two per-MTok rates. Then use Test Connection.
The advisor surface itself still needs `advisor.residential` ON in
Features. The branch deploys with no credentials anywhere in it.

## 19. No secrets committed

Confirmed. Provider keys exist only as environment variables written by the
protected EnvironmentSettings writer; the browser receives `configured:
true/false` booleans only; validation errors, audits and logs never carry a
secret; every test credential is an obvious fake (`fake-test-key` and
kin). `git diff` against main was reviewed and contains no `.env`, no
tokens, and no key material.

## 20. Production not changed

Confirmed. No SSH, no production file, no production `.env`, no deploy, no
final-release run. All work is commits on `feature/real-ai-advisor-multiprovider`,
pushed to that branch only.

---

## ADMIN USERS PHONE VISIBILITY

- **Previous behaviour:** the Users list/detail carried `phone_present` /
  `phone_status` only; the number required the PhoneRevealService ceremony
  (permission + `company_contact` consent + reason + optional note + rate
  limit + ledger + audit) via `POST /admin/users/{id}/phone`.
- **New behaviour:** administrators holding the existing permission see the
  plaintext number directly in the Users list and on the detail page — no
  button, no modal, no reason, no consent gate on this surface. The row
  serializer is actor-aware (`row(User $subject, bool $canViewPhone)`);
  decryption (via the existing `User::phone()` accessor — no duplicated
  crypto) happens only after the permission check, and the number renders
  `dir="ltr"` with a localized "No phone number" empty state. Responses
  carry `Cache-Control: no-store, private`.
- **Exact permission required:** `identity.users.contact`, checked
  server-side per request; the Super Admin arrives through the permission
  system (`Gate::before`), never a hard-coded id.
- **Consent / reason on Admin → Users:** not required — a deliberate
  product-policy change for the administrative account-management surface.
  The repository's product/legal documentation was searched for a hard
  non-overridable consent requirement before implementing; none exists
  (the prior consent gate was an engineering privacy contract, which this
  change supersedes by explicit product decision).
- **Sales Workspace unchanged:** the Leads reveal ceremony is untouched and
  now pinned by tests — reason still required, consent still enforced,
  ledger row and `leads.phone_revealed` audit still written. The Users
  surface's own reveal endpoint was removed so no second ceremonial door
  lingers.
- **CSV still excludes plaintext phones:** the export column remains
  availability status only, asserted by digit-scan; a page-view capability
  cannot become bulk extraction. Phone search remains disabled.
- **Audit:** one `identity.users.sensitive_data_viewed` record per real
  page render with `actor_id` / `page` / `rows` only — never per row, never
  on Inertia partial reloads, and never containing a number, an encrypted
  value or a blind index.
- **Unauthorized actors proven to receive nothing:** tests cover an
  operator with `identity.users.view` but not `contact` (null field plus a
  whole-payload digit scan over every stored phone form), ordinary members
  (403), and guests (redirect); encryption at rest and the blind index are
  untouched and no second plaintext column exists.
