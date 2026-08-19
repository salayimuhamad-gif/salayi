<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\Identity\Http\Middleware\EnsureMfaConfirmed;
use App\Modules\Identity\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Direct coverage for the administrative MFA gate.
 *
 * The suite previously had NONE. Every admin test was silently bounced to
 * /admin/mfa/setup or /admin/mfa/challenge and asserted against a redirect it
 * had caused itself, so the guard was simultaneously untested and the reason
 * most of the suite failed. These tests exercise it deliberately, from both
 * sides, so the central test helper can never quietly disable it.
 */
final class MfaGuardTest extends TestCase
{
    use RefreshDatabase;

    private const PROTECTED_ROUTE = '/admin/projects/wizard';

    /** 1. Enrolled? No. Then enrolment is the only thing on offer. */
    public function test_an_administrator_without_enrolment_is_sent_to_setup(): void
    {
        $user = User::factory()->superAdmin()->withoutMfa()->create();

        $this->actingAs($user)->get(self::PROTECTED_ROUTE)
            ->assertRedirect(route('admin.mfa.setup'));
    }

    /** 2. Enrolled but this session has not passed the challenge. */
    public function test_an_enrolled_administrator_without_session_confirmation_is_challenged(): void
    {
        $user = User::factory()->superAdmin()->create();

        // Sign in WITHOUT the helper's confirmation step.
        $this->be($user);

        $this->get(self::PROTECTED_ROUTE)->assertRedirect(route('admin.mfa.challenge'));
    }

    /** 3. Confirmed in this session: the protected route opens. */
    public function test_an_administrator_confirmed_in_this_session_is_admitted(): void
    {
        $user = User::factory()->superAdmin()->create();

        $this->actingAs($user)->get(self::PROTECTED_ROUTE)->assertRedirect();

        // A draft was created, which only happens past the gate.
        $this->assertDatabaseCount('project_drafts', 1);
    }

    /**
     * 4. Confirmation belonging to a DIFFERENT session does not carry.
     *
     * The key stores the session id it was issued for, so a value copied from
     * elsewhere — or replayed after the session changed — proves nothing.
     */
    public function test_confirmation_from_another_session_does_not_pass(): void
    {
        $user = User::factory()->superAdmin()->create();

        $this->be($user);
        $this->withSession([EnsureMfaConfirmed::SESSION_KEY => 'some-other-session-id']);

        $this->get(self::PROTECTED_ROUTE)->assertRedirect(route('admin.mfa.challenge'));
    }

    /**
     * 5. A NEW session does not inherit an old session's confirmation.
     *
     * The stored value is the session id it was issued for, so rotation —
     * whether by regeneration, expiry or simply a different browser — leaves
     * the new session unconfirmed. Modelled by presenting a different session
     * cookie, which is exactly what the next request carries after rotation.
     */
    public function test_a_rotated_session_does_not_inherit_confirmation(): void
    {
        $user = User::factory()->superAdmin()->create();

        $this->actingAs($user)->get(self::PROTECTED_ROUTE)->assertRedirect();

        $session = $this->app['session']->driver();
        $rotated = 'rotatedsession'.str_repeat('a', 26);

        $this->be($user);
        $this->withCookie($session->getName(), $rotated);
        $this->flushSession();

        $this->get(self::PROTECTED_ROUTE)->assertRedirect(route('admin.mfa.challenge'));
    }

    /** 6. HTML continuity: the same session serves several requests. */
    public function test_html_requests_keep_the_confirmed_session(): void
    {
        $user = User::factory()->superAdmin()->create();

        $this->actingAs($user);

        $this->get(self::PROTECTED_ROUTE)->assertRedirect();
        $this->get(self::PROTECTED_ROUTE)->assertRedirect();

        // Resumed rather than restarted: still exactly one draft.
        $this->assertDatabaseCount('project_drafts', 1);
    }

    /**
     * 7. JSON continuity, which needs credentials.
     *
     * Laravel omits cookies from `getJson()` unless `withCredentials()` is set,
     * so every XHR endpoint was previously bounced to the challenge. A browser
     * sends the session cookie on same-origin fetches; the helper matches that.
     */
    public function test_json_requests_keep_the_confirmed_session(): void
    {
        $user = User::factory()->superAdmin()->create();

        $this->actingAs($user)
            ->getJson('/admin/projects/wizard/nearby?latitude=36.19&longitude=44.009')
            ->assertSuccessful()
            ->assertJsonPath('distance.unit', 'km');
    }

    /** 8. The default administrator fixture is enrolled and deterministic. */
    public function test_the_default_administrator_factory_state_is_enrolled(): void
    {
        $user = User::factory()->superAdmin()->create();

        $this->assertNotNull($user->mfa_secret, 'The default admin must be enrolled.');
        $this->assertNotNull($user->mfa_confirmed_at);
        $this->assertTrue($user->hasMfaEnabled());
        $this->assertTrue($user->requiresMfa());
    }

    /** 9. The negative state remains available and is genuinely unenrolled. */
    public function test_the_without_mfa_factory_state_is_not_enrolled(): void
    {
        $user = User::factory()->superAdmin()->withoutMfa()->create();

        $this->assertNull($user->mfa_secret);
        $this->assertNull($user->mfa_confirmed_at);
        $this->assertFalse($user->hasMfaEnabled());
        $this->assertTrue($user->requiresMfa(), 'An administrator still REQUIRES mfa; they simply lack it.');
    }

    /** A non-administrative account is not subject to the gate at all. */
    public function test_a_non_administrative_account_is_not_challenged(): void
    {
        $user = User::factory()->withoutMfa()->create();

        $this->assertFalse($user->requiresMfa());
    }
}
