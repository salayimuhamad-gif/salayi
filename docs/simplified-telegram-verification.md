# Simplified registration and Telegram verification

The change in one line: **Telegram is used once, immediately after registration,
and never again.**

```text
REGISTER  →  OPEN TELEGRAM  →  PRESS START  →  VERIFIED  →  ACCOUNT READY
```

and every later visit:

```text
LOGIN (phone or email + password)  →  ACCOUNT
```

---

## 1. The old flow

```text
POST /register                     account created, signed in, unlinked
GET  /account/telegram/link        mints a TelegramLoginIntent
                                     purpose        = account_link
                                     expires_at     = now + 10 MINUTES
                                     bound to the browser SESSION
--- leave for Telegram, press START ---
POST /webhooks/telegram/updates    does NOT link. Parks a "candidate".
                                   bot: "go back to your browser and confirm"
--- RETURN TO THE BROWSER ---
poll → awaiting_confirmation       page shows the candidate identity
POST .../link/confirm              only now is the account linked
                                   bot sends a one-time handoff link, 5 MINUTES
→ /account/onboarding
```

Three separate clocks could end this: the intent (10 min), the return handoff
(5 min), and `PruneUnlinkedAccounts`, which **anonymised and soft-deleted** any
still-unlinked account after 72 hours.

And there was no way back in. Registration created accounts with `email = null`
and **no password**, while `/login` validated `email` + `password` — so an
ordinary customer's only door was `/login/telegram`, i.e. pressing START again,
on every single visit.

## 2. The new flow

```text
POST /register                     account created WITH A PASSWORD, signed in
                                   → a permanent verification token is minted
GET  /account/telegram/link        renders the SAME link every time
                                     no expiry, no session binding,
                                     no code, no countdown on screen
--- leave for Telegram, press START ---
POST /webhooks/telegram/updates    links the Telegram id, sets
                                   telegram_verified_at, consumes the token,
                                   all in one transaction
                                   bot: "✅ verified" + a plain link home
--- the open tab notices by itself (poll every 2.5s) ---
→ /account/onboarding              optional details: city, area, email,
                                   gender, date of birth
```

**One press. No second confirmation. No expiry.**

## 3. The permanent token

`telegram_verification_tokens`

| column | meaning |
| --- | --- |
| `token_hash` | `sha256(raw)` — the lookup key, UNIQUE |
| `token_encrypted` | the same value under `Crypt` — so the owner's own link can be re-rendered weeks later |
| `user_id` | the one account it may verify |
| `locale` | the language the bot answers in |
| `used_at` | set when a Start consumes it |
| `revoked_at` | set by "start again" or by abandoning the registration |

There is **no `expires_at` column**, and `isUsable()` consults no clock:

```text
VALID  ==  used_at IS NULL  AND  revoked_at IS NULL
```

### Why two stored forms

Hash-only would be the reflex, and it is wrong here. A hash cannot be shown
again, so somebody signing back in three weeks later could only be given a
*new* link — which would silently kill the one already sitting in their Telegram
chat. That exact bug was found and fixed once before in the account-link flow.
So the row carries a digest for lookup beside a ciphertext for display, which is
the pattern `users` already uses for phone numbers (`phone_index` +
`phone_encrypted`). The database alone yields neither a usable token nor a way
to derive one.

### What the token may do

Attach **one** Telegram identity to **one** account **that currently has none**.
It is not a login: it establishes no session, names no destination, and holds no
redirect. Holding it grants no access to the account it belongs to.

## 4. Security model

| Rule | Where it is enforced |
| --- | --- |
| Cryptographically random, opaque token | `TelegramVerificationToken::generateRaw()` — 32 CSPRNG bytes; encodes no id, name, phone or timestamp |
| Never stored usably | `token_hash` (irreversible) + `token_encrypted` (needs `APP_KEY`) |
| Telegram **numeric id** is the identity | `redeem()` reads `message.from.id`; the username is display metadata only |
| A Telegram id already on another account is never reassigned | in-transaction check **and** the UNIQUE index on `users.telegram_id` |
| An account already linked is never silently re-pointed | refused as `conflict`; changing a linked identity remains the separate, browser-confirmed `account_link` operation |
| A **spent** token cannot be claimed by a different Telegram account | `used_token_replay_by_other_sender` |
| Replay-safe | same sender + same spent token → the same success, nothing written |
| Atomic | one `DB::transaction` with `lockForUpdate()` on the token and the account |
| Webhook authenticity | unchanged: secret header + `TelegramWebhookInbox` replay ledger |
| No secrets in logs | audit rows carry `token_id` only — never the token, never its digest |
| No PII in the URL | the deep link contains one random hex string and nothing else |

