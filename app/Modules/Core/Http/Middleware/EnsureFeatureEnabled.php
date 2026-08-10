<?php

declare(strict_types=1);

namespace App\Modules\Core\Http\Middleware;

use App\Modules\Core\Contracts\FeatureFlagRepository;
use App\Modules\Core\Support\FeatureFlagResolver;
use App\Modules\Operations\Services\AuditLogger;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Server-boundary feature flag gate: `->middleware('feature:map.explorer')`.
 *
 * Repair prompt §3 (EN) / §17 (AR). Before this existed, flags controlled only
 * what the navigation rendered. Every disabled surface was still fully
 * reachable by typing its URL — the controller ran, the queries ran, and the
 * response came back. For `advertising` or `marketplace.owner_listings` that
 * is a commercial exposure, not a cosmetic one, so hiding a menu item was
 * never the guarantee the flag system implied.
 *
 * Response shape is deliberate and differs by audience:
 *
 *   - PUBLIC surfaces get 404. A disabled commercial module should not confirm
 *     its own existence to an anonymous visitor; "not found" and "exists but
 *     switched off" are different disclosures, and competitors read both.
 *   - ADMIN surfaces get 403 with an explanatory page. An administrator who
 *     followed a stale bookmark needs to know the feature is off and can be
 *     turned on, not be told the page never existed.
 *   - API surfaces get 404 JSON, for the same reason as public.
 *
 * Multiple flags may be listed; ALL must be enabled
 * (`feature:companies.portal,companies.branches`). Requiring every flag rather
 * than any is the safe reading — a surface that needs two modules is broken
 * when either is missing.
 *
 * Super-Admin-gated flags (config('features.requires_super_admin')) stay
 * reachable for a Super Admin even while disabled, so the flag can be
 * configured and previewed before it is turned on for everyone. That is an
 * explicit exception and it is audited on every use.
 */
final class EnsureFeatureEnabled
{
    public function __construct(
        private readonly FeatureFlagRepository $flags,
        private readonly FeatureFlagResolver $resolver,
        private readonly AuditLogger $audit,
    ) {}

    public function handle(Request $request, Closure $next, string ...$features): Response
    {
        // A route carrying `feature:` with no flag name is a wiring mistake.
        // Failing closed is the only safe reading: an empty gate that lets
        // everything through is worse than no gate, because it looks protected.
        if ($features === []) {
            $this->deny($request, '(none)', 'no flag name supplied to feature middleware');
        }

        foreach ($features as $feature) {
            if ($this->flags->enabled($feature)) {
                continue;
            }

            $user = $request->user();

            /*
             * Super Admin previews a disabled feature in two places, each an
             * audited security event:
             *
             *   - the ADMIN surface of ANY flag, so content can be curated
             *     and settings prepared before a launch — a Super Admin must
             *     see every implemented administrative section, and a flag
             *     is a launch switch, not an authorisation;
             *   - anywhere for the requires_super_admin flags, whose whole
             *     point is a super-admin-only preview of the public surface.
             *
             * Everyone else — every ordinary administrator, every visitor,
             * every API caller — is refused exactly as before: the flag
             * keeps the public product dark until it is explicitly enabled.
             */
            if ($user?->isSuperAdmin() === true) {
                $isAdminSurface = $request->is('admin/*') || $request->is('*/admin/*');

                if ($isAdminSurface || $this->resolver->requiresSuperAdmin($feature)) {
                    $this->audit->security('feature.preview_while_disabled', [
                        'feature' => $feature,
                        'path' => $request->path(),
                        'actor_id' => $user->id,
                    ], result: 'allowed');

                    continue;
                }
            }

            $this->deny($request, $feature, 'feature disabled');
        }

        return $next($request);
    }

    /**
     * Record the attempt, then abort with the shape appropriate to the audience.
     *
     * The attempt is logged rather than silently 404'd because a stream of
     * requests to a disabled commercial surface is worth seeing — it is either
     * a stale integration or someone probing.
     */
    private function deny(Request $request, string $feature, string $reason): never
    {
        $this->audit->security('feature.access_denied', [
            'feature' => $feature,
            'reason' => $reason,
            'path' => $request->path(),
            'method' => $request->method(),
            'actor_id' => $request->user()?->id,
        ]);

        /*
         * Thrown explicitly rather than via abort(). A helper that always
         * throws is still typed as returning void, so a `never` method built
         * on it reads to a static analyser as a function that can fall off its
         * own end. These throws say the same thing unambiguously.
         */
        if ($request->expectsJson() || $request->is('api/*')) {
            throw new NotFoundHttpException;
        }

        // Admin surfaces: say what happened. Public surfaces: do not confirm
        // that the feature exists at all.
        if ($request->is('admin/*') || $request->is('*/admin/*')) {
            throw new AccessDeniedHttpException(__('app.errors.feature_disabled'));
        }

        throw new NotFoundHttpException;
    }
}
