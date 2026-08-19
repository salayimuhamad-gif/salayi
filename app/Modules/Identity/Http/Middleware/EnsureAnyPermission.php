<?php

declare(strict_types=1);

namespace App\Modules\Identity\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Route gate satisfied by ANY of the listed permissions.
 *
 * `EnsurePermission` requires all of them, which is right for most routes and
 * wrong for the Wizard: creating a project is legitimate either as a company
 * (`projects.create_scoped`) or as the platform (`projects.create_unscoped`),
 * and almost nobody holds both. Requiring both locked out every real user;
 * requiring only the scoped one locked out platform operators.
 *
 * This is deliberately a SEPARATE middleware rather than a flag on the
 * existing one. A reader seeing `permission:a,b` should not have to check
 * which semantics apply — `permission_any:a,b` says it in the name.
 *
 * The gate is coarse on purpose: it establishes that the person may create
 * SOMETHING. Which mode they are actually in, and whether the draft in front
 * of them matches it, is decided per request against the stored session
 * context — a route group cannot know that.
 */
final class EnsureAnyPermission
{
    public function handle(Request $request, Closure $next, string ...$permissions): Response
    {
        $user = $request->user();

        if ($user === null) {
            abort(401);
        }

        foreach ($permissions as $permission) {
            if ($user->hasPermission($permission)) {
                return $next($request);
            }
        }

        abort(403, __('identity.errors.missing_permission'));
    }
}
