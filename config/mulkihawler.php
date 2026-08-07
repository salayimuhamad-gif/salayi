<?php

declare(strict_types=1);

/*
 * Product-level configuration that is NOT admin-editable.
 * Anything an administrator may change at runtime lives in the
 * `site_settings` table and is read through the settings() helper (spec 8).
 */
return [
    'version' => '4.0.0-step30',

    /*
     * Raised with the wizard migrations: project_drafts, project_prices,
     * project_draft_media, membership project rights, and the media-cleanup /
     * association-provenance columns. The installer compares this against the
     * database, so leaving it behind makes an upgraded schema look current.
     */
    /*
     * 12 covers the company↔developer association domain (001100) on top of
     * the lifecycle constraint (001000) and the provenance and
     * creation-evidence migrations (000800, 000900). The installer compares this against the
     * database, so a schema change without a version bump makes an upgraded
     * database look current.
     */
    'schema_version' => 18,

    'wizard' => [
        /*
         * How long an untouched Project Creation Wizard draft survives.
         * Configurable because a data-entry campaign and steady-state
         * operation want different answers; 30 days is long enough that
         * somebody returning after a holiday still finds their work.
         */
        'draft_retention_days' => (int) env('MULKIHAWLER_DRAFT_RETENTION_DAYS', 30),
    ],

    'city' => [
        'default_country' => 'IQ',
        'default_city' => 'erbil',
        // Bounding box used to reject obviously wrong coordinates on import
        // (spec 11.3 "detect coordinate anomalies"). Generous on purpose:
        // it rejects a Baghdad or a swapped lat/lng, not a suburb.
        'bounds' => [
            'min_lat' => 35.90,
            'max_lat' => 36.50,
            'min_lng' => 43.70,
            'max_lng' => 44.40,
        ],
    ],

    'money' => [
        // Spec 26.1: financial values use Decimal. Never float.
        'scale' => 4,
        'display_scale' => 0,
        'default_currency' => 'USD',
        'supported_currencies' => ['USD', 'IQD', 'EUR'],
    ],

    'security' => [
        'admin_mfa_required' => (bool) env('MULKIHAWLER_ADMIN_MFA_REQUIRED', true),
        'force_https' => (bool) env('MULKIHAWLER_FORCE_HTTPS', true),
        // Keys are separate from APP_KEY so PII can be re-keyed without
        // invalidating every session and signed URL (spec 30.1).
        'pii_key' => env('MULKIHAWLER_PII_KEY'),
        'blind_index_key' => env('MULKIHAWLER_BLIND_INDEX_KEY'),
        'password_min_length' => 12,
        'admin_session_idle_minutes' => 60,
        'login_throttle' => ['attempts' => 5, 'decay_minutes' => 15],
    ],

    'audit' => [
        // Spec 26.1: every administrator mutation is auditable.
        'enabled' => true,
        'retain_days' => 1095,
        'redact_keys' => [
            'password', 'password_confirmation', 'current_password',
            'token', 'api_key', 'secret', 'phone', 'phone_encrypted',
            'telegram_id', 'mfa_secret', 'recovery_codes',
        ],
    ],

    'telegram' => [
        /*
         * v4 §5 — browser confirmation after Telegram Start.
         *
         * The account-link deep-link token is a bearer secret and
         * Telegram's webhook cannot prove which browser minted it. With
         * this ON (the default, and the reviewed posture), a Start only
         * PARKS a candidate identity; the signed-in browser that started
         * the flow must confirm it before any link is written. A leaked
         * token therefore buys an attacker a rejected candidate rather
         * than somebody else's account.
         *
         * Turning it OFF restores the v3 Start-completes-the-link
         * behaviour. That is a deliberate acceptance of the residual risk
         * documented in SECURITY_REVIEW.md, not a tuning knob.
         */
        'link_requires_browser_confirmation' => (bool) env(
            'MULKIHAWLER_TELEGRAM_LINK_BROWSER_CONFIRMATION',
            true
        ),
    ],

    'registration' => [
        // Consent policy version stamped onto every consent record.
        'policy_version' => env('MULKIHAWLER_POLICY_VERSION', '2026-08-01'),

        /*
         * How long an account that never linked Telegram is kept before it is
         * reclaimed (v7 account-first).
         *
         * 72 hours is the product default, chosen against two opposing costs.
         * Too short and somebody who registered on Thursday evening, could not
         * find their phone, and came back after the weekend loses their
         * registration. Too long and their phone number — held by a UNIQUE
         * index — stays locked away from its real owner, and an unverified
         * name and number sit in the database on the strength of nothing.
         *
         * Three days covers a weekend, which is the realistic gap between
         * starting and finishing. Lower it only with the recovery path in
         * mind: until it elapses, the ONLY way back into an unlinked account
         * is the browser session that created it.
         */
        'unlinked_retention_hours' => (int) env('MULKIHAWLER_UNLINKED_RETENTION_HOURS', 72),
    ],

    'hosting' => [
        // Shared-hosting profile. Drives which installer checks are fatal
        // vs advisory, and whether the queue worker is cron-driven.
        'profile' => env('MULKIHAWLER_HOSTING_PROFILE', 'shared'),
        'has_redis' => false,
        'has_shell' => false,
        'queue_worker' => 'cron',
        'max_job_seconds' => 50,
    ],
];
