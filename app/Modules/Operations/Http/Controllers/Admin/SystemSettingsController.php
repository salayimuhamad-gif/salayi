<?php

declare(strict_types=1);

namespace App\Modules\Operations\Http\Controllers\Admin;

use App\Modules\Operations\Services\AuditLogger;
use App\Modules\Operations\Services\EnvironmentSettings;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use RuntimeException;

final class SystemSettingsController extends Controller
{
    public function __construct(
        private readonly EnvironmentSettings $environment,
        private readonly AuditLogger $audit,
    ) {}

    public function index(Request $request): Response
    {
        $supportedLocales = array_keys((array) config('localization.supported', []));
        $enabledLocales = array_values(array_filter(
            explode(',', (string) $this->environment->value('APP_ENABLED_LOCALES', implode(',', enabled_locales()))),
            static fn (string $locale): bool => $locale !== '',
        ));

        $isSuperAdmin = $request->user()?->isSuperAdmin() === true;

        return Inertia::render('Admin/SystemSettings', [
            'general' => [
                'app_name' => (string) $this->environment->value('APP_NAME', (string) config('app.name')),
                'app_url' => (string) $this->environment->value('APP_URL', (string) config('app.url')),
                'timezone' => (string) $this->environment->value('APP_TIMEZONE', (string) config('app.timezone')),
                'default_locale' => (string) $this->environment->value('APP_LOCALE', (string) config('app.locale')),
                'enabled_locales' => $enabledLocales,
                'queue_connection' => (string) $this->environment->value('QUEUE_CONNECTION', (string) config('queue.default')),
            ],
            'supported_locales' => array_map(
                static fn (string $code): array => [
                    'value' => $code,
                    'label' => (string) config("localization.supported.{$code}.native", $code),
                ],
                $supportedLocales,
            ),
            'timezones' => timezone_identifiers_list(),
            'environment_writable' => $this->environment->isWritable(),
            // Behind `auth`+`mfa`, user() is non-null; the nullsafe chain made
            // the ?? unreachable and stan flagged the dead branch.
            'can_update_general' => $request->user()->hasPermission('system.settings.update'),
            'can_manage_integrations' => $isSuperAdmin
                && $request->user()->hasPermission('system.integrations.update'),
            'integrations' => $isSuperAdmin ? $this->integrationPayload() : null,
        ]);
    }

    public function updateGeneral(Request $request): RedirectResponse
    {
        $supportedLocales = array_keys((array) config('localization.supported', []));

        $validated = $request->validate([
            'app_name' => ['required', 'string', 'max:120'],
            'app_url' => ['required', 'url:http,https', 'max:255'],
            'timezone' => ['required', 'string', Rule::in(timezone_identifiers_list())],
            'default_locale' => ['required', 'string', Rule::in($supportedLocales)],
            'enabled_locales' => ['required', 'array', 'min:1'],
            'enabled_locales.*' => ['required', 'string', 'distinct', Rule::in($supportedLocales)],
            'queue_connection' => ['required', 'string', Rule::in(['database', 'sync'])],
        ]);

        if (! in_array($validated['default_locale'], $validated['enabled_locales'], true)) {
            return back()->withErrors([
                'enabled_locales' => __('system.validation.default_locale_enabled'),
            ]);
        }

        return $this->apply(
            [
                'APP_NAME' => $validated['app_name'],
                'APP_URL' => rtrim($validated['app_url'], '/'),
                'APP_TIMEZONE' => $validated['timezone'],
                'APP_LOCALE' => $validated['default_locale'],
                'APP_FALLBACK_LOCALE' => $validated['default_locale'],
                'APP_ENABLED_LOCALES' => implode(',', $validated['enabled_locales']),
                'QUEUE_CONNECTION' => $validated['queue_connection'],
            ],
            'system.settings.updated',
        );
    }

