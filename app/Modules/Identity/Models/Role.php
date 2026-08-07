<?php

declare(strict_types=1);

namespace App\Modules\Identity\Models;

use App\Modules\Identity\Enums\RoleKey;
use App\Modules\Identity\Support\PermissionRegistry;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Carbon;

/**
 * @property string $key
 * @property bool $is_system
 *
 * ---- generated model properties (scripts/generate-model-annotations.php)
 * @property int $id
 * @property string $key
 * @property string $name
 * @property string|null $description
 * @property bool $is_system
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 *
 * ---- end generated model properties
 */
final class Role extends Model
{
    protected $table = 'roles';

    protected $fillable = ['key', 'name', 'description', 'is_system'];

    protected function casts(): array
    {
        return ['is_system' => 'boolean'];
    }

    /**
     * @return BelongsToMany<User, $this>
     */
    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'role_user')->withTimestamps();
    }

    public function enum(): ?RoleKey
    {
        return RoleKey::tryFrom($this->key);
    }

    /** @return list<string> */
    public function permissions(): array
    {
        return PermissionRegistry::forRole($this->key);
    }
}
