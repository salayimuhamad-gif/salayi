<?php

declare(strict_types=1);

namespace App\Modules\Advisor\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * ---- generated model properties (scripts/generate-model-annotations.php)
 *
 * @property int $id
 * @property string $public_id
 * @property int|null $user_id
 * @property string|null $session_key
 * @property int|null $lifestyle_profile_id
 * @property string $agent
 * @property string $locale
 * @property string $status
 * @property Carbon|null $last_message_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 *
 * ---- end generated model properties
 */
final class AdvisorConversation extends Model
{
    use SoftDeletes;

    protected $table = 'advisor_conversations';

    protected $fillable = [
        'public_id', 'user_id', 'session_key', 'lifestyle_profile_id',
        'agent', 'locale', 'status', 'last_message_at',
    ];

    protected function casts(): array
    {
        return ['last_message_at' => 'datetime'];
    }

    /**
     * @return HasMany<AdvisorMessage, $this>
     */
    public function messages(): HasMany
    {
        return $this->hasMany(AdvisorMessage::class)->orderBy('created_at');
    }

    protected static function booted(): void
    {
        self::creating(function (self $conversation): void {
            $conversation->public_id ??= (string) Str::uuid();
        });
    }
}
