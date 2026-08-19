<?php

declare(strict_types=1);

namespace App\Modules\Operations\Http\Controllers\Admin;

use App\Modules\Operations\Models\AuditLog;
use App\Modules\Operations\Models\SchedulerHeartbeat;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Operational visibility (spec 24.3, 30.1).
 *
 * Every step of this build wrote to `audit_logs` and nothing ever read it. An
 * append-only audit trail that cannot be inspected provides no accountability
 * — it only provides the feeling of it, which is worse, because it stops anyone
 * asking for the real thing.
 *
 * Same for failed jobs. On a cron-driven worker a job that exhausts its retries
 * fails silently: the queue drains, the site looks healthy, and the work simply
 * never happened. `failed_jobs` has recorded it since Step 1 and nothing has
 * ever looked.
 */
final class OperationsController extends Controller
{
    /** Rows past this age are not offered in the default view. */
    private const RECENT_DAYS = 30;

    public function audit(Request $request): Response
    {
        $query = AuditLog::query()
            ->when($request->string('action')->toString() !== '',
                fn ($q) => $q->where('action', 'like', $request->string('action')->toString().'%'))
            ->when($request->string('severity')->toString() !== '',
                fn ($q) => $q->where('severity', $request->string('severity')->toString()))
            ->when($request->string('actor')->toString() !== '',
                fn ($q) => $q->where('actor_label', 'like', '%'.$request->string('actor')->toString().'%'))
            ->when($request->string('subject_type')->toString() !== '',
                fn ($q) => $q->where('auditable_type', $request->string('subject_type')->toString()));

        $entries = $query
            ->orderByDesc('created_at')
            ->paginate(50)
            ->withQueryString()
            ->through(fn (AuditLog $log): array => [
                'id' => $log->id,
                'action' => $log->action,
                'severity' => $log->severity,
                // The denormalised label, not a join: the log must stay
                // readable after an account is erased under a data-rights
                // request (spec 30.3).
                'actor' => $log->actor_label,
                // The columns are `auditable_*`; `subject_*` never existed, so
                // the audit table rendered an empty subject for every entry.
                'subject_type' => $log->auditable_type,
                'subject_id' => $log->auditable_id,
                'result' => $log->result,
                'request_id' => $log->request_id,
                'created_at' => $log->created_at->toIso8601String(),
                // Already scrubbed by Redactor on write. Never re-derived here,
                // because re-deriving would mean holding the unredacted value.
                'context' => $log->context,
            ]);

        return Inertia::render('Admin/Operations/Audit', [
            'entries' => $entries,
            'filters' => [
                'action' => $request->string('action')->toString(),
                'severity' => $request->string('severity')->toString(),
                'actor' => $request->string('actor')->toString(),
                'subject_type' => $request->string('subject_type')->toString(),
            ],
            'facets' => $this->facets(),
        ]);
    }

    public function health(Request $request): Response
    {
        return Inertia::render('Admin/Operations/Health', [
            'scheduler' => $this->scheduler(),
            'queue' => $this->queue(),
            'failures' => $this->failures(),
            'data_quality' => $this->dataQuality(),
        ]);
    }

    /**
     * Distinct values for the audit filters.
     *
     * Capped and cached-shaped: `audit_logs` grows without bound and a
     * `SELECT DISTINCT` over the whole table would get slower every week on a
     * shared host.
     *
     * @return array<string, list<string>>
     */
    private function facets(): array
    {
        $since = now()->subDays(self::RECENT_DAYS);

        return [
            'actions' => AuditLog::query()
                ->where('created_at', '>=', $since)
                ->distinct()->limit(200)->orderBy('action')->pluck('action')->all(),
            'severities' => ['info', 'notice', 'warning', 'critical'],
            'subject_types' => AuditLog::query()
                ->where('created_at', '>=', $since)
                ->whereNotNull('auditable_type')
                ->distinct()->limit(50)->orderBy('auditable_type')->pluck('auditable_type')->all(),
        ];
    }

