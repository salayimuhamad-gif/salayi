<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\Identity\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Route;
use Illuminate\Testing\TestResponse;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;
use Tests\TestCase;

/**
 * The production error responder, from both sides of the counter (Phase 16).
 *
 * bootstrap/app.php replaces exception responses for a fixed status list
 * with the generic Inertia error page — in production only, for BROWSERS
 * only. Before this phase it replaced JSON responses too: an API caller's
 * 429 lost its localized limiter message, its Retry-After and its
 * X-RateLimit-* headers, and every JSON 404/419/500 turned into HTML the
 * caller could not parse. The contracts pinned here: JSON callers keep the
 * framework's own — already production-sanitized — rendering, headers
 * intact; browser callers keep the exact generic page; the advisor's
 * throttled Inertia flow keeps its 302-with-flash shape; and none of it
 * leaks a word of exception detail.
 *
 * Production is entered the way the responder itself reads it: the
 * container's 'env' binding, flipped per test. Two kernel realities shape
 * the assertions — the testing CSRF bypass keys off env === 'testing', so
 * POSTs here carry a real csrf_token(); and the Inertia page render omits
 * an explicit content-type in the test kernel, so HTML is asserted by body
 * shape, not header.
 */
final class ProductionErrorShapeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
    }

    /** Enter production exactly as the responder reads it. */
    private function production(): void
    {
        $this->app['env'] = 'production';
        config(['app.debug' => false]);
    }

    private function member(): User
    {
        $user = User::factory()->create();
        $user->forceFill(['telegram_verified_at' => now()])->save();

        return $user;
    }

    /** @param TestResponse<Response> $response */
    private function assertIsGenericHtmlPage(TestResponse $response): void
    {
        $this->assertStringStartsWith('<!DOCTYPE html>', $response->getContent(), 'expected the generic error page');
    }

    /* ------------------------------------------------------ JSON callers */

    public function test_production_json_404_stays_json(): void
    {
        $this->production();

        $response = $this->getJson('/definitely-not-a-route');

        $response->assertStatus(404);
        $this->assertStringContainsString('application/json', (string) $response->headers->get('content-type'));
        $this->assertIsArray(json_decode($response->getContent(), true));
    }

    public function test_production_json_419_stays_json(): void
    {
        $this->production();

        // No CSRF token on purpose: the refusal must still refuse — only
        // its SHAPE is at stake for a JSON caller.
        $response = $this->postJson('/advisor/reply', ['message' => 'x']);

        $response->assertStatus(419);
        $this->assertStringContainsString('application/json', (string) $response->headers->get('content-type'));
        $response->assertJsonPath('message', 'CSRF token mismatch.');
    }

    public function test_production_json_429_keeps_the_limiter_body_and_headers(): void
    {
        $this->production();
        $this->setFeatures(['advisor.residential' => true]);

        $member = $this->member();
        $this->actingAs($member)->get('/advisor')->assertOk();
        $headers = ['X-CSRF-TOKEN' => csrf_token()];

        foreach (range(1, 10) as $i) {
            $this->actingAs($member)
                ->postJson('/advisor/reply', ['message' => 'بەڵێ', 'locale' => 'ckb'], $headers)
                ->assertOk();
        }

        $blocked = $this->actingAs($member)
            ->postJson('/advisor/reply', ['message' => 'بەڵێ', 'locale' => 'ckb'], $headers);

        // The Phase 14 contract, now honoured in production too: localized
        // refusal, real Retry-After.
        $blocked->assertStatus(429);
        $blocked->assertJsonPath('message', __('advisor.chat.rate_limited', [], 'ckb'));
        $this->assertNotNull($blocked->headers->get('Retry-After'), 'Retry-After must survive production');
    }

    public function test_production_json_429_on_a_framework_limiter_keeps_rate_headers(): void
    {
        $this->production();

        $this->get('/login')->assertOk();
        $headers = ['X-CSRF-TOKEN' => csrf_token()];

        foreach (range(1, 5) as $i) {
            $this->postJson('/login', ['login' => 'p16@example.com', 'password' => 'wrong'], $headers)
                ->assertStatus(422);
        }

        $blocked = $this->postJson('/login', ['login' => 'p16@example.com', 'password' => 'wrong'], $headers);

        $blocked->assertStatus(429);
        $this->assertStringContainsString('application/json', (string) $blocked->headers->get('content-type'));
        $this->assertNotNull($blocked->headers->get('Retry-After'));
        $this->assertNotNull($blocked->headers->get('X-RateLimit-Limit'));
        $this->assertNotNull($blocked->headers->get('X-RateLimit-Remaining'));
    }

    public function test_production_json_500_is_sanitized_json(): void
    {
        Route::middleware('web')->get('/p16-boom', function (): void {
            throw new RuntimeException('SECRET-INTERNAL-DETAIL');
        });
        $this->production();

        $response = $this->getJson('/p16-boom');

        $response->assertStatus(500);
        $this->assertStringContainsString('application/json', (string) $response->headers->get('content-type'));
        // The framework's debug-off rendering: a fixed phrase, zero detail.
        $this->assertSame('Server Error', json_decode($response->getContent(), true)['message'] ?? null);
        $this->assertStringNotContainsString('SECRET-INTERNAL-DETAIL', $response->getContent());
    }

    /* --------------------------------------------------- browser callers */

    public function test_production_browser_404_still_renders_the_generic_page(): void
    {
        $this->production();

        $response = $this->get('/definitely-not-a-route');

        $response->assertStatus(404);
        $this->assertIsGenericHtmlPage($response);
    }

    public function test_production_browser_419_and_500_stay_generic_and_leak_free(): void
    {
        Route::middleware('web')->get('/p16-boom', function (): void {
            throw new RuntimeException('SECRET-INTERNAL-DETAIL');
        });
        $this->production();

        // 419: a token-less browser POST.
        $csrf = $this->post('/advisor/reply', ['message' => 'x']);
        $csrf->assertStatus(419);
        $this->assertIsGenericHtmlPage($csrf);

        // 500: the generic page, with not a word of the exception on it.
        $boom = $this->get('/p16-boom');
        $boom->assertStatus(500);
        $this->assertIsGenericHtmlPage($boom);
        $this->assertStringNotContainsString('SECRET-INTERNAL-DETAIL', $boom->getContent());
    }

    public function test_production_advisor_browser_throttle_flow_is_unchanged(): void
    {
        $this->production();
        $this->setFeatures(['advisor.residential' => true]);

        $member = $this->member();
        $this->actingAs($member)->get('/advisor')->assertOk();
        $token = csrf_token();

        foreach (range(1, 10) as $i) {
            $this->actingAs($member)
                ->post('/advisor/reply', ['message' => 'بەڵێ', 'locale' => 'ckb', '_token' => $token])
                ->assertStatus(302);
        }

        // The eleventh: still the 302-with-flash shape the chat renders —
        // never a 429 page, never a JSON body.
        $blocked = $this->actingAs($member)
            ->post('/advisor/reply', ['message' => 'بەڵێ', 'locale' => 'ckb', '_token' => $token]);

        $blocked->assertStatus(302);
        $blocked->assertSessionHasErrors(['message' => __('advisor.chat.rate_limited', [], 'ckb')]);
    }

    /* ------------------------------------------------------- inertness */

    public function test_the_responder_is_inert_outside_production(): void
    {
        // Same request as the JSON-404 test, under the testing env: the
        // framework's JSON rendering, untouched by the hook.
        $response = $this->getJson('/definitely-not-a-route');

        $response->assertStatus(404);
        $this->assertStringContainsString('application/json', (string) $response->headers->get('content-type'));
    }
}
