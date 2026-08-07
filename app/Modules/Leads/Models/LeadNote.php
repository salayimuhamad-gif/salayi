<?php

declare(strict_types=1);

namespace App\Modules\Leads\Models;

use App\Modules\Identity\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Crypt;
use Throwable;

/**
 * A note on a lead (File one §9 Leads 2).
 *
 * The body is encrypted because of what sales notes actually contain: "wife is
 * pregnant, wants to move before March", "father will co-sign". Those are a
 * household's private circumstances written down by a third party, and a
 * database dump should not read as a dossier on Erbil families.
 *
 * ---- generated model properties (scripts/generate-model-annotations.php)
 *
 * @property int $id
 * @property int $demand_profile_id
 * @property int|null $author_user_id
 * @property int|null $company_id
 * @property string $body_encrypted
 * @property string|null $stage_at_time
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 *
 * ---- end generated model properties
 */
final class LeadNote extends Model
{
    protected $fillable = [
        'demand_profile_id', 'author_user_id', 'company_id',
        'body_encrypted', 'stage_at_time',
    ];

    /** The ciphertext never serialises; callers go through body(). */
    protected $hidden = ['body_encrypted'];

    /**
     * @return BelongsTo<DemandProfile, $this>
     */
    public function profile(): BelongsTo
    {
        return $this->belongsTo(DemandProfile::class, 'demand_profile_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'author_user_id');
    }

    public function setBody(string $body): void
    {
        $this->body_encrypted = Crypt::encryptString($body);
    }

    /**
     * The decrypted note.
     *
     * Returns null rather than throwing when the ciphertext cannot be read —
     * a key rotation that left old rows undecryptable must not make the whole
     * workspace unopenable, and a missing note is visible to the reader while a
     * fatal error hides every other note on the lead.
     */
    public function body(): ?string
    {
        // `lead_notes.body_encrypted` is NOT NULL. An EMPTY ciphertext is the
        // reachable case — a row written before encryption succeeded — and
        // decrypting it would throw rather than return an empty note.
        if ($this->body_encrypted === '') {
            return null;
        }

        try {
            return Crypt::decryptString($this->body_encrypted);
        } catch (Throwable) {
            return null;
        }
    }
}
