<?php

declare(strict_types=1);

namespace App\Modules\Identity\Http\Controllers\Admin;

use App\Modules\Identity\Http\Middleware\TouchLastSeen;
use App\Modules\Identity\Models\User;
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
            /*
             * Member-activity metrics, gated on the users permission: the
             * dashboard route itself is open to any admin-surface account,
             * but usage numbers describe the member base and belong to
             * whoever may see the member list. Aggregate counts over two
             * indexed columns — no event log, no per-request writes beyond
             * TouchLastSeen's throttled timestamp (§32's own constraint).
             */
            'activity' => $request->user()?->hasPermission('identity.users.view')
                ? $this->activity()
                : null,
        ]);
    }

    /** @return array<string, int> */
    private function activity(): array
    {
        $members = fn () => User::query()->whereDoesntHave('roles');

        return [
            'online_now' => (clone $members())
                ->where('last_seen_at', '>=', now()->subSeconds(TouchLastSeen::INTERVAL_SECONDS))->count(),
            'active_today' => (clone $members())->where('last_seen_at', '>=', now()->startOfDay())->count(),
            'active_week' => (clone $members())->where('last_seen_at', '>=', now()->subDays(7))->count(),
            'active_month' => (clone $members())->where('last_seen_at', '>=', now()->subDays(30))->count(),
            'new_today' => (clone $members())->where('created_at', '>=', now()->startOfDay())->count(),
            'new_week' => (clone $members())->where('created_at', '>=', now()->subDays(7))->count(),
            'new_month' => (clone $members())->where('created_at', '>=', now()->subDays(30))->count(),
            'telegram_linked' => (clone $members())->whereNotNull('telegram_verified_at')->count(),
            'total' => $members()->count(),
        ];
    }
}
