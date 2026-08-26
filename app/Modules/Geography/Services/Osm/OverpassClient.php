<?php

declare(strict_types=1);

namespace App\Modules\Geography\Services\Osm;

use App\Modules\Geography\ValueObjects\BoundingBox;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * The one seam between this platform and the Overpass API (Map Phase 2).
 *
 * Server-side only, admin-triggered only: a public visitor's request must
 * never wait on — or even reach — an external community service. The durable
 * public POI source is the `places` table; this client exists solely to fill
 * the admin's import preview, and everything about it is shaped by Overpass
 * being a shared, keyless, volunteer-run resource:
 *
 *   - one bounded request per category GROUP (education, health, …), never
 *     per user, per pan, or per category button;
 *   - the successful response is cached for 24 hours keyed on
 *     endpoint + bbox + group + query version, so repeated previews, the
 *     confirm step and idempotent re-runs cost zero external calls;
 *   - failures are never cached and never retried here — a 429 surfaces its
 *     Retry-After to the administrator instead of a worker sleeping through
 *     it, and no alternate public endpoints are rotated through to dodge a
 *     rate limit;
 *   - the User-Agent identifies the product, per the service's usage policy.
 *
 * The mapper owns WHAT is asked for (the tag whitelist); this class owns HOW
 * it is asked (transport, caching, failure taxonomy).
 */
final class OverpassClient
{
    /**
     * Part of every cache key. Bump when the query SHAPE changes (new
     * selectors, different out mode), so stale cached responses cannot
     * masquerade as answers to the new question.
     */
    public const QUERY_VERSION = 1;

    private const CACHE_TTL_HOURS = 24;

    /**
     * Defensive ceiling on elements kept from one group response. Erbil-scale
     * categories are hundreds of objects; anything past this is either a
     * mis-scoped query or an upstream anomaly, and silently caching megabytes
     * into a database cache row helps nobody. The truncation is reported.
     */
    private const MAX_ELEMENTS = 8000;

    /**
     * Fetch one category group's raw OSM objects inside a bounding box.
     *
     * @param  list<string>  $selectors  tag selector fragments, e.g. '["amenity"="school"]'
     * @return array{elements: list<array<string, mixed>>, truncated: bool, from_cache: bool}
     *
     * @throws OverpassUnavailable
     */
    public function fetch(string $group, array $selectors, BoundingBox $box): array
    {
        if ($selectors === []) {
            return ['elements' => [], 'truncated' => false, 'from_cache' => false];
        }

        $endpoint = $this->endpoint();
        $key = $this->cacheKey($endpoint, $group, $box);

        /** @var array{elements: list<array<string, mixed>>, truncated: bool}|null $cached */
        $cached = Cache::get($key);

        if (is_array($cached)) {
            return [...$cached, 'from_cache' => true];
        }

        $payload = $this->request($endpoint, $this->query($selectors, $box));

        // Only SUCCESS is cached. A cached failure would keep telling the
        // administrator the service is down for a day after it recovered.
        Cache::put($key, $payload, now()->addHours(self::CACHE_TTL_HOURS));

        return [...$payload, 'from_cache' => false];
    }

    /** The canonical cache key for one (endpoint, bbox, group) question. */
    public function cacheKey(string $endpoint, string $group, BoundingBox $box): string
    {
        return 'overpass:v'.self::QUERY_VERSION.':'.sha1(implode('|', [
            $endpoint,
            $this->bboxString($box),
            $group,
        ]));
    }

    private function endpoint(): string
    {
        return rtrim((string) config('services.overpass.endpoint', 'https://overpass-api.de/api/interpreter'), '/');
    }

    /**
     * One union query over node/way/relation for every selector, JSON out,
     * `out center` so ways and relations arrive with a representative point.
     */
    private function query(array $selectors, BoundingBox $box): string
    {
        $bbox = $this->bboxString($box);

        $lines = [];

        foreach ($selectors as $selector) {
            foreach (['node', 'way', 'relation'] as $type) {
                $lines[] = sprintf('  %s%s(%s);', $type, $selector, $bbox);
            }
        }

        // The server-side [timeout:] stays under our own HTTP timeout so
        // Overpass gives up before the socket does and we read its answer.
        $serverTimeout = max(10, $this->timeoutSeconds() - 5);

        return sprintf(
            "[out:json][timeout:%d];\n(\n%s\n);\nout center;",
            $serverTimeout,
            implode("\n", $lines),
        );
    }