### The accepted residual risk

Removing the browser-confirmation step means somebody who obtains a **live**
link before its owner uses it can bind *their* Telegram account to that
stranger's account. They gain no session, no password and no data — the token
authenticates nobody — so the harm is denial, not takeover, and it is bounded:

- the link is only ever rendered to the signed-in owner and never sent by email
  or SMS;
- the refusal is loud and audited when the real owner then tries;
- "start again" revokes and re-issues;
- the account has a password, so the owner is never locked out of it.

The old confirmation step is **not** removed from the flow that re-points an
account which already *has* a Telegram identity. That threat is genuinely
different and it keeps its gate.

## 5. Login and recovery

`/login` now takes one field, `login`, holding **a phone number or an email**.
Which one is decided by shape (`@` means email), never by trying one and then
the other — a fallback attempt would double what an attacker gets per submission.
Phone lookup goes through the same keyed blind index as everything else. The
throttle key is a digest of the identifier rather than the identifier itself.

Destination after sign-in:

- administrator → `/admin/dashboard` (unchanged)
- unverified customer → the verification page, where their **permanent link is
  waiting**
- everyone else → onboarding, then profile

Password reset is unchanged and still email-based; the optional email collected
during onboarding is what makes it available to customers. Verified Telegram is
now a *usable* future recovery channel — the identity is durably linked and
audited — but no such flow is enabled by this change.

## 6. Retention

`PruneUnlinkedAccounts` no longer reclaims an account that is **reachable**:

```text
has a password            → 'reachable_by_password'   → keep
holds a live token        → 'verification_pending'    → keep
```

Both are decided before the retention window. The pre-existing population the
sweep was written for — no email, no password, no live link, genuinely
unreachable — is still reclaimed on exactly the same schedule.

**Consequence, stated plainly:** a number typed by mistake during registration
is no longer released automatically after 72 hours. That is the direct cost of
"the link must still work next month". The releases available are the
self-service **"cancel my registration"** button (which revokes the link and
frees the number immediately) and support.

## 7. Existing users

- Accounts already verified: **untouched**. `telegram_verified_at` is not
  cleared, re-checked or re-proven, and no existing linked identity changes.
- Accounts with a password: sign in exactly as before; the field they type into
  is now labelled "phone number or email" and still accepts their email.
- Unverified accounts created before this change: they have no password, so the
  sweep still applies to them on the old schedule. Signing in is not possible
  for them (it never was); registering again after reclamation is.
- **Defect repaired along the way:** `TelegramAuthenticator::applyVerifiedContact()`
  set `telegram_id` without `telegram_verified_at`. The gate reads the
  timestamp, so a Share-Contact sign-in produced an account that every personal
  surface refused and bounced to a verification page which could not help it —
  a redirect loop with no exit. All three branches now stamp it, an
  already-verified account keeps its original timestamp, and redemption repairs
  an affected row the next time that person presses Start.

## 8. Profile fields

Registration asks for the minimum: **name, phone, password, language, terms.**

Everything else is asked once the account exists, on the onboarding screen,
inside a block labelled optional: **city, area, email, gender, date of birth**
— every one nullable, none of them gating anything.

City and area come from the admin-managed `areas` table (published rows only) —
**nothing is hard-coded**. Publish Erbil's neighbourhoods through the existing
Geography admin and the picker offers them with no code change. If a city has no
published areas yet, the area picker simply does not appear.

Only `profile_area_id` is stored, holding the finest choice made; the city is
**derived** from the area's place in the hierarchy, so there is no second column
to disagree with the first.

Date of birth, not age: an age is a number that becomes wrong every year.