    public function updateIntegrations(Request $request): RedirectResponse
    {
        abort_unless($request->user()?->isSuperAdmin() === true, 403);

        $validator = Validator::make($request->all(), [
            'mail_host' => ['required', 'string', 'max:255'],
            'mail_port' => ['required', 'integer', 'between:1,65535'],
            'mail_username' => ['nullable', 'string', 'max:255'],
            'mail_password' => ['nullable', 'string', 'max:2048'],
            'clear_mail_password' => ['required', 'boolean'],
            'mail_scheme' => ['required', 'string', Rule::in(['tls', 'ssl', 'smtp'])],
            'mail_from_address' => ['required', 'email', 'max:255'],
            'mail_from_name' => ['required', 'string', 'max:120'],

            'map_provider' => ['required', 'string', Rule::in(['maplibre', 'google'])],
            'maplibre_style_url' => ['nullable', 'url:http,https', 'max:1024'],
            'google_maps_api_key' => ['nullable', 'string', 'max:2048'],
            'clear_google_maps_api_key' => ['required', 'boolean'],

            'telegram_bot_username' => ['nullable', 'string', 'max:64', 'regex:/^[A-Za-z0-9_]+$/'],
            'telegram_bot_token' => ['nullable', 'string', 'max:2048'],
            'clear_telegram_bot_token' => ['required', 'boolean'],
            'telegram_webhook_secret' => ['nullable', 'string', 'max:2048'],
            'clear_telegram_webhook_secret' => ['required', 'boolean'],

            'ai_provider' => ['required', 'string', Rule::in(['null', 'openai_compatible'])],
            'ai_base_url' => ['nullable', 'url:http,https', 'max:1024'],
            'ai_api_key' => ['nullable', 'string', 'max:2048'],
            'clear_ai_api_key' => ['required', 'boolean'],
            'ai_model' => ['nullable', 'string', 'max:160'],
            'ai_fallback_model' => ['nullable', 'string', 'max:160'],
            'ai_timeout' => ['required', 'integer', 'between:1,120'],
            'ai_monthly_cost_limit_usd' => ['required', 'numeric', 'min:0', 'max:1000000'],
        ]);

        $validator->after(function ($validator) use ($request): void {
            if ($request->string('map_provider')->toString() === 'maplibre'
                && $request->string('maplibre_style_url')->toString() === '') {
                $validator->errors()->add('maplibre_style_url', __('system.validation.maplibre_style_required'));
            }

            if ($request->string('map_provider')->toString() === 'google'
                && ! $this->incomingOrConfigured($request, 'google_maps_api_key', 'clear_google_maps_api_key', 'GOOGLE_MAPS_API_KEY')) {
                $validator->errors()->add('google_maps_api_key', __('system.validation.google_key_required'));
            }

            if ($request->string('ai_provider')->toString() === 'openai_compatible') {
                if ($request->string('ai_base_url')->toString() === '') {
                    $validator->errors()->add('ai_base_url', __('system.validation.ai_base_url_required'));
                }

                if ($request->string('ai_model')->toString() === '') {
                    $validator->errors()->add('ai_model', __('system.validation.ai_model_required'));
                }

                if (! $this->incomingOrConfigured($request, 'ai_api_key', 'clear_ai_api_key', 'AI_API_KEY')) {
                    $validator->errors()->add('ai_api_key', __('system.validation.ai_key_required'));
                }
            }
        });

        /** @var array<string, mixed> $validated */
        $validated = $validator->validate();

        $values = [
            'MAIL_MAILER' => 'smtp',
            'MAIL_HOST' => $validated['mail_host'],
            'MAIL_PORT' => (int) $validated['mail_port'],
            'MAIL_USERNAME' => $validated['mail_username'] ?? '',
            'MAIL_SCHEME' => $validated['mail_scheme'],
            'MAIL_FROM_ADDRESS' => $validated['mail_from_address'],
            'MAIL_FROM_NAME' => $validated['mail_from_name'],

            'MAP_PROVIDER' => $validated['map_provider'],
            'MAPLIBRE_STYLE_URL' => $validated['maplibre_style_url'] ?? '',

            'TELEGRAM_BOT_USERNAME' => $validated['telegram_bot_username'] ?? '',

            'AI_PROVIDER' => $validated['ai_provider'],
            'AI_BASE_URL' => $validated['ai_base_url'] ?? '',
            'AI_MODEL' => $validated['ai_model'] ?? '',
            'AI_FALLBACK_MODEL' => $validated['ai_fallback_model'] ?? '',
            'AI_TIMEOUT' => (int) $validated['ai_timeout'],
            'AI_MONTHLY_COST_LIMIT_USD' => (float) $validated['ai_monthly_cost_limit_usd'],
        ];

        $this->mergeSecret($values, $validated, 'mail_password', 'clear_mail_password', 'MAIL_PASSWORD');
        $this->mergeSecret($values, $validated, 'google_maps_api_key', 'clear_google_maps_api_key', 'GOOGLE_MAPS_API_KEY');
        $this->mergeSecret($values, $validated, 'telegram_bot_token', 'clear_telegram_bot_token', 'TELEGRAM_BOT_TOKEN');
        $this->mergeSecret($values, $validated, 'telegram_webhook_secret', 'clear_telegram_webhook_secret', 'TELEGRAM_WEBHOOK_SECRET');
        $this->mergeSecret($values, $validated, 'ai_api_key', 'clear_ai_api_key', 'AI_API_KEY');

        return $this->apply($values, 'system.integrations.updated');
    }

