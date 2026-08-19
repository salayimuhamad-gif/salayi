<?php

declare(strict_types=1);

namespace App\Modules\Operations\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * Attaches a request id so an audit row, an application log line and a queued
 * job can be correlated after the fact. Cheap, and the first thing anyone
 * wants when investigating "who changed this and what else did they touch".
 */
final class RecordAuditContext
{
    public function handle(Request $request, Closure $next): Response
    {
        $requestId = $request->headers->get('X-Request-Id') ?: (string) Str::uuid();
        $request->headers->set('X-Request-Id', $requestId);

        $response = $next($request);
        $response->headers->set('X-Request-Id', $requestId);

        return $response;
    }
}
