<?php

declare(strict_types=1);

namespace App\Modules\Identity\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

/**
 * Record that an authenticated account was just here — cheaply.
 *
 * One quiet UPDATE per user per interval, gated through the cache so the
 * common case (an active user clicking around) costs a cache read and
 * nothing else. `Cache::add` is atomic on every store this project runs, so
 * concurrent requests do not stack writes. A raw query rather than the
 * model: no events, no observers, no `updated_at` churn — presence is
 * metadata about the row, not a change to the account.
 */
final class TouchLastSeen
{
    /** Write at most once per user per this many seconds. */
    public const INTERVAL_SECONDS = 300;

    public function handle(Request $request, Closure $next): Response
    {
        $userId = $request->user()?->getAuthIdentifier();

        if ($userId !== null && Cache::add('last-seen:'.$userId, 1, self::INTERVAL_SECONDS)) {
            DB::table('users')->where('id', $userId)->update(['last_seen_at' => now()]);
        }

        return $next($request);
    }
}