    /** @return list<array<string, mixed>> one row per scheduled key */
    private function scheduler(): array
    {
        return SchedulerHeartbeat::query()
            ->orderBy('key')
            ->get()
            ->map(fn (SchedulerHeartbeat $h): array => [
                'key' => $h->key,
                'last_success_at' => $h->last_success_at?->toIso8601String(),
                // No `last_failure_at` column exists and nothing records one;
                // failure state is `last_error` plus `consecutive_failures`,
                // both of which are already reported here.
                'last_error' => $h->last_error,
                'consecutive_failures' => $h->consecutive_failures,
                'is_stale' => $h->isStale(),
            ])
            ->all();
    }

    /**
     * Queue depth per queue name.
     *
     * Read straight from the `jobs` table because the database driver is the
     * only one available here (spec 34.3). Depth on `maintenance` growing while
     * `critical` stays empty is the signal that the worker is running but
     * saturated, which looks identical to "healthy" from anywhere else.
     *
     * @return array{driver: string, pending: array<string, int>, oldest_seconds: int|null}
     */
    private function queue(): array
    {
        $driver = (string) config('queue.default');

        if ($driver !== 'database' || ! Schema::hasTable('jobs')) {
            return ['driver' => $driver, 'pending' => [], 'oldest_seconds' => null];
        }

        $pending = DB::table('jobs')
            ->select('queue', DB::raw('count(*) as total'))
            ->groupBy('queue')
            ->pluck('total', 'queue')
            ->map(static fn ($v): int => (int) $v)
            ->all();

        $oldest = DB::table('jobs')->min('available_at');

        return [
            'driver' => $driver,
            'pending' => $pending,
            'oldest_seconds' => $oldest === null ? null : max(0, time() - (int) $oldest),
        ];
    }

    /**
     * Recent job failures.
     *
     * The exception is truncated hard. A stack trace on an admin page is a
     * disclosure risk — it names file paths, package versions and sometimes
     * query fragments containing user data.
     *
     * @return array{total: int, recent: list<array<string, mixed>>}
     */
    private function failures(): array
    {
        if (! Schema::hasTable('failed_jobs')) {
            return ['total' => 0, 'recent' => []];
        }

        $recent = DB::table('failed_jobs')
            ->orderByDesc('failed_at')
            ->limit(20)
            ->get(['id', 'uuid', 'queue', 'payload', 'exception', 'failed_at'])
            ->map(function ($row): array {
                $payload = json_decode((string) $row->payload, true);

                return [
                    'id' => $row->id,
                    'queue' => $row->queue,
                    'job' => $payload['displayName'] ?? 'unknown',
                    'failed_at' => $row->failed_at,
                    'exception' => mb_substr((string) $row->exception, 0, 240),
                ];
            })
            ->all();

        return ['total' => DB::table('failed_jobs')->count(), 'recent' => $recent];
    }

    /**
     * Cross-entity data-quality gaps.
     *
     * Counts of things that will silently not work rather than error: a
     * published project with no source cannot show its facts, a place with no
     * source is excluded from every public profile, a stale nearby snapshot is
     * showing a figure nobody has confirmed.
     *
     * @return array<string, int>
     */
    private function dataQuality(): array
    {
        $count = static function (string $table, callable $filter): int {
            if (! Schema::hasTable($table)) {
                return 0;
            }

            return (int) $filter(DB::table($table))->count();
        };

        return [
            'projects_published_without_source' => $count('projects', fn ($q) => $q
                ->where('publication_status', 'published')
                ->where(fn ($w) => $w->whereNull('source')->orWhere('source', ''))),
            'projects_without_geometry' => $count('projects', fn ($q) => $q
                ->whereNull('latitude')->whereNull('boundary_wkt')),
            'projects_never_verified' => $count('projects', fn ($q) => $q
                ->where('publication_status', 'published')->whereNull('verified_at')),
            'places_without_source' => $count('places', fn ($q) => $q
                ->where(fn ($w) => $w->whereNull('source')->orWhere('source', ''))),
            'areas_without_boundary' => $count('areas', fn ($q) => $q
                ->whereNull('boundary_wkt')),
            'stale_nearby_snapshots' => $count('project_nearby_places', fn ($q) => $q
                ->where('is_stale', true)),
        ];
    }
}
