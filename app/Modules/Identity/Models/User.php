<?php

declare(strict_types=1);

namespace App\Modules\Identity\Models;

use App\Modules\Identity\Enums\RoleKey;
use App\Modules\Identity\Support\PermissionRegistry;
use App\Modules\Leads\Models\DemandProfile;
use App\Modules\Portfolio\Models\PortfolioProperty;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;

/**
 * @property int $id
 * @property string $name
 * @property string|null $email
 * @property string $preferred_locale
 * @property string|null $mfa_secret
 * @property Carbon|null $mfa_confirmed_at
 * @property bool $is_active
 * @property-read int|null $advisor_request_count withCount alias, users workspace
 * @property-read int|null $portfolio_count withCount alias, users workspace
 * @property-read bool|int|null $contact_consent withExists alias, users workspace
 *
 * ---- generated model properties (scripts/generate-model-annotations.php)
 * @property int $id
 * @property string $name
 * @property string|null $email
 * @property Carbon|null $email_verified_at
 * @property mixed $password
 * @property string $preferred_locale
 * @property string $timezone
 * @property string|null $phone_encrypted
 * @property string|null $phone_index
 * @property int $phone_verified
 * @property string|null $telegram_id
 * @property string|null $telegram_username
 * @property string|null $mfa_secret
 * @property string|null $mfa_recovery_codes
 * @property Carbon|null $mfa_confirmed_at
 * @property bool $is_active
 * @property Carbon|null $suspended_at
 * @property string|null $suspended_reason
 * @property Carbon|null $last_login_at
 * @property string|null $last_login_ip_hash
 * @property string|null $remember_token
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 * @property Carbon|null $telegram_verified_at
 * @property Carbon|null $onboarding_completed_at
 * @property string|null $display_name
 * @property string|null $profile_photo_path
 * @property string|null $profile_photo_disk
 * @property int|null $profile_area_id
 * @property string|null $profile_bio
 * @property string|null $contact_preference
 * @property string|null $primary_purpose
 * @property string|null $gender
 * @property Carbon|null $date_of_birth
 * @property Carbon|null $last_seen_at
 *
 * ---- end generated model properties
 */
