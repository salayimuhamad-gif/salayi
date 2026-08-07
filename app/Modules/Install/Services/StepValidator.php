<?php

declare(strict_types=1);

namespace App\Modules\Install\Services;

/**
 * Per-step validation and the answer→.env mapping (repair prompt §2.3, §2.5).
 *
 * Two things lived nowhere before this class existed:
 *
 *   1. VALIDATION. InstallController::store() persisted `$request->all()`
 *      unchecked, so a mistyped port, an empty database name or a four-letter
 *      admin password sailed through and failed several steps later at
 *      `migrate` — by which point the operator has no idea which screen was
 *      wrong. On shared hosting, where the installer is the only interface,
 *      that is the difference between a five-minute install and giving up.
 *
 *   2. THE MAPPING. The wizard collected answers into a JSON file and nothing
 *      ever turned them into configuration. EnvWriter existed, was bound in
 *      the container, and was never called by anything.
 *
 * Secrets are declared here too, and they are the reason the mapping and the
 * validation must sit together: a secret has to reach .env on the very request
 * that carries it, because InstallState deliberately refuses to persist it.
 * Miss that ordering and the password is simply lost.
 */
final class StepValidator
{
    /**
     * Fields that must never be written to the resumable state file
     * (repair prompt §2.5). Mirrors InstallState::TRANSIENT — kept in step with
     * it by StepValidatorTest, which fails if the two lists diverge.
     *
     * @var list<string>
     */
    public const SECRET_FIELDS = [
        'db_password',
        'mail_password',
        'admin_password',
        'admin_password_confirmation',
        'ai_api_key',
        'google_maps_api_key',
        'telegram_bot_token',
        'telegram_webhook_secret',
    ];

    /**
     * Validation rules per step.
     *
     * Steps absent from this list collect nothing (welcome, license) or are
     * read-only reports (requirements, extensions, permissions) or are executed
     * by InstallRunner rather than submitted (migrate onwards).
     *
     * @return array<string, array<int, string>>
     */
    public function rules(string $step): array
    {
        return match ($step) {
            'database' => [
                'db_host' => ['required', 'string', 'max:255'],
                'db_port' => ['required', 'integer', 'between:1,65535'],
                'db_database' => ['required', 'string', 'max:64'],
                'db_username' => ['required', 'string', 'max:64'],
                'db_password' => ['nullable', 'string', 'max:255'],
            ],

            'app_url' => [
                // A trailing slash or an http:// URL here produces broken
                // signed URLs and mixed-content warnings later, so both are
                // corrected in normalise() rather than merely rejected.
                'app_url' => ['required', 'string', 'max:255', 'url'],
                'app_name' => ['required', 'string', 'max:80'],
                'timezone' => ['required', 'string', 'timezone'],
            ],

            'mail' => [
                'mail_host' => ['required', 'string', 'max:255'],
                'mail_port' => ['required', 'integer', 'between:1,65535'],
                'mail_username' => ['nullable', 'string', 'max:255'],
                'mail_password' => ['nullable', 'string', 'max:255'],
                'mail_scheme' => ['required', 'string', 'in:tls,ssl,smtp'],
                'mail_from_address' => ['required', 'email', 'max:255'],
                'mail_from_name' => ['required', 'string', 'max:80'],
            ],

            'queue' => [
                // Hostinger shared hosting has no Redis and no daemon, so the
                // database driver is the only honest option; it is validated
                // rather than assumed so a VPS deployment can differ.
                'queue_connection' => ['required', 'string', 'in:database,sync'],
                'scheduler_confirmed' => ['required', 'accepted'],
            ],

            'map_provider' => [
                'map_provider' => ['required', 'string', 'in:maplibre,google'],
                'maplibre_style_url' => ['nullable', 'string', 'max:255', 'url'],
                // Required only when Google is chosen: MapLibre must stay usable
                // with no key at all, which is why it is the default.
                'google_maps_api_key' => ['nullable', 'string', 'max:255', 'required_if:map_provider,google'],
            ],

            'telegram' => [
                'telegram_enabled' => ['required', 'boolean'],
                'telegram_bot_token' => ['nullable', 'string', 'max:255', 'required_if:telegram_enabled,1'],
                'telegram_bot_username' => ['nullable', 'string', 'max:64'],
                'telegram_webhook_secret' => ['nullable', 'string', 'max:255'],
            ],

            'ai_provider' => [
                'ai_provider' => ['required', 'string', 'in:null,openai_compatible'],
                'ai_base_url' => ['nullable', 'string', 'max:255', 'url', 'required_if:ai_provider,openai_compatible'],
                'ai_api_key' => ['nullable', 'string', 'max:255', 'required_if:ai_provider,openai_compatible'],
                'ai_model' => ['nullable', 'string', 'max:120', 'required_if:ai_provider,openai_compatible'],
                'ai_fallback_model' => ['nullable', 'string', 'max:120'],
            ],

            'default_language' => [
                'default_locale' => ['required', 'string', 'in:ckb,ar,en'],
            ],

            'enabled_languages' => [
                'enabled_locales' => ['required', 'array', 'min:1'],
                'enabled_locales.*' => ['string', 'in:ckb,ar,en'],
            ],

            'branding' => [
                'site_name' => ['required', 'string', 'max:80'],
                'support_email' => ['nullable', 'email', 'max:255'],
                'support_phone' => ['nullable', 'string', 'max:40'],
            ],

            'super_admin' => [
                'admin_name' => ['required', 'string', 'max:120'],
                'admin_email' => ['required', 'email', 'max:255'],
                // 12 minimum: this account can read every encrypted phone
                // number in the system and Laravel's default of 8 is not
                // adequate for it.
                'admin_password' => ['required', 'string', 'min:12', 'max:255', 'confirmed'],
                'admin_locale' => ['required', 'string', 'in:ckb,ar,en'],
            ],

            default => [],
        };
    }

