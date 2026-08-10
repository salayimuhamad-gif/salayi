<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\Identity\Enums\RoleKey;
use App\Modules\Identity\Models\Role;
use App\Modules\Identity\Models\User;
use App\Modules\Leads\Models\DemandProfile;
use App\Modules\Operations\Models\AuditLog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * The Admin Users Workspace: the follow-up surface over member accounts.
 *
 * Two contracts under test. The CRM half: every row explains what the person
 * is currently looking for, from the newest advisor request, and the filters
 * select against exactly what the row displays. The privacy half: the list,
 * and now the export sheet, carry phone AVAILABILITY only — the number's one
 * exit remains the per-target reveal ceremony, and a bulk sheet is precisely
 * the artefact that must never become a second exit.
 */
final class AdminUsersWorkspaceTest extends TestCase
{
    use RefreshDatabase;

    private const PASSWORD = 'Str0ng!Passw0rd#2026';

    /** Each member needs a distinct number — the registrar refuses reuse. */
    private static int $phoneSerial = 0;

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();

        if ((string) config('mulkihawler.security.blind_index_key', '') === '') {
            config([
                'mulkihawler.security.blind_index_key' => str_repeat('a', 64),
                'mulkihawler.security.pii_key' => str_repeat('b', 64),
            ]);
        }

        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true])]);
    }

    /** A real member, registered through the real form. */
    private function member(bool $consentContact = false, string $name = 'Member Person'): User
    {
        $phone = '07509'.str_pad((string) (100000 + self::$phoneSerial++), 6, '0', STR_PAD_LEFT);

        $this->post('/register', [
            'name' => $name,
            'phone' => $phone,
            'password' => self::PASSWORD,
            'password_confirmation' => self::PASSWORD,
            'locale' => 'ckb',
            'accept_terms' => true,
            'consent_contact' => $consentContact,
        ])->assertRedirect();
        $this->post('/logout');

        return User::query()->where('name', $name)->firstOrFail();
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

    private function request(
        User $user,
        string $objective = 'residence',
        string $propertyType = 'apartment',
        string $stage = 'new',
    ): DemandProfile {
        return DemandProfile::query()->create([
            'user_id' => $user->id,
            'preferred_locale' => 'ckb',
            'objective' => $objective,
            'property_type' => $propertyType,
            'stage' => $stage,
            'source' => 'advisor',
        ]);
    }

    /* ------------------------------------------------- the row payload */

    public function test_the_row_carries_the_latest_request_and_the_consent_bit(): void
    {
        $member = $this->member(consentContact: true);

        $this->travel(-2)->hours(fn () => $this->request($member, objective: 'residence', stage: 'contacted'));
        $this->request($member, objective: 'investment', propertyType: 'villa', stage: 'qualified');

        $this->actingAs($this->admin())->get('/admin/users')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('users.data.0.advisor_request_count', 2)
                // The NEWEST request, not the first: the row answers "what
                // are they looking for NOW".
                ->where('users.data.0.latest_request.objective', 'investment')
                ->where('users.data.0.latest_request.property_type', 'villa')
                ->where('users.data.0.latest_request.stage', 'qualified')
                ->where('users.data.0.contact_consent', true)
                ->where('can_view_phone', true)
                ->where('can_export', true));
    }

    public function test_a_member_without_requests_shows_none_and_no_invention(): void
    {
        $this->member();

        $this->actingAs($this->admin())->get('/admin/users')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('users.data.0.latest_request', null)
                ->where('users.data.0.advisor_request_count', 0)
                ->where('users.data.0.contact_consent', false));
    }

    /* -------------------------------------------------- request filters */

    public function test_the_has_request_filter_partitions_members(): void
    {
        $with = $this->member(name: 'Has Request');
        $this->request($with);
        $this->member(name: 'No Request');

        $this->actingAs($this->admin())->get('/admin/users?has_request=1')
            ->assertInertia(fn ($page) => $page
                ->where('users.data', fn ($rows) => count($rows) === 1
                    && $rows[0]['name'] === 'Has Request'));

        $this->actingAs($this->admin())->get('/admin/users?has_request=0')
            ->assertInertia(fn ($page) => $page
                ->where('users.data', fn ($rows) => count($rows) === 1
                    && $rows[0]['name'] === 'No Request'));
    }

    public function test_objective_type_and_stage_filter_against_the_latest_request(): void
    {
        $member = $this->member(name: 'Filter Target');
        // An OLD investment request superseded by a residence one: the
        // investment filter must NOT match, because the row shows residence.
        $this->travel(-3)->hours(fn () => $this->request($member, objective: 'investment', propertyType: 'villa', stage: 'won'));
        $this->request($member, objective: 'residence', propertyType: 'house', stage: 'viewing');

        $admin = $this->admin();

        $this->actingAs($admin)->get('/admin/users?objective=residence')
            ->assertInertia(fn ($page) => $page->where('users.data', fn ($rows) => count($rows) === 1));
        $this->actingAs($admin)->get('/admin/users?objective=investment')
            ->assertInertia(fn ($page) => $page->where('users.data', fn ($rows) => count($rows) === 0));

        $this->actingAs($admin)->get('/admin/users?property_type=house')
            ->assertInertia(fn ($page) => $page->where('users.data', fn ($rows) => count($rows) === 1));
        $this->actingAs($admin)->get('/admin/users?stage=viewing')
            ->assertInertia(fn ($page) => $page->where('users.data', fn ($rows) => count($rows) === 1));
        $this->actingAs($admin)->get('/admin/users?stage=won')
            ->assertInertia(fn ($page) => $page->where('users.data', fn ($rows) => count($rows) === 0));

        $this->actingAs($admin)->get('/admin/users?stage=not-a-stage')->assertSessionHasErrors('stage');
    }

    public function test_request_filters_compose_with_the_existing_ones(): void
    {
        $match = $this->member(name: 'Composed Match');
        $this->request($match, objective: 'investment');

        $other = $this->member(name: 'Wrong Locale');
        $this->request($other, objective: 'investment');
        $other->forceFill(['preferred_locale' => 'ar'])->save();

        $this->actingAs($this->admin())
            ->get('/admin/users?objective=investment&locale=ckb&status=active&sort=oldest')
            ->assertInertia(fn ($page) => $page
                ->where('users.data', fn ($rows) => count($rows) === 1
                    && $rows[0]['name'] === 'Composed Match'));
    }

    /* ------------------------------------------------------ the export */

    private function exportCsv(User $actor, string $query = ''): string
    {
        $response = $this->actingAs($actor)->get('/admin/users/export'.$query);
        $response->assertOk();
        $response->assertHeader('Content-Type', 'text/csv; charset=UTF-8');

        return $response->streamedContent();
    }

    public function test_the_export_requires_its_own_permission(): void
    {
        // Sales Manager holds identity.users.view — enough for the LIST,
        // deliberately not enough for the SHEET.
        $this->operator(RoleKey::SalesManager);

        $this->actingAs($this->operator(RoleKey::SalesManager))
            ->get('/admin/users/export')
            ->assertForbidden();

        // System Admin inherits the identity group, including export; the
        // Super Admin arrives through Gate::before as everywhere else.
        $this->exportCsv($this->operator(RoleKey::SystemAdmin));
        $this->exportCsv($this->admin());
    }

    public function test_the_export_is_audited_with_its_filters(): void
    {
        $this->member();

        $this->exportCsv($this->admin(), '?status=active&has_request=0');

        $entry = AuditLog::query()->where('action', 'identity.users.exported')->first();

        $this->assertNotNull($entry);
        $this->assertSame('active', $entry->context['filters']['status'] ?? null);
        $this->assertSame('0', $entry->context['filters']['has_request'] ?? null);
        $this->assertSame(1, $entry->context['rows'] ?? null);
    }

    public function test_the_export_honours_the_same_filters_as_the_list(): void
    {
        $keep = $this->member(name: 'Keep Row');
        $this->request($keep, objective: 'investment');
        $this->member(name: 'Drop Row');

        $csv = $this->exportCsv($this->admin(), '?objective=investment');

        $this->assertStringContainsString('Keep Row', $csv);
        $this->assertStringNotContainsString('Drop Row', $csv);
        $this->assertStringContainsString('investment', $csv);
    }

    public function test_the_export_is_utf8_with_bom_and_carries_kurdish_and_arabic_names(): void
    {
        $this->member(name: 'هاوکار عەلی');

        $csv = $this->exportCsv($this->admin());

        // The BOM is the difference between Excel showing Kurdish and Excel
        // showing mojibake.
        $this->assertStringStartsWith("\xEF\xBB\xBF", $csv);
        $this->assertStringContainsString('هاوکار عەلی', $csv);
        $this->assertStringContainsString("\r\n", $csv);
    }

    public function test_the_export_never_contains_phone_digits_or_security_material(): void
    {
        $consenting = $this->member(consentContact: true, name: 'Consenting Member');

        $csv = $this->exportCsv($this->admin());

        // Availability status only — with consent on file the sheet says the
        // reveal is PERMITTED, it does not perform it.
        $this->assertStringContainsString('available_reveal_permitted', $csv);

        foreach (['07501234567', '+9647501234567', '9647501234567', '1234567'] as $form) {
            $this->assertStringNotContainsString($form, $csv);
        }

        // Nothing encrypted, indexed, hashed or secret has any business in a
        // spreadsheet: the sheet is header + human-readable follow-up data.
        foreach (['phone_encrypted', 'phone_index', 'mfa', 'remember_token', 'password', 'telegram_id'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $csv);
        }

        $this->assertNotNull($consenting->phone_index, 'fixture must actually carry a phone');
    }

    public function test_the_export_reports_reveal_not_permitted_without_consent(): void
    {
        $this->member(consentContact: false);

        $csv = $this->exportCsv($this->admin());

        $this->assertStringContainsString('available_reveal_not_permitted', $csv);
        $this->assertStringNotContainsString('available_reveal_permitted,', $csv);
    }

    public function test_export_cells_are_defused_against_formula_injection(): void
    {
        // A display name is member-controlled text; a spreadsheet must treat
        // it as text however hostile it looks.
        $member = $this->member(name: 'Injection Case');
        $member->forceFill(['display_name' => '=HYPERLINK("http://evil.example","click")'])->save();

        $csv = $this->exportCsv($this->admin());

        $this->assertStringNotContainsString("\n=HYPERLINK", $csv);
        $this->assertStringContainsString('\'=HYPERLINK', $csv);
    }

    public function test_the_export_row_shape_is_the_documented_sheet(): void
    {
        $member = $this->member(consentContact: true);
        $this->request($member, objective: 'investment', propertyType: 'villa', stage: 'qualified');

        $csv = $this->exportCsv($this->admin());
        $lines = explode("\r\n", trim(substr($csv, 3)));

        $this->assertSame(
            'name,preferred_language,account_status,registered_at,last_login_at,last_seen_at,'
            .'online,telegram_linked,telegram_linked_at,phone_status,request_count,'
            .'latest_request_objective,latest_request_property_type,latest_request_stage,'
            .'latest_request_updated_at,portfolio_count',
            $lines[0],
        );

        $this->assertCount(2, $lines);
        $this->assertStringContainsString('villa', $lines[1]);
        $this->assertStringContainsString('qualified', $lines[1]);
    }
}
