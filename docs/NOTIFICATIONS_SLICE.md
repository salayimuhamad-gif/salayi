# Notifications — the transport, and everything that was waiting on it

The previous slice added the transport and left five things unwired: the
unsubscribe endpoint, the in-app centre, the dispatch call sites, digest
batching, and a real Telegram call. Four are now closed. The fifth is closed in
code and **still unverified against the live API**, for the reason given below.

## What each step closed

**Unsubscribe (was: "the promise the message makes is not yet kept").**
A stateless HMAC token, no table — a link printed into a Telegram message six
months ago still resolves, because the recipient did not agree to a deadline.
GET renders a confirmation page and POST performs the withdrawal: Telegram and
every mail client fetch link previews, and a GET that acted would opt people out
because a bot glanced at the message. The withdrawal is written as a new
`consents` row rather than an edit, so `ConsentGate` refuses the next send with
no special case anywhere.

**In-app centre (was: "delivers into a void a user cannot open").**
Unread badge, mark read, mark all read, pagination, detail, mobile layout. Not
permission-gated: the question is never "may this user read notifications" but
"is this row theirs", answered by a `forUser()` scope on every query, with a 404
rather than a 403 for someone else's id.

**Dispatch wiring (was: "nothing calls the dispatcher yet").**
Listing expiry, offer approved, offer rejected, changes requested, rating
review, and account/security. Moderation and security travel under transactional
purposes, so a seller learns their listing was rejected having declined every
optional channel. Messages are composed in the RECIPIENT's language, not the
actor's — an English-speaking moderator's rejection reaches a Sorani seller in
Sorani.

**Preferences.** A recipient chooses immediate or daily, and the send hour,
from their own screen. `setFrequency()` had existed since the digest shipped
with nothing calling it, so the only route onto the digest was a hand-written
database row. There is deliberately no administrative path to change somebody
else's frequency: how often a person is contacted is their decision, and an
admin lever for it is a lever for raising another person's message volume.

**Digest (was: "every event would send its own message").**
Deferral delays the PUSH and never the INFORMATION: the in-app row is written
immediately and only the external send waits. Transactional purposes and
high/urgent priority are never batched — holding "your password was changed" for
a day is the difference between catching a takeover and reading about it
tomorrow.

## Three defects the new guards caught

Worth recording, because all three fail silently:

1. **The dispatcher had no channels registered.** Container auto-resolution
   produced an empty channel list, which does not error: every send reports
   success, nothing is delivered, and the audit row says so in a field nobody
   reads.
2. **`RatingController` referenced `app.states.approved`, which does not exist.**
   It would have shipped the literal key inside a message.
3. **`ConsentGate` knew the purpose `telegram_message` and nothing could
   unsubscribe from it.** The envelope would have thrown at send time, inside
   `Notifier`'s catch, becoming a log line and a lost notification.

## Telegram, still honestly labelled

**The channel has still never been called against the real API.** That is not a
missing intention; it is an environment fact, and it was tested rather than
assumed:

```
$ curl -D- https://api.telegram.org/bot0:X/getMe
HTTP/2 403
x-deny-reason: host_not_allowed
Host not in allowlist: api.telegram.org.
```

The 403 comes from the egress proxy, not from Telegram. No request left the
machine, so nothing about the live API has been observed.

What IS now executed, against a stubbed transport: the endpoint URL, the exact
payload keys, that the rendered text carries the reason and the unsubscribe line
(spec 22.3 surviving the last hop), that the bot token never appears in the
message body, that 403 maps to `recipient_blocked_bot` and other statuses to
`telegram_error`, that a thrown request cannot escape and abort the in-app
fallback, and that a missing token makes the channel unavailable rather than
throwing. The 403 mapping is asserted against the DOCUMENTED contract — it is
what the code does with a 403, not proof Telegram sends one.

To close it, on a machine with the token:

```bash
php artisan mulkihawler:notifications:telegram-check
php artisan mulkihawler:notifications:telegram-check --chat=<your chat id>
```

Token from `.env` only (spec 37.5) — never an argument, so it stays out of shell
history and the process list. The probe sends through `TelegramChannel::send()`
rather than around it, so what it verifies is this product's code.

## Verification

| Check | Result |
| --- | --- |
| `php -l`, whole tree | **PASS** 307/307 |
| Standalone logic suite | **PASS** — 1,409 assertions, 0 failures |
| Translation parity | **PASS** — 1,051 keys, ckb/ar/en |
| Migration guard | **PASS** — 16 migrations, all reversible |
| Secret scan | **PASS** — no committed secrets |
| Telegram live API | **NOT VERIFIED** — egress blocked, see above |
| PHPUnit feature suite | **NOT RUN** — Packagist unreachable (403), no `composer install` |

The feature suite now carries 265 tests, of which 44 are new across this work.
**None of the 265 has been executed here**, and that is unchanged from the
previous slice: without `composer install` there is no framework to run them
against. They are written to run, not known to pass.

## Remaining issues

1. **The feature suite has never run.** Everything database-backed, HTTP-routed
   or Inertia-rendered is verified by inspection only.
2. **Telegram has still never touched its API.**
3. **The digest writes its own in-app row.** The dispatcher always appends the
   database channel, so a digest creates a summary notification alongside the
   items it summarises. Left deliberately: suppressing it would mean special-
   casing the dispatcher's "nothing is lost" invariant.

## Rollback

Additive. One new table, two new columns, both reversible.

```bash
php artisan migrate:rollback --step=1
rm -rf app/Modules/Notifications/Console app/Modules/Notifications/Http \
       app/Modules/Notifications/Models app/Modules/Notifications/Routes \
       app/Modules/Notifications/Services/{Notifier,NotificationComposer,RecipientResolver,DigestPreferences}.php \
       app/Modules/Notifications/Support/{UnsubscribeToken,UnsubscribeUrl}.php \
       app/Modules/Notifications/Database/Migrations/2026_07_01_000300_add_notification_digest.php \
       resources/js/Pages/Admin/Notifications resources/js/Pages/Public/Unsubscribe.vue \
       lang/*/notifications.php
rm -f  tests/Standalone/{UnsubscribeToken,TranslationUsage,DispatchWiring,Digest,TelegramChannel}Test.php \
       tests/Feature/{UnsubscribeEndpoint,NotificationCenter,NotificationDigest,NotificationPreferences}Test.php
git checkout -- app/Http/Middleware/HandleInertiaRequests.php \
                app/Modules/Core/Support/AdminNavigation.php \
                app/Modules/Notifications/Providers/NotificationsServiceProvider.php \
                app/Modules/Notifications/Channels/DatabaseChannel.php \
                app/Modules/Operations/Console/ExpireLapsedRecords.php \
                app/Modules/Marketplace/Http/Controllers/Admin/OfferController.php \
                app/Modules/Projects/Http/Controllers/Admin/RatingController.php \
                app/Modules/Identity/Http/Controllers/Auth/ \
                resources/js/Layouts/AdminLayout.vue resources/js/Types/inertia.d.ts \
                lang/*/nav.php tests/Standalone/harness.php
```
