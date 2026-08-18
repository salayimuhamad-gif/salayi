<?php

declare(strict_types=1);

namespace App\Modules\Identity\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Route-level permission gate: `->middleware('permission:projects.publish')`.
 *
 * This is a coarse first line only. Object-level authorisation still belongs
 * in a Policy — spec 35.4 tests "object-level authorization" separately,
 * because holding `projects.update` does not imply the right to update every
 * project once company scoping arrives in Step 5.
 */
final class EnsurePermission
{
    public function handle(Request $request, Closure $next, string ...$permissions): Response
    {
        $user = $request->user();

        if ($user === null) {
            abort(401);
        }

        foreach ($permissions as $permission) {
            if (! $user->hasPermission($permission)) {
                abort(403, __('identity.errors.missing_permission'));
            }
        }

        return $next($request);
    }
}
