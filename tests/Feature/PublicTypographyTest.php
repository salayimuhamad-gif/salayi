<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\Core\Contracts\SettingsRepository;
use App\Modules\Identity\Enums\RoleKey;
use App\Modules\Identity\Models\Role;
use App\Modules\Identity\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Owner typography controls (Wave 2B §7): stored through the existing
 * generic settings repository under the branding permissions, validated as
 * closed enums, and emitted into the public shell as CSS custom properties.
 * No schema change was needed — these tests pin that the whole surface
 * behaves like the rest of branding.
 */
final class PublicTypographyTest extends TestCase
{
    use RefreshDatabase;

    /** Every branding field update() requires, at its shipped defaults. */
    private const BASE_PAYLOAD = [
        'branding.site_name' => 'Mulkihawler',
        'branding.color_brand' => '15 62 89',
        'branding.color_accent' => '201 162 39',
    ];

    private function admin(): User
    {
        $user = User::factory()->create();

        $user->roles()->attach(Role::query()->firstOrCreate(
            ['key' => RoleKey::SystemAdmin->value],
            ['name' => RoleKey::SystemAdmin->value, 'is_system' => true],
        ));

        return $user;
    }

    public function test_an_ordinary_user_cannot_view_or_change_typography_settings(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->get('/admin/branding')->assertForbidden();

        $this->actingAs($user)
            ->put('/admin/branding', self::BASE_PAYLOAD + ['typography.public_scale' => '110'])
            ->assertForbidden();

        $this->assertNull(app(SettingsRepository::class)->get('typography.public_scale'));
    }

    public function test_a_guest_is_sent_to_login(): void
    {
        $this->put('/admin/branding', self::BASE_PAYLOAD)->assertRedirect('/login');
    }

    public function test_an_admin_changes_typography_and_it_persists(): void
    {
        $this->actingAs($this->admin())
            ->put('/admin/branding', self::BASE_PAYLOAD + [
                'typography.font_latin' => 'noto_sans',
                'typography.font_ckb' => 'kufi',
                'typography.font_ar' => 'kufi',
                'typography.public_scale' => '115',
            ])
            ->assertRedirect()
            ->assertSessionHas('success');

        $settings = app(SettingsRepository::class);

        $this->assertSame('noto_sans', $settings->get('typography.font_latin'));
        $this->assertSame('kufi', $settings->get('typography.font_ckb'));
        $this->assertSame('kufi', $settings->get('typography.font_ar'));
        $this->assertSame('115', $settings->get('typography.public_scale'));
    }

    public function test_values_outside_the_closed_enums_are_rejected(): void
    {
        $this->actingAs($this->admin())
            ->from('/admin/branding')
            ->put('/admin/branding', self::BASE_PAYLOAD + [
                'typography.font_latin' => 'comic_sans',
                'typography.public_scale' => '400',
            ])
            ->assertRedirect('/admin/branding')
            ->assertSessionHasErrors(['typography.font_latin', 'typography.public_scale']);

        $this->assertNull(app(SettingsRepository::class)->get('typography.font_latin'));
    }

    public function test_the_branding_page_serves_the_typography_group(): void
    {
        app(SettingsRepository::class)->set('typography.public_scale', '105');

        $this->actingAs($this->admin())
            ->get('/admin/branding')
            ->assertOk()
            // The group array is keyed by full setting keys (dots inside),
            // so it is matched as one value rather than a nested path.
            ->assertInertia(fn ($page) => $page
                ->where('typography', ['typography.public_scale' => '105']));
    }

    public function test_the_public_shell_emits_the_chosen_scale_and_stacks(): void
    {
        $settings = app(SettingsRepository::class);
        $settings->set('typography.public_scale', '120');
        $settings->set('typography.font_latin', 'system');

        $html = $this->get('/')->assertOk()->getContent();

        $this->assertStringContainsString('--mh-type-scale: 120;', $html);
        $this->assertStringContainsString('--mh-font-latin: ui-sans-serif, system-ui, sans-serif;', $html);
        // The Arabic-script stacks are untouched by a Latin change.
        $this->assertStringContainsString("--mh-font-ckb: 'K24', 'Noto Kufi Arabic'", $html);
    }

    public function test_defaults_emit_when_nothing_is_stored_and_bad_values_fall_back(): void
    {
        $html = $this->get('/')->assertOk()->getContent();

        $this->assertStringContainsString('--mh-type-scale: 100;', $html);
        $this->assertStringContainsString("--mh-font-latin: 'Outfit', 'Noto Sans'", $html);

        // A value that slipped past validation (edited directly in the DB)
        // must still resolve to the safe default at render time.
        app(SettingsRepository::class)->set('typography.public_scale', '999');

        $this->assertStringContainsString(
            '--mh-type-scale: 100;',
            $this->get('/')->assertOk()->getContent(),
        );
    }

    public function test_reset_to_defaults_round_trips(): void
    {
        $settings = app(SettingsRepository::class);
        $settings->set('typography.public_scale', '90');
        $settings->set('typography.font_latin', 'system');

        // The admin reset action submits the default values like any save.
        $this->actingAs($this->admin())
            ->put('/admin/branding', self::BASE_PAYLOAD + [
                'typography.font_latin' => 'outfit',
                'typography.font_ckb' => 'k24',
                'typography.font_ar' => 'noto_sans_arabic',
                'typography.public_scale' => '100',
            ])
            ->assertRedirect();

        $this->assertSame('100', $settings->get('typography.public_scale'));
        $this->assertSame('outfit', $settings->get('typography.font_latin'));

        $this->assertStringContainsString(
            '--mh-type-scale: 100;',
            $this->get('/')->assertOk()->getContent(),
        );
    }
}
