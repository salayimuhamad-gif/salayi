<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\Identity\Enums\RoleKey;
use App\Modules\Identity\Models\Role;
use App\Modules\Identity\Models\User;
use App\Modules\Operations\Models\AuditLog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The system settings surface: authorised by the declared permissions,
 * persisted for real, validated, audited — and never a source of secrets.
 */
final class SystemSettingsTest extends TestCase
{
    use RefreshDatabase;

    private function operator(RoleKey $key): User
    {
        $user = User::factory()->create();

        $user->roles()->attach(Role::query()->firstOrCreate(
            ['key' => $key->value],
            ['name' => $key->value, 'is_system' => true],
        ));

        return $user;
    }

    public function test_the_page_requires_the_settings_view_permission(): void
    {
        $this->actingAs(User::factory()->projectEditor()->create())
            ->get('/admin/settings')
            ->assertForbidden();

        $this->actingAs($this->operator(RoleKey::SystemAdmin))
            ->get('/admin/settings')
            ->assertOk();
    }

    public function test_integration_credentials_are_super_admin_only_and_absent_for_others(): void
    {
        // A System Admin holds system.integrations.update in the registry,
        // and STILL does not see or edit the credential panel: integrations
        // carry live secrets, and that panel is Super Admin's alone.
        $this->actingAs($this->operator(RoleKey::SystemAdmin))
            ->get('/admin/settings')
            ->assertInertia(fn ($page) => $page
                ->where('integrations', null)
                ->where('can_manage_integrations', false));

        $this->actingAs($this->operator(RoleKey::SystemAdmin))
            ->put('/admin/settings/integrations', [])
            ->assertForbidden();
    }

    public function test_general_settings_validate_and_persist_and_audit(): void
    {
        $admin = User::factory()->superAdmin()->create();

        // An unknown timezone and a default locale outside the enabled set
        // are both refused before anything touches the environment.
        $this->actingAs($admin)
            ->put('/admin/settings/general', [
                'app_name' => 'MyHawler',
                'app_url' => 'https://myhawler.test',
                'timezone' => 'Mars/Olympus',
                'default_locale' => 'ckb',
                'enabled_locales' => ['ckb'],
                'queue_connection' => 'database',
            ])
            ->assertSessionHasErrors('timezone');

        $this->actingAs($admin)
            ->put('/admin/settings/general', [
                'app_name' => 'MyHawler',
                'app_url' => 'https://myhawler.test',
                'timezone' => 'Asia/Baghdad',
                'default_locale' => 'ar',
                'enabled_locales' => ['ckb', 'en'],
                'queue_connection' => 'database',
            ])
            ->assertSessionHasErrors('enabled_locales');

        /*
         * Persistence writes the real environment file, so the test provides
         * a disposable one at the real path and removes it whatever happens —
         * a leftover .env fails the secret-scan gate by design.
         */
        $path = base_path('.env');
        $this->assertFileDoesNotExist($path, 'refusing to overwrite a real .env');

        try {
            file_put_contents($path, "APP_NAME=Old\n");

            $this->actingAs($admin)
                ->put('/admin/settings/general', [
                    'app_name' => 'مولکی هەولێر',
                    'app_url' => 'https://myhawler.test/',
                    'timezone' => 'Asia/Baghdad',
                    'default_locale' => 'ckb',
                    'enabled_locales' => ['ckb', 'ar', 'en'],
                    'queue_connection' => 'database',
                ])
                ->assertRedirect()
                ->assertSessionHasNoErrors();

            $written = (string) file_get_contents($path);

            $this->assertStringContainsString('مولکی هەولێر', $written);
            // The trailing slash is normalised away before persisting.
            $this->assertStringContainsString('APP_URL=https://myhawler.test', $written);
            $this->assertStringNotContainsString('APP_URL=https://myhawler.test/', $written);
            $this->assertStringContainsString('APP_ENABLED_LOCALES=ckb,ar,en', $written);

            $this->assertTrue(
                AuditLog::query()->where('action', 'system.settings.updated')->exists(),
            );
        } finally {
            @unlink($path);
            @unlink($path.'.system-settings.lock');

            // The service snapshots the file it edits; the snapshots of a
            // disposable test .env are as disposable as the file itself.
            foreach (glob(storage_path('app/private/system-settings/env-backups/*')) ?: [] as $backup) {
                @unlink($backup);
            }
        }
    }

    public function test_updating_general_settings_requires_the_update_permission(): void
    {
        // Security Auditor reads everything and writes nothing — it holds
        // system.settings.view and must be refused by the update route.
        $this->actingAs($this->operator(RoleKey::SecurityAuditor))
            ->put('/admin/settings/general', [
                'app_name' => 'X',
                'app_url' => 'https://x.test',
                'timezone' => 'Asia/Baghdad',
                'default_locale' => 'ckb',
                'enabled_locales' => ['ckb'],
                'queue_connection' => 'database',
            ])
            ->assertForbidden();
    }
}
