# AI Advisor — Forensic Audit (before any code change)

Audited at branch point `ab68a77eaa80588c86a164d90a588b115773aedb` (origin/main),
on branch `feature/real-ai-advisor-multiprovider`, before the first edit.
Every claim below carries the file and line it was read from, at that commit.

## 1. The complete runtime path, as it actually runs

```
Public Advisor UI
  resources/js/Pages/Public/Advisor/Chat.vue          (Inertia page: transcript, one-question composer)
  public/advisor-live-chat-v7.js                      (plain-JS overlay loaded by resources/views/app.blade.php:81;
                                                       owns the LIVE send loop, quick replies, recommendation cards)
-> HTTP route/controller
  app/Modules/Advisor/Routes/web.php:47-54            GET /advisor, POST /advisor/{reply,amend,recommend,reset,request}
                                                      middleware: feature:advisor.residential + telegram.linked
  app/Modules/Advisor/Http/Controllers/AdvisorController.php
-> conversation service
  AdvisorConversationService::acceptReply()           persists the user turn FIRST, in a transaction (:87-125)
-> criteria extraction (deterministic PHP only)
  AdvisorConversationService::mergeReply()            (:462-525) keyword/regex extractors:
    extractPurpose / extractPropertyType / extractCurrency / applyBudgetReply / parseAmount
-> deterministic slot flow
  AdvisorConversationFlow::slots() / nextSlot()       (:41-106) fixed ordered slot list; nextSlot()==null == "complete"
-> turn composer
  AdvisorTurnComposer::compose()                      (:27-98) the ONLY live-path model call; ckb hard-bypassed (:47-49)
-> AiGateway
  AiGateway::complete()                               (:71-121) budget check, provider loop, breaker, spend record
-> provider adapter
  OpenAiCompatibleProvider::complete()                (:61-144) POST {base_url}/chat/completions via Laravel Http
-> final recommendation
  AdvisorProjectMatcher::recommend()                  (:28-164) published projects + approved prices, PHP scoring;
  AdvisorTurnComposer::finalRecommendation()          (:140-172) wraps it — source=deterministic, model=null
-> message persistence
  AdvisorConversationService::persistTurnOrPayload()  (:412-456) AdvisorMessage row with provider/model/cost/validation
-> frontend rendering
  Chat.vue renders the transcript; advisor-live-chat-v7.js re-renders live turns, quick replies and
  recommendation cards from the JSON responses, and at :996-999 DISABLES the composer once complete.
```

A second, fuller pipeline exists — `AdvisorAnswerComposer::compose()`
(RetrievalGuard → deterministic ranking → model explanation → NumericGuard
validation) — but it is **dead code on the live surface**: its only caller is
`AdvisorConversationService::recommend()` (:362-381), and no route or service
invokes that method. The guards are real and sound; the shipped conversation
path simply never reaches them (the live path has its own inline validation,
`AdvisorTurnComposer::isSafeTurn()` :192-232).

## 2. The twenty audit questions, answered

1. **Is the current experience actually calling an external LLM?**
   Only conditionally, and only for one narrow purpose. The single live-path
   call site is `AdvisorTurnComposer::compose()` — used to *rephrase the next
   intake question* in a friendlier sentence. It fires only when ALL hold:
   locale is `ar` or `en` (ckb returns at :47-49 before the gateway), the
   interview is not complete (`$nextSlot !== null`, :38-40), and
   `AiGateway::isAvailable()` is true — which requires `AI_BASE_URL` and
   `AI_API_KEY` both non-empty (AdvisorServiceProvider:72-83) and the circuit
   breaker closed. Criteria extraction, question selection, ranking and the
   final recommendation never involve a model.