    /**
     * Normalise validated input before it is written anywhere.
     *
     * @param  array<string, mixed>  $validated
     * @return array<string, mixed>
     */
    public function normalise(string $step, array $validated): array
    {
        if ($step === 'app_url' && isset($validated['app_url'])) {
            $url = rtrim((string) $validated['app_url'], '/');

            // Spec requires HTTPS in production; silently upgrading is safer
            // than storing an http:// base that will emit mixed content.
            if (str_starts_with($url, 'http://')) {
                $url = 'https://'.substr($url, 7);
            }

            $validated['app_url'] = $url;
        }

        if ($step === 'enabled_languages' && isset($validated['enabled_locales'])) {
            /** @var list<string> $locales */
            $locales = array_values(array_unique((array) $validated['enabled_locales']));

            // ckb is the authored language and the fallback; a site that
            // disabled it would fall back to missing strings everywhere.
            if (! in_array('ckb', $locales, true)) {
                $locales[] = 'ckb';
            }

            $validated['enabled_locales'] = $locales;
        }

        return $validated;
    }

    /**
     * Map validated answers to .env keys for a step.
     *
     * Returning [] means the step contributes nothing to .env — its answers
     * belong in the database (branding, super admin) or nowhere at all.
     *
     * @param  array<string, mixed>  $answers
     * @return array<string, string|int|bool|null>
     */
    public function envFor(string $step, array $answers): array
    {
        $get = static fn (string $key, mixed $default = null): mixed => $answers[$key] ?? $default;

        return match ($step) {
            'database' => [
                'DB_CONNECTION' => 'mysql',
                'DB_HOST' => (string) $get('db_host'),
                'DB_PORT' => (int) $get('db_port', 3306),
                'DB_DATABASE' => (string) $get('db_database'),
                'DB_USERNAME' => (string) $get('db_username'),
                'DB_PASSWORD' => (string) $get('db_password', ''),
            ],

            'app_url' => [
                'APP_NAME' => (string) $get('app_name'),
                'APP_URL' => (string) $get('app_url'),
                'APP_TIMEZONE' => (string) $get('timezone', 'Asia/Baghdad'),
            ],

            'mail' => [
                'MAIL_MAILER' => 'smtp',
                'MAIL_HOST' => (string) $get('mail_host'),
                'MAIL_PORT' => (int) $get('mail_port', 587),
                'MAIL_USERNAME' => (string) $get('mail_username', ''),
                'MAIL_PASSWORD' => (string) $get('mail_password', ''),
                'MAIL_SCHEME' => (string) $get('mail_scheme', 'tls'),
                'MAIL_FROM_ADDRESS' => (string) $get('mail_from_address'),
                'MAIL_FROM_NAME' => (string) $get('mail_from_name'),
            ],

            'queue' => [
                'QUEUE_CONNECTION' => (string) $get('queue_connection', 'database'),
            ],

            'map_provider' => [
                'MAP_PROVIDER' => (string) $get('map_provider', 'maplibre'),
                'MAPLIBRE_STYLE_URL' => (string) $get('maplibre_style_url', ''),
                'GOOGLE_MAPS_API_KEY' => (string) $get('google_maps_api_key', ''),
            ],

            'telegram' => [
                'TELEGRAM_BOT_TOKEN' => (string) $get('telegram_bot_token', ''),
                'TELEGRAM_BOT_USERNAME' => (string) $get('telegram_bot_username', ''),
                'TELEGRAM_WEBHOOK_SECRET' => (string) $get('telegram_webhook_secret', ''),
            ],

            'ai_provider' => [
                'AI_PROVIDER' => (string) $get('ai_provider', 'null'),
                'AI_BASE_URL' => (string) $get('ai_base_url', ''),
                'AI_API_KEY' => (string) $get('ai_api_key', ''),
                'AI_MODEL' => (string) $get('ai_model', ''),
                'AI_FALLBACK_MODEL' => (string) $get('ai_fallback_model', ''),
            ],

            'default_language' => [
                'APP_LOCALE' => (string) $get('default_locale', 'ckb'),
                // Fallback follows the default: falling back to a language the
                // site does not author produces missing strings, not English.
                'APP_FALLBACK_LOCALE' => (string) $get('default_locale', 'ckb'),
            ],

            'enabled_languages' => [
                'APP_ENABLED_LOCALES' => implode(',', (array) $get('enabled_locales', ['ckb'])),
            ],

            default => [],
        };
    }

    /**
     * Strip secrets from an answer set before it reaches the state file.
     *
     * @param  array<string, mixed>  $answers
     * @return array<string, mixed>
     */
    public function withoutSecrets(array $answers): array
    {
        return array_diff_key($answers, array_flip(self::SECRET_FIELDS));
    }
}
