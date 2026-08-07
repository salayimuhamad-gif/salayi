<?php

declare(strict_types=1);

namespace App\Modules\Operations\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Carbon;
use RuntimeException;

/**
 * Append-only record of every administrative mutation (spec 26.1, 37.5).
 *
 * There is no `updated_at` and no update path. An audit row that can be edited
 * is not an audit row.
 *
 * @property string $action
 * @property string|null $auditable_type
 * @property array<string, mixed>|null $changes
 *
 * ---- generated model properties (scripts/generate-model-annotations.php)
 * @property int $id
 * @property int|null $actor_id
 * @property string|null $actor_label
 * @property string $action
 * @property string|null $auditable_type
 * @property int|null $auditable_id
 * @property array<string, mixed>|null $changes
 * @property array<string, mixed>|null $context
 * @property string|null $ip_hash
 * @property string|null $user_agent
 * @property string|null $route
 * @property string|null $method
 * @property string|null $request_id
 * @property string $result
 * @property string $severity
 * @property Carbon $created_at
 *
 * ---- end generated model properties
 */
final class AuditLog extends Model
{
    public const UPDATED_AT = null;

    protected $table = 'audit_logs';

    protected $fillable = [
        'actor_id', 'actor_label', 'action', 'auditable_type', 'auditable_id',
        'changes', 'context', 'ip_hash', 'user_agent', 'route', 'method',
        'request_id', 'result', 'severity',
    ];

    protected function casts(): array
    {
        return [
            'changes' => 'array',
            'context' => 'array',
        ];
    }

    /**
     * @return MorphTo<Model, $this>
     */
    public function auditable(): MorphTo
    {
        return $this->morphTo();
    }

    protected static function booted(): void
    {
        self::updating(static function (): bool {
            throw new RuntimeException('Audit log entries are append-only and cannot be modified.');
        });

        self::deleting(static function (): bool {
            throw new RuntimeException('Audit log entries are append-only and cannot be deleted.');
        });
    }
}
