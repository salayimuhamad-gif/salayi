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

`AI_MONTHLY_COST_LIMIT_USD` defaults to `0`, meaning the AI provider is off.
It must be set deliberately before any AI surface can spend money.