    /** @return array<string, mixed> */
    private function integrationPayload(): array
    {
        return [
            'mail' => [
                'host' => (string) $this->environment->value('MAIL_HOST', (string) config('mail.mailers.smtp.host')),
                'port' => (int) $this->environment->value('MAIL_PORT', (string) config('mail.mailers.smtp.port')),
                'username' => (string) $this->environment->value('MAIL_USERNAME', (string) config('mail.mailers.smtp.username')),
                'scheme' => (string) $this->environment->value('MAIL_SCHEME', (string) config('mail.mailers.smtp.scheme')),
                'from_address' => (string) $this->environment->value('MAIL_FROM_ADDRESS', (string) config('mail.from.address')),
                'from_name' => (string) $this->environment->value('MAIL_FROM_NAME', (string) config('mail.from.name')),
                'password_configured' => $this->environment->configured('MAIL_PASSWORD'),
            ],
            'maps' => [
                'provider' => (string) $this->environment->value('MAP_PROVIDER', (string) config('services.maps.provider', 'maplibre')),
                'maplibre_style_url' => (string) $this->environment->value('MAPLIBRE_STYLE_URL', (string) config('services.maps.maplibre_style_url')),
                'google_key_configured' => $this->environment->configured('GOOGLE_MAPS_API_KEY'),
            ],
            'telegram' => [
                'bot_username' => (string) $this->environment->value('TELEGRAM_BOT_USERNAME', (string) config('services.telegram.bot_username')),
                'bot_token_configured' => $this->environment->configured('TELEGRAM_BOT_TOKEN'),
                'webhook_secret_configured' => $this->environment->configured('TELEGRAM_WEBHOOK_SECRET'),
            ],
            'ai' => [
                'provider' => (string) $this->environment->value('AI_PROVIDER', (string) config('services.ai.provider', 'null')),
                'base_url' => (string) $this->environment->value('AI_BASE_URL', (string) config('services.ai.base_url')),
                'model' => (string) $this->environment->value('AI_MODEL', (string) config('services.ai.model')),
                'fallback_model' => (string) $this->environment->value('AI_FALLBACK_MODEL', (string) config('services.ai.fallback_model')),
                'timeout' => (int) $this->environment->value('AI_TIMEOUT', (string) config('services.ai.timeout', 30)),
                'monthly_cost_limit_usd' => (float) $this->environment->value('AI_MONTHLY_COST_LIMIT_USD', (string) config('services.ai.monthly_cost_limit_usd', 0)),
                'api_key_configured' => $this->environment->configured('AI_API_KEY'),
            ],
        ];
    }

    private function incomingOrConfigured(
        Request $request,
        string $input,
        string $clearInput,
        string $environmentKey,
    ): bool {
        if ($request->boolean($clearInput)) {
            return false;
        }

        return $request->string($input)->toString() !== '' || $this->environment->configured($environmentKey);
    }

    /**
     * @param  array<string, string|int|float|bool|null>  $values
     * @param  array<string, mixed>  $validated
     */
    private function mergeSecret(
        array &$values,
        array $validated,
        string $input,
        string $clearInput,
        string $environmentKey,
    ): void {
        if ((bool) ($validated[$clearInput] ?? false)) {
            $values[$environmentKey] = '';

            return;
        }

        $incoming = trim((string) ($validated[$input] ?? ''));

        if ($incoming !== '') {
            $values[$environmentKey] = $incoming;
        }
    }

    /**
     * @param  array<string, string|int|float|bool|null>  $values
     */
    private function apply(array $values, string $auditAction): RedirectResponse
    {
        try {
            $changed = $this->environment->update($values);
        } catch (RuntimeException $e) {
            Log::error('System settings update failed', [
                'reason' => $e->getMessage(),
                'keys' => array_keys($values),
            ]);

            return back()->with('error', __('system.messages.write_failed'));
        }

        $this->audit->record($auditAction, null, [
            'keys' => $changed,
            'secret_keys' => array_values(array_filter(
                $changed,
                fn (string $key): bool => $this->environment->isSecret($key),
            )),
        ], severity: 'warning');

        return back()->with('success', $changed === []
            ? __('system.messages.no_changes')
            : __('system.messages.saved'));
    }
}
