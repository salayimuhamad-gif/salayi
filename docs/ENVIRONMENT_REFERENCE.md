# Environment reference

Every variable in `.env.example`, what it does, and what breaks if it is wrong.
Referenced from the header of `.env.example` itself.

## Application

| Variable | Default | Notes |
| --- | --- | --- |
| `APP_NAME` | `Mulkihawler` | Display name. The admin-editable one is `settings('branding.site_name')`; this is the fallback before the database exists. |
| `APP_ENV` | `production` | `local`, `testing`, `production`. Controls strict Eloquent mode — lazy loading and missing attributes throw outside production. |
| `APP_KEY` | *(generated)* | **Never regenerate on an existing install.** Encrypts sessions, signed URLs and `mfa_recovery_codes`. |
| `APP_DEBUG` | `false` | Must be `false` in production. `true` exposes the environment on any error page. |
| `APP_URL` | — | Used for signed URLs, asset URLs and Telegram callbacks. Must include the scheme and must be the canonical host. |
| `APP_TIMEZONE` | `Asia/Baghdad` | Erbil. Affects scheduling and every displayed timestamp. |

## Localisation

| Variable | Default | Notes |
| --- | --- | --- |
| `APP_LOCALE` | `ckb` | Kurdish Sorani. Product default. |
| `APP_FALLBACK_LOCALE` | `ckb` | **Deliberately not `en`.** A missing key must render the key and be caught by `lang-parity`, never silently render English on a Sorani page. |
| `APP_ENABLED_LOCALES` | `ckb,ar,en` | Comma-separated. `ckb` is re-added automatically if omitted — it cannot be disabled. |
| `APP_FAKER_LOCALE` | `ar_IQ` | Test data only. |

## Installer

| Variable | Default | Notes |
| --- | --- | --- |
| `MULKIHAWLER_INSTALLED` | `false` | Belt to the lock file's braces. Either being true means installed. |
| `MULKIHAWLER_INSTALL_RESET_TOKEN` | — | Minimum 32 characters. Required to reopen `/install` after locking. Leave empty in normal operation. |

## Database

Standard Laravel keys. Two that matter here:

- `DB_CHARSET` must be `utf8mb4`. `utf8mb3` truncates Sorani text.
- `DB_COLLATION` should be `utf8mb4_unicode_ci`.

## Shared hosting profile

| Variable | Value | Why |
| --- | --- | --- |
| `CACHE_STORE` | `database` | No Redis on Hostinger. |
| `SESSION_DRIVER` | `database` | Must survive across PHP workers. |
| `SESSION_ENCRYPT` | `true` | Session payloads contain the MFA challenge marker. |
| `SESSION_SECURE_COOKIE` | `true` | Requires HTTPS. Set `false` only for local HTTP. |
| `QUEUE_CONNECTION` | `database` | No daemon may run. |
| `LOG_CHANNEL` | `daily` | With `LOG_DAILY_DAYS=14`. Shared disk quota is finite. |
| `LOG_LEVEL` | `warning` | `debug` in production fills the quota and leaks request detail. |

## Security

| Variable | Notes |
| --- | --- |
| `MULKIHAWLER_PII_KEY` | Encrypts `phone_encrypted`. Separate from `APP_KEY` so PII can be re-keyed without invalidating every session and signed URL. |
| `MULKIHAWLER_BLIND_INDEX_KEY` | Keys the HMAC that makes an encrypted phone searchable by equality. **If unset, `User::blindIndex()` throws** rather than falling back to an unkeyed hash — an unkeyed index looks like it works and is rainbow-table-able. |
| `MULKIHAWLER_ADMIN_MFA_REQUIRED` | `true`. Setting `false` disables the MFA gate for every administrative role. Only for local development. |
| `MULKIHAWLER_FORCE_HTTPS` | `true` in production. |

Both keys are generated automatically by `EnvWriter` on a fresh install if
absent, and preserved on upgrade.

## Providers

`MAP_PROVIDER`, `AI_PROVIDER`, `TELEGRAM_BOT_TOKEN` and friends are read only
through `config/services.php`, and `config/services.php` reads only from the
environment.

This is deliberate and is spec §37.5: **a provider credential never lives in
the database and is never readable through the admin interface.** An
administrator chooses *which* provider is active through site settings; the key
itself is visible only to whoever has filesystem access.

### AI provider selection (multi-provider schema)

`AI_PROVIDER` selects the primary AI provider — `null` (off), `openai`,
`gemini`, or `openai_compatible` — and `AI_FALLBACK_PROVIDER` optionally
names one fallback from the same set. `null` means OFF regardless of stored
credentials. Each provider carries its own credentials:

| Key | Meaning |
| --- | --- |
| `OPENAI_API_KEY`, `OPENAI_MODEL` | The hosted OpenAI adapter. |
| `GEMINI_API_KEY`, `GEMINI_MODEL` | The Google Gemini adapter. |
| `OPENAI_COMPATIBLE_BASE_URL`, `OPENAI_COMPATIBLE_API_KEY`, `OPENAI_COMPATIBLE_MODEL` | Any OpenAI-compatible endpoint (Groq, Together, OpenRouter, vLLM, Ollama…). |
| `AI_TIMEOUT` | Per-request timeout, seconds. |
| `AI_MONTHLY_COST_LIMIT_USD` | Hard monthly ceiling; at `0` no ceiling applies. Checked BEFORE each call, and it gates the fallback provider too. |
| `AI_PROMPT_COST_PER_MTOK`, `AI_COMPLETION_COST_PER_MTOK` | USD per one million tokens, used to price usage toward the ceiling. Left at `0`, spend records as zero and the ceiling cannot trip — the admin diagnostics say so. |

**Legacy compatibility:** the historical single-provider keys `AI_BASE_URL`,
`AI_API_KEY` and `AI_MODEL` remain readable as defaults for the
`openai_compatible` block, so an existing `.env` with
`AI_PROVIDER=openai_compatible` keeps working unchanged. `AI_FALLBACK_MODEL`
is **retired**: it was stored and displayed but executed by nothing (see
`docs/AI_ADVISOR_AUDIT.md`, finding H); the fallback model is now simply the
fallback provider's own configured model.

### Bird / WhatsApp OTP verification

Account verification by WhatsApp one-time code, sent through Bird's official
Channels API (`POST /workspaces/{id}/channels/{id}/messages`, authenticated by
`Authorization: AccessKey …`, success = HTTP 202 Accepted). Off until the
first four keys are all set; the verification-choice page then offers
WhatsApp beside Telegram. Full setup walkthrough — the exact Bird objects to
create and the one real test send required before production enablement — in
`docs/BIRD_WHATSAPP_OTP.md`.

| Key | Meaning |
| --- | --- |
| `BIRD_API_KEY` | Workspace access key with permission to create messages on the channel. |
| `BIRD_WORKSPACE_ID` | The Bird workspace id (UUID). |
| `BIRD_WHATSAPP_CHANNEL_ID` | The connected WhatsApp channel id (UUID). |
| `BIRD_OTP_TEMPLATE_PROJECT_ID` | The approved verification template's TEMPLATE-PROJECT id (UUID). The raw Channels API references templates by project id, not by the SDK's slug. |
| `BIRD_OTP_TEMPLATE_VERSION` | Template version to render: `latest` (default) or a version UUID. |
| `BIRD_OTP_TEMPLATE_LOCALE` | Which published locale of the template to render (default `en`). |
| `BIRD_OTP_TEMPLATE_PARAMETER_KEY` | The template variable key that carries the six digits (default `code`). |
| `BIRD_BASE_URL` | Documented API host; leave at `https://api.bird.com`. |
