<?php

declare(strict_types=1);

namespace App\Modules\Identity\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A recorded consent decision (spec 30.2, 30.3).
 *
 * Append-only in practice: withdrawing consent writes a new row with
 * `granted = false` rather than deleting the grant. Spec 23.3 requires "no
 * marketing contact without valid consent" — proving that at a point in time
 * needs the history, not just the current state.
 *
 * @property string $type
 * @property bool $granted
 *
 * ---- generated model properties (scripts/generate-model-annotations.php)
 * @property int $id
 * @property int $user_id
 * @property string $type
 * @property bool $granted
 * @property string|null $source
 * @property array<string, mixed>|null $evidence
 * @property string|null $ip_hash
 * @property string|null $user_agent_hash
 * @property string|null $locale
 * @property Carbon|null $granted_at
 * @property Carbon|null $withdrawn_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 *
 * ---- end generated model properties
 */
final class Consent extends Model
{
    protected $table = 'consents';

    protected $fillable = [
        'user_id', 'type', 'granted', 'source', 'evidence',
        'ip_hash', 'user_agent_hash', 'locale', 'granted_at', 'withdrawn_at',
    ];

    protected function casts(): array
    {
        return [
            'granted' => 'boolean',
            'granted_at' => 'datetime',
            'withdrawn_at' => 'datetime',
            'evidence' => 'array',
        ];
    }

    /** Spec 30.2 consent types. */
    public const TYPES = [
        'account_registration',
        'telegram_contact_sharing',
        'alerts',
        'marketing',
        'company_contact',
        'portfolio_contact',
        'location_usage',
        'analytics',
        'ai_processing',
        'third_party_providers',
    ];

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