2. **In which locales?** `ar` and `en` only. `ckb` is deterministically
   bypassed with an explicit code comment ("Sorani is kept deterministic until
   a native-reviewed generation prompt … is available", AdvisorTurnComposer:44-49).

3. **At which exact turns?** Intermediate intake turns only — the assistant
   reply between accepting an answer and asking the next question. Never the
   greeting (`initialMessage()` is deterministic, AdvisorConversationService:263-279),
   never the completion turn, never the recommendation turn.

4. **Which turns are fully deterministic?** Everything else: the initial
   prompt; every ckb turn; every turn when the provider is unconfigured,
   breaker-open, failed, or fails `isSafeTurn()` validation; the completion
   message; `finalRecommendation()` (source=deterministic, model=null,
   provider=null — AdvisorTurnComposer:140-172); the recommendation cards
   themselves; the recovery turn (`recoverLastUnansweredTurn()` always uses
   `fallbackTurn()`); the editable summary; quick replies.

5. **Why does the conversation stop?** Because slot exhaustion is wired to
   conversation death in three places at once:
   - Backend: when `nextSlot()` returns null the session slot becomes null,
     and `AdvisorController::reply()` answers **409 "already_complete"** to any
     further message (:162-170). The interview state machine has no
     post-recommendation state — "intake complete" IS "conversation over".
   - Overlay: `advisor-live-chat-v7.js:996-999` sets
     `button.disabled = state.complete; textarea.disabled = state.complete;`
     — the composer is disabled the moment `complete` is true.
   - Chat.vue: when `question === null` the composer section is replaced
     entirely by the recommend/submit panel (v-if/v-else at :236/:276).

6. **Hard question limit or slot completion?** No hard counter exists — it is
   purely a consequence of `AdvisorConversationFlow::nextSlot()` walking the
   fixed slot list (:87-106) and returning null. The "~9 questions" the
   product owner perceives is the applicable-slot count minus opportunistic
   pickups (see Q7/Q8).

7. **Applicable slots** (`AdvisorConversationFlow::slots()` :41-77):
   - Residential purchase: 10 — purpose, currency, budget, property_type
     (required) + area, workplace, schools, family, timeframe, payment
     (optional). `risk`/`return_goal` are skipped for non-investment (:97-100).
   - Investment: 12 — the ten above plus risk and return_goal.
   - Other purposes: none exist. `extractPurpose()` recognises only
     investment/residence keywords; anything else leaves purpose unanswered
     and the same question is re-asked. The matcher then defaults purpose to
     `residence` (AdvisorProjectMatcher:65).
   In practice currency is usually answered inside the budget reply, and
   purpose/type/budget/currency are picked up opportunistically, so a typical
   run lands at ~8-10 visible questions — matching the reported "about 9".

8. **Can one user message fill multiple criteria?** Partially. `mergeReply()`
   (:462-525) opportunistically extracts purpose, property_type, currency, and
   budget-with-explicit-unit from ANY answer, whatever slot was asked. But
   area, workplace, schools, family, timeframe, payment, risk and return_goal
   are captured only when their own slot is the one being asked — a message
   like "an apartment near Ankawa for my family, around 180 million IQD,
   installments preferred" fills type/budget/currency and then still gets
   asked about the area, the family and the payment plan individually.

9. **Free-text understanding: LLM, regex/parser, or deterministic PHP?**
   Deterministic PHP exclusively: keyword lists (`extractPurpose`,
   `extractPropertyType`, AdvisorConversationService:544-584), the currency
   detector (`AdvisorCurrency::detect`), and the digit/scale-word parser
   (`parseAmount` :689-726). The model receives the user's text only to
   *phrase* a reply; nothing it returns is parsed back into criteria.

10. **Is the external model allowed to select projects?** No.
    `AdvisorProjectMatcher::recommend()` queries `Project::query()->published()`
    and scores in PHP (:33-153). The model is never shown the candidate set on
    the live path, and `isSafeTurn()` rejects any turn containing a number the
    user did not themselves write (:224-229), links, HTML, or markdown.

11. **Is the final public recommendation model-generated or deterministic?**
    Deterministic, always. `finalRecommendation()` stamps
    `source=deterministic, model=null, provider=null,
    prompt_version=advisor-project-results-v6-grounded` (:151-171), and the
    message text is `AdvisorProjectMatcher`'s own localized copy.

12. **Does AI_PROVIDER actually choose a provider?** **No — confirmed
    configuration bug.** `config/services.php:15` defines
    `services.ai.provider`, the admin panel validates it
    (`Rule::in(['null','openai_compatible'])`,
    SystemSettingsController:122) and writes it to `.env`, the installer
    collects it — and **no runtime code reads it**. `AdvisorServiceProvider`
    :69-89 constructs the provider purely from `AI_BASE_URL` + `AI_API_KEY`
    presence. Consequences: setting `AI_PROVIDER=null` with a key and base URL
    still configured leaves the AI **on**; the admin "provider" selector is a
    placebo.

13. **Does AI_FALLBACK_MODEL actually work?** **No — confirmed dead setting.**
    It exists in `config/services.php:17`, `.env.example:88`, the
    EnvironmentSettings allowlist (:30), the SystemSettings screen
    (SystemSettings.vue:322) and the installer (StepValidator:115,244) — and is
    consumed by nothing. `AiGateway` has no model-level fallback at all, and
    `OpenAiCompatibleProvider` uses only `AI_MODEL`
    (`$request['model'] ?? $this->defaultModel`, :80). It is a fake field that
    looks functional in the UI.

14. **What happens when AI_API_KEY is empty?** The provider array stays empty
    (AdvisorServiceProvider:75), `isAvailable()` is false, every surface takes
    the deterministic fallback, and the public header shows the "unavailable"
    state (`ai_available` prop). No error, no broken page.

15. **What happens when AI_BASE_URL is empty?** Identical to Q14 — the same
    `if ($baseUrl !== '' && … $key !== '')` guard covers both.

16. **What happens on 401, 403, 429, timeout, malformed JSON, 5xx?**
    `OpenAiCompatibleProvider::complete()`:
    - 401/403 → `AiProviderException::unauthorized` (:108-110);
    - 429 → `::rateLimited` (:112-114);
    - transport failure/timeout → one silent retry on connection error
      (`retry(1, 200, throw: false)` :92), then `::unreachable` (:100-104) —
      deliberately swallowing the transfer exception because the URL may embed
      the key;
    - other non-2xx (5xx included) → `::failed` (:116-118);
    - non-JSON body or missing `choices[0].message.content` →
      `::invalidResponse` (:120-130).
    All of these surface in `AiGateway::complete()` as a caught
    `AiProviderException`: the failure is counted against the breaker, logged
    as `ai.provider_failed` with provider key + safe message only (:113-116),
    and the next configured provider is tried (today there is none), after
    which the caller's try/catch produces a deterministic fallback turn.
    **Gap:** the failure *category* is not recorded anywhere queryable — the
    typed factory methods collapse into one message string in a log file.

17. **Is the circuit breaker behaving correctly?** Mechanically yes, with one
    design consequence to note. Three consecutive failures (`FAILURE_THRESHOLD`)
    open the breaker for 300 s (`BREAKER_SECONDS`); each failure re-arms the
    TTL; one success clears it entirely (AiGateway:160-188). Breaker state
    also feeds `isAvailable()`, so an open breaker flips the public surface to
    the honest "unavailable" state rather than timing out on every turn.
    Consequences: the breaker keys on `provider->key()` — fine today, but any
    multi-provider rework must keep per-provider keys distinct. There is no
    admin-visible readout of breaker state (see Q20).

18. **Can configuration changes made in Admin become active immediately?**
    Yes. `EnvironmentSettings::update()` rewrites `.env` atomically and
    rebuilds the configuration cache (no-op under testing), and the
    `AiGateway` singleton is constructed per request from `config()`, so the
    next request binds a gateway from the new values. No queue-worker restart
    is needed for the web path (a long-running queue worker would need one,
    but the advisor runs synchronously in the request).

19. **Are any secrets exposed to Inertia/browser payloads?** No.
    `integrationPayload()` sends `api_key_configured` as a boolean only
    (SystemSettingsController:222); the AI key is written via the
    keep/replace/clear `mergeSecret()` pattern (:187) and is in
    `EnvironmentSettings::SECRET_KEYS` (:35-38). The public advisor page
    receives only `ai_available: bool`. Verified nothing else under
    `services.ai.*` reaches an Inertia prop.

20. **Are provider failures visible enough for Super Admin to diagnose?**
    **No.** Diagnostics today are: a `Log::warning('ai.provider_failed', …)`
    line in `laravel.log`, per-message `validation_detail` /
    `withheld_reason` columns in `advisor_messages`, and the public
    `ai_available` boolean. There is no admin surface showing configured
    provider, breaker state, spend against the monthly ceiling, last failure
    category, or a test-connection action. An "AI is not working" incident is
    currently diagnosable only with filesystem/log access.

## 3. Suspicions A–K, verified

| # | Suspicion | Verdict | Evidence |
|---|-----------|---------|----------|
| A | Deterministic slot sequence owned by AdvisorConversationFlow | **Confirmed** | `slots()` fixed ordered list, `AdvisorConversationFlow.php:41-77` |
| B | `nextSlot() === null` drives the end state | **Confirmed** | Controller 409 at `AdvisorController.php:162-170`; overlay disables composer at `advisor-live-chat-v7.js:996-999`; Chat.vue swaps composer for recommend panel |
| C | ckb bypasses model generation | **Confirmed** | `AdvisorTurnComposer.php:47-49`, explicit early return before the gateway |
| D | `finalRecommendation()` is deterministic with source=deterministic, model=null, provider=null | **Confirmed** | `AdvisorTurnComposer.php:151-171` |
| E | Only an OpenAI-compatible provider exists | **Confirmed** | `app/Modules/Advisor/Providers/` contains `OpenAiCompatibleProvider` and the service provider only; no other `AiProvider` implementation in the codebase |
| F | Admin validation accepts only `null` / `openai_compatible` | **Confirmed** | `SystemSettingsController.php:122`, `Rule::in(['null','openai_compatible'])`; installer mirrors it |
| G | AI_PROVIDER does not actually choose the provider | **Confirmed — real bug** | `AdvisorServiceProvider.php:69-89` never reads `services.ai.provider`; presence of base_url+key alone activates the adapter |
| H | AI_FALLBACK_MODEL participates in no fallback execution | **Confirmed — fake field** | Consumers are display/validation only (SystemSettingsController:127,178,219; StepValidator:115,244; SystemSettings.vue:322); zero runtime reads |
| I | No native Gemini adapter | **Confirmed** | repository-wide search for `gemini` (case-insensitive) returns nothing |
| J | No native MiniMax adapter | **Confirmed** | repository-wide search for `minimax` returns nothing |
| K | "Mini4" must not be silently assumed | **UNRESOLVED — reported, not guessed** | Case-insensitive search for `mini4` and `minimax` across all PHP/Vue/JS/MD/env files: zero hits. No repository or documentation evidence identifies what "Mini4" refers to. Per instruction, **MiniMax is NOT implemented**; the provider registry is designed so a `minimax` (or any other) adapter can be added as one class + one config block if the product owner later confirms the intent. |

## 4. Additional defects found during the trace

1. **The cost ceiling can never trip.** `AdvisorServiceProvider:81` reads
   `config('services.ai.rates', ['prompt'=>0.0,'completion'=>0.0])` — but
   `config/services.php` defines no `rates` key and no env var feeds one, so
   every completion is costed at `0.000000`
   (OpenAiCompatibleProvider::cost()), `recordSpend()` accumulates zero, and
   `AI_MONTHLY_COST_LIMIT_USD` — validated, stored, shown in the admin panel —
   is decorative. Token counts are recorded; dollars are not.
2. **The grounded answer pipeline is unreachable.**
   `AdvisorAnswerComposer` (RetrievalGuard → NumericGuard) has no live caller
   — see §1. The guards must be preserved and *wired into* the new
   conversational path, not left ornamental.
3. **No PHP test coverage for the Advisor module at all.** `tests/Feature` and
   `tests/Unit` contain no Advisor/AiGateway/NumericGuard/RetrievalGuard
   tests; the only coverage is a Playwright spec (`tests/Browser/advisor.spec.ts`)
   that skips its main body when the flag is off.
4. **Session-bound criteria, DB-bound transcript.** Criteria live in the
   session (`advisor.criteria`), messages in the DB. A session expiry orphans
   a live transcript's criteria; the "recovery" path patches one narrow case
   (last unanswered user turn). Any redesign must keep the two stores from
   drifting further apart.
5. **`AdvisorConversation.status`** is only ever `open`/`closed`; there is no
   stage concept (intake vs advisory), which is exactly the missing state that
   ties recommendation to termination.

## 5. Root cause, stated plainly

The product behaves like a questionnaire because it *is* one. The model is
invited only to re-phrase the next intake question (and only in Arabic and
English); understanding, memory, selection and the final recommendation are
PHP. The conversation ends after ~9 answers because `nextSlot() === null`
means simultaneously "intake sufficient" and "conversation dead" in the
backend (409), the overlay (disabled composer), and the page (composer
replaced). The provider architecture beneath it is sound in shape (contract,
gateway, breaker, budget-first check) but is single-adapter, ignores its own
`AI_PROVIDER` selector, carries a dead `AI_FALLBACK_MODEL` setting, computes
all costs as zero, and offers the Super Admin no way to see any of this.
