<?php

declare(strict_types=1);

namespace App\Modules\Identity\Http\Controllers\Admin;

use App\Modules\Operations\Models\SchedulerHeartbeat;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The admin overview (spec 24.3).
 *
 * Deliberately shows operational truth rather than vanity counts. The
 * scheduler heartbeat is first because a silently stopped cron is the most
 * common shared-hosting incident and nothing else on the page reveals it.
 */
final class DashboardController extends Controller
{
    public function __invoke(Request $request): Response
    {
        $heartbeat = SchedulerHeartbeat::query()->where('key', 'scheduler')->first();

        return Inertia::render('Admin/Dashboard', [
            'scheduler' => [
                'last_success_at' => $heartbeat?->last_success_at?->toIso8601String(),
                'is_stale' => $heartbeat === null || $heartbeat->isStale(),
                'consecutive_failures' => $heartbeat === null ? 0 : $heartbeat->consecutive_failures,
            ],
            'build' => [
                'version' => (string) config('mulkihawler.version'),
                'schema_version' => (int) config('mulkihawler.schema_version'),
                'environment' => (string) config('app.env'),
                'debug' => (bool) config('app.debug'),
            ],
            'roadmap' => [
                'note' => 'admin_shell_slice',
            ],
        ]);
    }
}
