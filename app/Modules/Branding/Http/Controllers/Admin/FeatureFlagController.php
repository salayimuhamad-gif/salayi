<?php

declare(strict_types=1);

namespace App\Modules\Branding\Http\Controllers\Admin;

use App\Modules\Core\Contracts\FeatureFlagRepository;
use App\Modules\Core\Exceptions\FeatureFlagAuthorizationException;
use App\Modules\Core\Support\FeatureFlagResolver;
use App\Modules\Operations\Services\AuditLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Inertia\Inertia;
use Inertia\Response;

final class FeatureFlagController extends Controller
{
    public function __construct(
        private readonly FeatureFlagRepository $flags,
        private readonly FeatureFlagResolver $resolver,
        private readonly AuditLogger $audit,
    ) {}

    public function index(): Response
    {
        $all = $this->flags->all();
        $rows = [];

        foreach ($all as $flag => $enabled) {
            $rows[] = [
                'flag' => $flag,
                'enabled' => $enabled,
                'requires_super_admin' => $this->resolver->requiresSuperAdmin($flag),
                'known' => $this->resolver->isKnown($flag),
                'group' => str_contains($flag, '.') ? explode('.', $flag)[0] : 'general',
            ];
        }

        return Inertia::render('Admin/FeatureFlags', ['flags' => $rows]);
    }

    public function update(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'flag' => ['required', 'string', 'max:96'],
            'enabled' => ['required', 'boolean'],
        ]);

        $user = $request->user();

        try {
            $this->flags->set(
                $validated['flag'],
                $validated['enabled'],
                $user?->id,
                $user?->isSuperAdmin() ?? false,
            );
        } catch (FeatureFlagAuthorizationException $e) {
            $this->audit->security('feature_flag.denied', [
                'flag' => $validated['flag'],
                'user_id' => $user?->id,
            ]);

            return back()->withErrors(['flag' => $e->getMessage()]);
        }

        $this->audit->record('feature_flag.updated', null, [
            'flag' => $validated['flag'],
            'enabled' => $validated['enabled'],
        ], severity: 'warning');

        return back()->with('success', __('app.states.saved'));
    }
}
