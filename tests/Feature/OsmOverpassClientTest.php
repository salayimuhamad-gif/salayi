<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\Geography\Services\Osm\OsmPlaceMapper;
use App\Modules\Geography\Services\Osm\OverpassClient;
use App\Modules\Geography\Services\Osm\OverpassUnavailable;
use App\Modules\Geography\ValueObjects\BoundingBox;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * The Overpass transport seam (Map Phase 2).
 *
 * CI never talks to the real service — every response here is faked — and
 * the contracts pinned are exactly the politeness rules the client exists
 * for: one identified, bounded request per group; successful answers cached
 * so repeats cost nothing; failures surfaced as typed reasons and NEVER
 * cached; 429's Retry-After honored rather than slept through; metadata
 * (editor usernames, changesets) stripped before anything is stored.
 */
final class OsmOverpassClientTest extends TestCase
{
    private function client(): OverpassClient
    {
        return app(OverpassClient::class);
    }

    private function box(): BoundingBox
    {
        return BoundingBox::operatingArea();
    }

    /** @return array<string, mixed> */
    private function goodBody(): array
    {
        return ['elements' => [
            [
                'type' => 'node', 'id' => 101, 'lat' => 36.19, 'lon' => 44.01,
                'tags' => ['amenity' => 'school', 'name' => 'Test School'],
                // Editor metadata that must never survive the prune.
                'user' => 'mapper-jane', 'uid' => 77, 'changeset' => 123456, 'version' => 4,
            ],
            [
                'type' => 'way', 'id' => 202, 'center' => ['lat' => 36.20, 'lon' => 44.02],
                'tags' => ['amenity' => 'school', 'name' => 'Way School'],
            ],
            [
                'type' => 'relation', 'id' => 303, 'center' => ['lat' => 36.21, 'lon' => 44.03],
                'tags' => ['amenity' => 'university', 'name' => 'Relation Campus'],
            ],
            // No coordinates at all -> dropped by the prune.
            ['type' => 'way', 'id' => 404, 'tags' => ['amenity' => 'school']],
        ]];
    }

    public function test_success_prunes_metadata_and_resolves_centres(): void
    {
        Http::fake(['overpass-api.de/*' => Http::response($this->goodBody())]);

        $result = $this->client()->fetch('education', OsmPlaceMapper::selectorsFor('education'), $this->box());

        $this->assertFalse($result['from_cache']);
        $this->assertFalse($result['truncated']);
        $this->assertCount(3, $result['elements']);

        // Exactly the mapper's diet — nothing else survives the prune.
        foreach ($result['elements'] as $element) {
            $this->assertSame(['type', 'id', 'lat', 'lon', 'tags'], array_keys($element));
        }

        // Way and relation centres became plain coordinates.
        $this->assertSame(44.02, $result['elements'][1]['lon']);
        $this->assertSame(36.21, $result['elements'][2]['lat']);
    }

    public function test_the_query_is_bounded_batched_and_identified(): void
    {
        Http::fake(['overpass-api.de/*' => Http::response(['elements' => []])]);

        $this->client()->fetch('education', OsmPlaceMapper::selectorsFor('education'), $this->box());

        Http::assertSent(function ($request): bool {
            $query = (string) $request['data'];

            return str_contains($query, '[out:json]')
                && str_contains($query, '[timeout:')
                && str_contains($query, 'out center;')
                // Overpass bbox order (south,west,north,east), Erbil's box.
                && str_contains($query, '(35.9000,43.7000,36.5000,44.4000)')
                // Every selector fans out over all three object types.
                && str_contains($query, 'node["amenity"="school"]')
                && str_contains($query, 'way["amenity"="school"]')
                && str_contains($query, 'relation["amenity"="school"]')
                && str_contains($query, 'node["amenity"="university"]')
                && str_contains((string) $request->header('User-Agent')[0], 'Mulkihawler');
        });
    }