final class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory;

    use Notifiable;
    use SoftDeletes;

    protected $table = 'users';

    /**
     * Laravel resolves factories by convention from the model's namespace.
     * This model lives under App\Modules\..., which does not map to
     * Database\Factories\UserFactory, so the binding is stated explicitly
     * rather than left to fail at the first `User::factory()` call.
     */
    protected static function newFactory(): UserFactory
    {
        return UserFactory::new();
    }

    protected $fillable = [
        'name', 'email', 'password', 'preferred_locale', 'is_active', 'timezone',
    ];

    /**
     * Spec 32.2: phone, telegram id, MFA material and recovery codes must
     * never reach a template, an API resource or an analytics payload.
     */
    protected $hidden = [
        'password', 'remember_token',
        'mfa_secret', 'mfa_recovery_codes',
        'phone_encrypted', 'phone_index', 'telegram_id',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'mfa_confirmed_at' => 'datetime',
            'telegram_verified_at' => 'datetime',
            'onboarding_completed_at' => 'datetime',
            /*
             * A DATE, not a datetime: a birthday has no time of day, and
             * casting it as one would drag it across a day boundary the first
             * time a timezone was applied to it.
             */
            'date_of_birth' => 'date',
            'last_login_at' => 'datetime',
            'last_seen_at' => 'datetime',
            'suspended_at' => 'datetime',
            'password' => 'hashed',
            'is_active' => 'boolean',
            'mfa_recovery_codes' => 'encrypted:array',
        ];
    }

    // ---------------------------------------------------------------
    // Relationships
    // ---------------------------------------------------------------

    /**
     * @return BelongsToMany<Role, $this>
     */
    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(Role::class, 'role_user')->withTimestamps();
    }

    /**
     * The newest advisor request, for the users workspace: one row's worth of
     * "what is this person currently looking for", resolved as a one-of-many
     * so a page of users costs one extra query rather than one per row.
     *
     * @return HasOne<DemandProfile, $this>
     */
    public function latestAdvisorRequest(): HasOne
    {
        return $this->hasOne(DemandProfile::class)->ofMany(
            ['updated_at' => 'max', 'id' => 'max'],
            static fn ($query) => $query->where('source', 'advisor'),
        );
    }

    /**
     * @return HasMany<DemandProfile, $this>
     */
    public function demandProfiles(): HasMany
    {
        return $this->hasMany(DemandProfile::class);
    }

    /**
     * @return HasMany<PortfolioProperty, $this>
     */
    public function portfolioProperties(): HasMany
    {
        return $this->hasMany(PortfolioProperty::class);
    }

    /**
     * @return HasMany<Consent, $this>
     */
    public function consents(): HasMany
    {
        return $this->hasMany(Consent::class);
    }

    // ---------------------------------------------------------------
    // Authorisation
    // ---------------------------------------------------------------

    /** @return Collection<int, string> */
    public function roleKeys(): Collection
    {
        return $this->roles->pluck('key');
    }

    public function hasRole(RoleKey|string $role): bool
    {
        $key = $role instanceof RoleKey ? $role->value : $role;

        return $this->roleKeys()->contains($key);
    }

    public function isSuperAdmin(): bool
    {
        return $this->hasRole(RoleKey::SuperAdmin);
    }

    /**
     * Effective permission check.
     *
     * Super Admin short-circuits to true. Everyone else is the union of their
     * roles' permission lists. A suspended or inactive account holds nothing,
     * regardless of role — spec 30.1, an offboarded employee's session must
     * stop working the moment the account is disabled, not at next login.
     */
    /**
     * THE active-account rule, in one place (corrections H4/M12).
     *
     * Every way into a session — password, Telegram returning login, the
     * legacy Share Contact flow, the registration poll, the account-link
     * poll — and every authenticated request through the account.active
     * middleware asks this ONE method. A rule that lives in one place
     * cannot be forgotten by the next route.
     */
    public function mayAuthenticate(): bool
    {
        return $this->is_active && $this->suspended_at === null;
    }

    public function hasPermission(string $permission): bool
    {
        if (! $this->is_active || $this->suspended_at !== null) {
            return false;
        }

        if ($this->isSuperAdmin()) {
            return true;
        }

        foreach ($this->roleKeys() as $roleKey) {
            if (in_array($permission, PermissionRegistry::forRole($roleKey), true)) {
                return true;
            }
        }

        return false;
    }

    /** @return list<string> */
    public function allPermissions(): array
    {
        if ($this->isSuperAdmin()) {
            return PermissionRegistry::all();
        }

        $permissions = [];

        foreach ($this->roleKeys() as $roleKey) {
            $permissions = array_merge($permissions, PermissionRegistry::forRole($roleKey));
        }

        $permissions = array_values(array_unique($permissions));
        sort($permissions);

        return $permissions;
    }

    /** Any administrative role means /admin is reachable at all. */
    public function isAdministrative(): bool
    {
        foreach ($this->roleKeys() as $key) {
            $role = RoleKey::tryFrom($key);

            if ($role !== null && $role->isAdministrative()) {
                return true;
            }
        }

        return false;
    }

    public function requiresMfa(): bool
    {
        if (! (bool) config('mulkihawler.security.admin_mfa_required', true)) {
            return false;
        }

        return $this->isAdministrative();
    }

    public function hasMfaEnabled(): bool
    {
        return $this->mfa_secret !== null && $this->mfa_confirmed_at !== null;
    }

    // ---------------------------------------------------------------
    // PII (spec 22.2, 30.1)
    // ---------------------------------------------------------------

    /**
     * Phone is stored encrypted with a searchable blind index, never in clear.
     * The index is a keyed HMAC, so an equality lookup still works while a
     * database dump reveals nothing.
     */
    public function setPhone(?string $e164): void
    {
        if ($e164 === null || $e164 === '') {
            $this->phone_encrypted = null;
            $this->phone_index = null;

            return;
        }

        $this->phone_encrypted = Crypt::encryptString($e164);
        $this->phone_index = self::blindIndex($e164);
    }

    public function phone(): ?string
    {
        if ($this->phone_encrypted === null) {
            return null;
        }

        try {
            return Crypt::decryptString($this->phone_encrypted);
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * The avatar the interface renders for this account: the processed photo
     * when one exists, otherwise material for an initials fallback.
     *
     * URLs come from the storage disk, so what leaves the server is the
     * public `/storage/...` address of a re-encoded file — never a filesystem
     * location and never anything the uploader named.
     *
     * @return array{photo: string|null, thumb: string|null, initials: string}
     */
    public function avatarPayload(): array
    {
        return [
            'photo' => $this->profilePhotoUrl(256),
            'thumb' => $this->profilePhotoUrl(64),
            'initials' => $this->initials(),
        ];
    }

    public function profilePhotoUrl(int $size): ?string
    {
        if ($this->profile_photo_path === null || $this->profile_photo_disk === null) {
            return null;
        }

        $name = $size <= 64 ? 'p64' : 'p256';

        return Storage::disk($this->profile_photo_disk)->url($this->profile_photo_path.'/'.$name.'.jpg');
    }

    /**
     * Up to two initials from the display name, falling back to the account
     * name — grapheme-aware, because Sorani and Arabic names are not ASCII.
     */
    public function initials(): string
    {
        $source = trim((string) ($this->display_name ?? $this->name));

        if ($source === '') {
            return '·';
        }

        $words = preg_split('/\s+/u', $source) ?: [];
        $letters = array_map(
            static fn (string $word): string => mb_substr($word, 0, 1),
            array_slice(array_values(array_filter($words)), 0, 2),
        );

        return implode('', $letters);
    }

    public static function blindIndex(string $value): string
    {
        $key = (string) config('mulkihawler.security.blind_index_key');

        // A missing key must fail loudly. Falling back to an unkeyed hash
        // would produce a rainbow-table-able index that looks like it works.
        if ($key === '') {
            throw new RuntimeException('MULKIHAWLER_BLIND_INDEX_KEY is not configured; refusing to build an unkeyed index.');
        }

        return hash_hmac('sha256', mb_strtolower(trim($value)), $key);
    }
}
