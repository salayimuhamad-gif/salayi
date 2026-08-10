<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\Identity\Enums\RoleKey;
use App\Modules\Identity\Models\Role;
use App\Modules\Identity\Models\User;
use App\Modules\Operations\Models\AuditLog;
use App\Modules\Operations\Services\EnvironmentSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * The Super Admin AI control panel: multi-provider configuration, the
 * keep/replace/clear secret contract, the audited rate-limited connection
 * test — and above all the rule that no credential ever travels toward the
 * browser in any form but `configured: true/false`.
 */
final class SystemSettingsAiTest extends TestCase
{
    use RefreshDatabase;

    private const FAKE_KEY = 'fake-super-secret-key-value-12345';

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
    }

    private function admin(): User
    {
        return User::factory()->superAdmin()->create();
    }

    private function operator(RoleKey $key): User
    {
        $user = User::factory()->create();

        $user->roles()->attach(Role::query()->firstOrCreate(
            ['key' => $key->value],
            ['name' => $key->value, 'is_system' => true],
        ));

        return $user;
    }

    /** Aim the real writer at a disposable file under storage. */
    private function disposableEnvironment(): string
    {
        $path = storage_path('framework/testing/system-settings/.env');
        @mkdir(dirname($path), 0o700, true);
        file_put_contents($path, "APP_NAME=Test\n");
        $this->app->instance(EnvironmentSettings::class, new EnvironmentSettings($path));

        return $path;
    }

    private function cleanupEnvironment(string $path): void
    {
        $sweep = static function (string $directory): void {
            foreach (array_diff(scandir($directory) ?: [], ['.', '..']) as $stray) {
                @unlink($directory.'/'.$stray);
            }
        };

        $sweep(dirname($path));
        @rmdir(dirname($path));

        if (is_dir($backups = storage_path('app/private/system-settings/env-backups'))) {
            $sweep($backups);
        }
    }

    /** A full valid integrations payload the AI fields can be varied on. */
    private function payload(array $overrides = []): array
    {
        return array_merge([
            'mail_host' => 'smtp.test', 'mail_port' => 587, 'mail_username' => '',
            'mail_password' => '', 'clear_mail_password' => false, 'mail_scheme' => 'tls',
            'mail_from_address' => 'noreply@test.example', 'mail_from_name' => 'Test',
            'map_provider' => 'maplibre', 'maplibre_style_url' => 'https://tiles.test/style.json',
            'google_maps_api_key' => '', 'clear_google_maps_api_key' => false,
            'telegram_bot_username' => '', 'telegram_bot_token' => '', 'clear_telegram_bot_token' => false,
            'telegram_webhook_secret' => '', 'clear_telegram_webhook_secret' => false,

            'ai_provider' => 'null',
            'ai_fallback_provider' => null,
            'openai_api_key' => '', 'clear_openai_api_key' => false, 'openai_model' => '',
            'gemini_api_key' => '', 'clear_gemini_api_key' => false, 'gemini_model' => '',
            'openai_compatible_base_url' => '', 'openai_compatible_api_key' => '',
            'clear_openai_compatible_api_key' => false, 'openai_compatible_model' => '',
            'ai_timeout' => 30,
            'ai_monthly_cost_limit_usd' => 0,
            'ai_prompt_cost_per_mtok' => 0,
            'ai_completion_cost_per_mtok' => 0,
        ], $overrides);
    }

    /* ---------------------------------------------------- secret exposure */

    public function test_the_settings_page_never_carries_key_material_only_configured_booleans(): void
    {
        config([
            'services.ai.providers.openai.key' => self::FAKE_KEY,
            'services.ai.providers.openai.model' => 'test-model',
        ]);

        $response = $this->actingAs($this->admin())->get('/admin/settings')->assertOk();

        $serialized = json_encode($response->inertiaPage()['props'], JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString(self::FAKE_KEY, $serialized);

        $response->assertInertia(fn ($page) => $page
            ->where('integrations.ai.provider', 'null')
            ->has('integrations.ai.openai.key_configured')
            ->has('integrations.ai.gemini.key_configured')
            ->has('integrations.ai.openai_compatible.key_configured')
            ->has('integrations.ai.health.available')
            ->has('integrations.ai.health.providers'));
    }

    public function test_integration_and_test_endpoints_are_super_admin_only_even_with_the_permission(): void
    {
        // System Admin holds system.integrations.update — the permission
        // narrows, the rank decides, exactly as for the update endpoint.
        $systemAdmin = $this->operator(RoleKey::SystemAdmin);

        $this->actingAs($systemAdmin)
            ->put('/admin/settings/integrations', $this->payload())
            ->assertForbidden();

        $this->actingAs($systemAdmin)
            ->postJson('/admin/settings/integrations/ai-test', ['provider' => 'openai'])
            ->assertForbidden();

        // Without the permission the middleware answers first.
        $this->actingAs(User::factory()->projectEditor()->create())
            ->postJson('/admin/settings/integrations/ai-test', ['provider' => 'openai'])
            ->assertForbidden();
    }

    /* ------------------------------------------------- validation contract */

    public function test_a_selected_provider_must_be_whole_before_saving(): void
    {
        $path = $this->disposableEnvironment();

        try {
            $this->actingAs($this->admin())
                ->put('/admin/settings/integrations', $this->payload([
                    'ai_provider' => 'openai',
                ]))
                ->assertSessionHasErrors(['openai_model', 'openai_api_key']);

            $this->actingAs($this->admin())
                ->put('/admin/settings/integrations', $this->payload([
                    'ai_provider' => 'gemini',
                    'ai_fallback_provider' => 'gemini',
                ]))
                ->assertSessionHasErrors(['ai_fallback_provider']);

            $this->assertStringNotContainsString('AI_PROVIDER=openai', (string) file_get_contents($path));
        } finally {
            $this->cleanupEnvironment($path);
        }
    }

    public function test_saving_openai_with_gemini_fallback_writes_the_new_schema_and_never_the_secret_to_the_audit(): void
    {
        $path = $this->disposableEnvironment();

        try {
            $this->actingAs($this->admin())
                ->put('/admin/settings/integrations', $this->payload([
                    'ai_provider' => 'openai',
                    'ai_fallback_provider' => 'gemini',
                    'openai_api_key' => self::FAKE_KEY,
                    'openai_model' => 'test-openai-model',
                    'gemini_api_key' => 'fake-gemini-key-6789',
                    'gemini_model' => 'test-gemini-model',
                    'ai_monthly_cost_limit_usd' => 25,
                    'ai_prompt_cost_per_mtok' => 1.25,
                    'ai_completion_cost_per_mtok' => 5,
                ]))
                ->assertRedirect()
                ->assertSessionHasNoErrors();

            $written = (string) file_get_contents($path);
            $this->assertStringContainsString('AI_PROVIDER=openai', $written);
            $this->assertStringContainsString('AI_FALLBACK_PROVIDER=gemini', $written);
            $this->assertStringContainsString('OPENAI_MODEL=test-openai-model', $written);
            $this->assertStringContainsString('GEMINI_MODEL=test-gemini-model', $written);
            $this->assertStringContainsString('OPENAI_API_KEY=', $written);
            $this->assertStringContainsString('AI_PROMPT_COST_PER_MTOK=1.25', $written);
            // The retired fake setting is not written back.
            $this->assertStringNotContainsString('AI_FALLBACK_MODEL', $written);

            $entry = AuditLog::query()->where('action', 'system.integrations.updated')->firstOrFail();
            $serializedAudit = json_encode([$entry->changes, $entry->context], JSON_THROW_ON_ERROR);
            $this->assertStringContainsString('OPENAI_API_KEY', $serializedAudit);
            $this->assertStringNotContainsString(self::FAKE_KEY, $serializedAudit);
        } finally {
            $this->cleanupEnvironment($path);
        }
    }

    public function test_the_secret_contract_blank_keeps_typed_replaces_clear_removes(): void
    {
        $path = $this->disposableEnvironment();
        file_put_contents($path, "APP_NAME=Test\nOPENAI_API_KEY=".self::FAKE_KEY."\nOPENAI_MODEL=test-model\n");

        try {
            // Blank input: the stored key survives.
            $this->actingAs($this->admin())
                ->put('/admin/settings/integrations', $this->payload([
                    'ai_provider' => 'openai',
                    'openai_model' => 'test-model',
                ]))
                ->assertSessionHasNoErrors();
            $this->assertStringContainsString(self::FAKE_KEY, (string) file_get_contents($path));

            // Typed value: replaced.
            $this->actingAs($this->admin())
                ->put('/admin/settings/integrations', $this->payload([
                    'ai_provider' => 'openai',
                    'openai_model' => 'test-model',
                    'openai_api_key' => 'fake-replacement-key',
                ]))
                ->assertSessionHasNoErrors();
            $written = (string) file_get_contents($path);
            $this->assertStringNotContainsString(self::FAKE_KEY, $written);
            $this->assertStringContainsString('fake-replacement-key', $written);

            // Explicit clear: removed — and clearing the primary's key while
            // it stays selected is a validation error instead, so step back
            // to no provider first.
            $this->actingAs($this->admin())
                ->put('/admin/settings/integrations', $this->payload([
                    'ai_provider' => 'null',
                    'clear_openai_api_key' => true,
                ]))
                ->assertSessionHasNoErrors();
            $this->assertStringNotContainsString('fake-replacement-key', (string) file_get_contents($path));
        } finally {
            $this->cleanupEnvironment($path);
        }
    }

    /* ------------------------------------------------------ the connection test */

    public function test_the_connection_test_reports_categories_is_audited_and_rate_limited(): void
    {
        config([
            'services.ai.providers.openai.key' => 'fake-test-key',
            'services.ai.providers.openai.model' => 'test-model',
        ]);

        $admin = $this->admin();

        $ok = [
            'model' => 'test-model',
            'choices' => [['message' => ['content' => 'OK'], 'finish_reason' => 'stop']],
            'usage' => ['prompt_tokens' => 5, 'completion_tokens' => 1],
        ];

        // One stub, scripted per call: connect, refuse the credential, then
        // connect twice more for the rate-limit countdown.
        Http::fake(['api.openai.com/*' => Http::sequence()
            ->push($ok)
            ->push(['error' => 'bad key'], 401)
            ->push($ok)
            ->push($ok),
        ]);

        $this->actingAs($admin)
            ->postJson('/admin/settings/integrations/ai-test', ['provider' => 'openai'])
            ->assertOk()
            ->assertJsonPath('status', 'connected')
            ->assertJsonPath('provider', 'openai')
            ->assertHeader('Cache-Control', 'no-store, private');

        $this->assertTrue(
            AuditLog::query()->where('action', 'system.ai.connection_tested')->exists(),
        );

        // A credential failure comes back as its category token, not a body.
        Http::fake(['api.openai.com/*' => Http::response(['error' => 'bad key'], 401)]);
        $response = $this->actingAs($admin)
            ->postJson('/admin/settings/integrations/ai-test', ['provider' => 'openai'])
            ->assertOk()
            ->assertJsonPath('status', 'auth_failed');
        $this->assertStringNotContainsString('bad key', $response->getContent());

        // An unconfigured provider needs no HTTP at all.
        $this->actingAs($admin)
            ->postJson('/admin/settings/integrations/ai-test', ['provider' => 'gemini'])
            ->assertOk()
            ->assertJsonPath('status', 'not_configured');

        // Five tests per minute: the sixth is refused locally.
        $this->actingAs($admin)
            ->postJson('/admin/settings/integrations/ai-test', ['provider' => 'openai'])
            ->assertOk();
        $this->actingAs($admin)
            ->postJson('/admin/settings/integrations/ai-test', ['provider' => 'openai'])
            ->assertOk();
        $this->actingAs($admin)
            ->postJson('/admin/settings/integrations/ai-test', ['provider' => 'openai'])
            ->assertStatus(429)
            ->assertJsonPath('status', 'too_many_tests');
    }

    public function test_the_connection_test_rejects_unknown_providers(): void
    {
        $this->actingAs($this->admin())
            ->postJson('/admin/settings/integrations/ai-test', ['provider' => 'mini4'])
            ->assertStatus(422);
    }
}
