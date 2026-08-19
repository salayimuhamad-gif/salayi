<?php

declare(strict_types=1);

namespace App\Modules\Operations\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Proof the cron is alive (spec 24.3 "scheduler heartbeat").
 *
 * On shared hosting a cron entry silently stopping is the single most common
 * production incident, and without this row nothing surfaces it — alerts stop
 * arriving and imports stop finishing with no error anywhere.
 *
 * @property string $key
 *
 * ---- generated model properties (scripts/generate-model-annotations.php)
 * @property int $id
 * @property string $key
 * @property Carbon|null $last_run_at
 * @property Carbon|null $last_success_at
 * @property int $consecutive_failures
 * @property string|null $last_error
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 *
 * ---- end generated model properties
 */
final class SchedulerHeartbeat extends Model
{
    protected $table = 'scheduler_heartbeats';

    protected $fillable = ['key', 'last_run_at', 'last_success_at', 'consecutive_failures', 'last_error'];

    protected function casts(): array
    {
        return [
            'last_run_at' => 'datetime',
            'last_success_at' => 'datetime',
            'consecutive_failures' => 'integer',
        ];
    }

    public function isStale(int $toleranceMinutes = 5): bool
    {
        return $this->last_success_at === null
            || $this->last_success_at->lt(now()->subMinutes($toleranceMinutes));
    }
}
