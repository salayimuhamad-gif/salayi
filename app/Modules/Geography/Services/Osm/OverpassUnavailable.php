<?php

declare(strict_types=1);

namespace App\Modules\Geography\Services\Osm;

use RuntimeException;

/**
 * One typed failure for every way Overpass can decline to answer.
 *
 * The admin import screen is the only consumer, and what it needs is not a
 * stack trace but an honest sentence: the service timed out, asked us to slow
 * down (and until when), errored server-side, or answered with something that
 * is not the documented JSON. Reasons are a closed set so the controller can
 * translate each into its own localized message instead of exposing raw
 * transport detail to a non-technical administrator.
 */
final class OverpassUnavailable extends RuntimeException
{
    public const TIMEOUT = 'timeout';

    public const RATE_LIMITED = 'rate_limited';

    public const SERVER_ERROR = 'server_error';

    public const MALFORMED = 'malformed';

    public const UNREACHABLE = 'unreachable';

    public function __construct(
        public readonly string $reason,
        public readonly ?int $retryAfterSeconds = null,
    ) {
        parent::__construct('Overpass unavailable: '.$reason);
    }
}