    public function test_a_successful_answer_is_cached_for_repeats(): void
    {
        Http::fake(['overpass-api.de/*' => Http::response($this->goodBody())]);

        $first = $this->client()->fetch('education', OsmPlaceMapper::selectorsFor('education'), $this->box());
        $second = $this->client()->fetch('education', OsmPlaceMapper::selectorsFor('education'), $this->box());

        Http::assertSentCount(1);
        $this->assertFalse($first['from_cache']);
        $this->assertTrue($second['from_cache']);
        $this->assertSame($first['elements'], $second['elements']);
    }

    public function test_different_groups_cache_independently(): void
    {
        Http::fake(['overpass-api.de/*' => Http::response(['elements' => []])]);

        $client = $this->client();
        $client->fetch('education', OsmPlaceMapper::selectorsFor('education'), $this->box());
        $client->fetch('health', OsmPlaceMapper::selectorsFor('health'), $this->box());
        $client->fetch('health', OsmPlaceMapper::selectorsFor('health'), $this->box());

        Http::assertSentCount(2);

        $this->assertNotSame(
            $client->cacheKey('https://overpass-api.de/api/interpreter', 'education', $this->box()),
            $client->cacheKey('https://overpass-api.de/api/interpreter', 'health', $this->box()),
        );
    }

    public function test_a_timeout_is_typed_and_not_cached(): void
    {
        Http::fake(function (): never {
            throw new ConnectionException('cURL error 28: operation timed out');
        });

        try {
            $this->client()->fetch('education', OsmPlaceMapper::selectorsFor('education'), $this->box());
            $this->fail('Expected OverpassUnavailable');
        } catch (OverpassUnavailable $exception) {
            $this->assertSame(OverpassUnavailable::TIMEOUT, $exception->reason);
        }
    }

    public function test_a_failure_is_never_cached_so_recovery_is_immediate(): void
    {
        Http::fake(['overpass-api.de/*' => Http::sequence()
            ->pushStatus(500)
            ->push($this->goodBody())]);

        try {
            $this->client()->fetch('education', OsmPlaceMapper::selectorsFor('education'), $this->box());
            $this->fail('Expected OverpassUnavailable');
        } catch (OverpassUnavailable $exception) {
            $this->assertSame(OverpassUnavailable::SERVER_ERROR, $exception->reason);
        }

        // The service recovered; the client must see that now, not tomorrow.
        $result = $this->client()->fetch('education', OsmPlaceMapper::selectorsFor('education'), $this->box());

        $this->assertCount(3, $result['elements']);
        Http::assertSentCount(2);
    }

    public function test_rate_limiting_honors_retry_after(): void
    {
        Http::fake(['overpass-api.de/*' => Http::response('', 429, ['Retry-After' => '120'])]);

        try {
            $this->client()->fetch('education', OsmPlaceMapper::selectorsFor('education'), $this->box());
            $this->fail('Expected OverpassUnavailable');
        } catch (OverpassUnavailable $exception) {
            $this->assertSame(OverpassUnavailable::RATE_LIMITED, $exception->reason);
            $this->assertSame(120, $exception->retryAfterSeconds);
        }

        // Exactly one request: no retry storm against a service that just
        // asked us to slow down.
        Http::assertSentCount(1);
    }

    public function test_rate_limiting_without_a_header_is_conservative(): void
    {
        Http::fake(['overpass-api.de/*' => Http::response('', 429)]);

        try {
            $this->client()->fetch('education', OsmPlaceMapper::selectorsFor('education'), $this->box());
            $this->fail('Expected OverpassUnavailable');
        } catch (OverpassUnavailable $exception) {
            $this->assertSame(60, $exception->retryAfterSeconds);
        }
    }

    public function test_a_malformed_answer_is_typed(): void
    {
        Http::fake(['overpass-api.de/*' => Http::response(['remark' => 'runtime error'], 200)]);

        try {
            $this->client()->fetch('education', OsmPlaceMapper::selectorsFor('education'), $this->box());
            $this->fail('Expected OverpassUnavailable');
        } catch (OverpassUnavailable $exception) {
            $this->assertSame(OverpassUnavailable::MALFORMED, $exception->reason);
        }
    }

    public function test_an_empty_selector_list_never_touches_the_network(): void
    {
        Http::fake();

        $result = $this->client()->fetch('education', [], $this->box());

        $this->assertSame([], $result['elements']);
        Http::assertNothingSent();
    }
}
