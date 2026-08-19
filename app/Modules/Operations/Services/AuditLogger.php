<?php

declare(strict_types=1);

namespace App\Modules\Operations\Services;

use App\Modules\Operations\Models\AuditLog;
use App\Modules\Operations\Support\Redactor;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Writes audit rows (spec 26.1: "Every administrator mutation is auditable").
 *
 * Never throws into the caller's transaction. An audit write that fails must
 * be loud in the error log but must not roll back the business action that
 * succeeded — losing an audit row is bad, silently losing a project publish
 * because of it is worse.
 */
final class AuditLogger
{
    /**
     * @param  array<string, mixed>  $changes
     * @param  array<string, mixed>  $context
     */
    public function record(
        string $action,
        ?Model $subject = null,
        array $changes = [],
        array $context = [],
        string $result = 'success',
        string $severity = 'info',
    ): void {
        if (! (bool) config('mulkihawler.audit.enabled', true)) {
            return;
        }

        try {
            /*
             * `request()` always resolves a Request — Laravel binds one even in
             * console and queue contexts — so the instance itself needs no
             * null-safe access. What genuinely CAN be null is what it contains:
             * there is no authenticated user in a queued job, and no route when
             * the code did not arrive over HTTP, so those keep their guards.
             */
            $request = request();
            $actor = $request->user();

            AuditLog::query()->create([
                'actor_id' => $actor?->id,
                // Denormalised so the log still reads correctly after the
                // account is deleted under spec 30.3 data rights.
                'actor_label' => $actor === null ? 'system' : $actor->name,
                'action' => $action,
                'auditable_type' => $subject?->getMorphClass(),
                'auditable_id' => $subject?->getKey(),
                'changes' => Redactor::scrub($changes),
                'context' => Redactor::scrub($context),
                'ip_hash' => Redactor::hashIp($request->ip()),
                'user_agent' => mb_substr((string) $request->userAgent(), 0, 255),
                'route' => $request->route()?->getName(),
                'method' => $request->method(),
                'request_id' => $request->headers->get('X-Request-Id'),
                'result' => $result,
                'severity' => $severity,
            ]);
        } catch (Throwable $e) {
            Log::channel('security')->error('Audit write failed', [
                'action' => $action,
                'exception' => $e->getMessage(),
            ]);
        }
    }

    /** Convenience for a model update: logs only the attributes that changed. */
    public function recordModelChange(string $action, Model $model, string $severity = 'info'): void
    {
        $changes = [];

        foreach ($model->getChanges() as $attribute => $new) {
            if ($attribute === 'updated_at') {
                continue;
            }

            $changes[$attribute] = [
                'from' => $model->getOriginal($attribute),
                'to' => $new,
            ];
        }

        if ($changes === []) {
            return;
        }

        $this->record($action, $model, $changes, severity: $severity);
    }

    /**
     * @param  array<string, mixed>  $context  diagnostic detail, scrubbed before it is written
     */
    public function security(string $action, array $context = [], string $result = 'denied'): void
    {
        $this->record($action, null, [], $context, $result, 'warning');

        Log::channel('security')->warning($action, Redactor::scrub($context));
    }
}