    /**
     * Overpass bbox order is (south,west,north,east). Rounded to 4 decimals
     * (~11 m) so the query text and the cache key describe the same box and a
     * float-noise difference cannot fragment the cache.
     */
    private function bboxString(BoundingBox $box): string
    {
        return sprintf(
            '%.4F,%.4F,%.4F,%.4F',
            $box->minLatitude,
            $box->minLongitude,
            $box->maxLatitude,
            $box->maxLongitude,
        );
    }

    private function timeoutSeconds(): int
    {
        return max(10, (int) config('services.overpass.timeout', 30));
    }

    /**
     * @return array{elements: list<array<string, mixed>>, truncated: bool}
     *
     * @throws OverpassUnavailable
     */
    private function request(string $endpoint, string $query): array
    {
        try {
            $response = Http::connectTimeout(max(2, (int) config('services.overpass.connect_timeout', 5)))
                ->timeout($this->timeoutSeconds())
                ->withHeaders([
                    // Identify ourselves honestly; the Overpass usage policy
                    // asks exactly this of automated clients.
                    'User-Agent' => 'Mulkihawler/4.0 (+'.(string) config('app.url', 'https://myhawler.com').'; admin OSM place import)',
                    'Accept' => 'application/json',
                ])
                ->asForm()
                ->post($endpoint, ['data' => $query]);
        } catch (ConnectionException) {
            // Connect/read timeouts and DNS failures land here. Logged by
            // category only — the query text is boring, but log noise is not.
            Log::warning('overpass.unreachable');

            throw new OverpassUnavailable(OverpassUnavailable::TIMEOUT);
        } catch (Throwable) {
            Log::warning('overpass.unreachable');

            throw new OverpassUnavailable(OverpassUnavailable::UNREACHABLE);
        }

        if ($response->status() === 429) {
            $retryAfter = $response->header('Retry-After');
            // Honor the server's own figure when it gives one; otherwise be
            // conservative rather than optimistic.
            $seconds = is_numeric($retryAfter) ? max(1, (int) $retryAfter) : 60;

            Log::warning('overpass.rate_limited', ['retry_after' => $seconds]);

            throw new OverpassUnavailable(OverpassUnavailable::RATE_LIMITED, $seconds);
        }

        if ($response->status() >= 500) {
            Log::warning('overpass.server_error', ['status' => $response->status()]);

            throw new OverpassUnavailable(OverpassUnavailable::SERVER_ERROR);
        }

        if (! $response->successful()) {
            // 4xx other than 429: our query was refused (400 syntax, 403…).
            // From the administrator's seat that is still "the service did
            // not answer"; the distinction lives in the log.
            Log::warning('overpass.rejected', ['status' => $response->status()]);

            throw new OverpassUnavailable(OverpassUnavailable::SERVER_ERROR);
        }

        $body = $response->json();

        if (! is_array($body) || ! array_key_exists('elements', $body) || ! is_array($body['elements'])) {
            Log::warning('overpass.malformed');

            throw new OverpassUnavailable(OverpassUnavailable::MALFORMED);
        }

        return $this->prune($body['elements']);
    }

    /**
     * Keep only what the mapper reads: type, id, coordinates (node lat/lon or
     * way/relation centre) and tags. Everything else — versions, changesets,
     * user metadata — is deliberately dropped BEFORE caching, so editor
     * identities never even enter this system's storage.
     *
     * @param  array<int|string, mixed>  $elements
     * @return array{elements: list<array<string, mixed>>, truncated: bool}
     */
    private function prune(array $elements): array
    {
        $kept = [];
        $truncated = false;

        foreach ($elements as $element) {
            if (count($kept) >= self::MAX_ELEMENTS) {
                $truncated = true;
                break;
            }

            if (! is_array($element)) {
                continue;
            }

            $type = $element['type'] ?? null;
            $id = $element['id'] ?? null;

            if (! in_array($type, ['node', 'way', 'relation'], true) || ! is_int($id)) {
                continue;
            }

            $centre = is_array($element['center'] ?? null) ? $element['center'] : null;

            $lat = $element['lat'] ?? $centre['lat'] ?? null;
            $lon = $element['lon'] ?? $centre['lon'] ?? null;

            if (! is_numeric($lat) || ! is_numeric($lon)) {
                continue;
            }

            $tags = is_array($element['tags'] ?? null) ? $element['tags'] : [];

            $kept[] = [
                'type' => $type,
                'id' => $id,
                'lat' => (float) $lat,
                'lon' => (float) $lon,
                'tags' => $tags,
            ];
        }

        return ['elements' => $kept, 'truncated' => $truncated];
    }
}
