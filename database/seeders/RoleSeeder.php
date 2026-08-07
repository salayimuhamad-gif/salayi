<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Modules\Identity\Enums\RoleKey;
use App\Modules\Identity\Models\Role;
use Illuminate\Database\Seeder;

/**
 * Seeds the 17 administrative roles plus 4 public account types (spec 3.1, 3.2).
 *
 * Idempotent: safe to re-run on every deploy, which is how a role added in a
 * later step reaches an existing installation.
 */
final class RoleSeeder extends Seeder
{
    public function run(): void
    {
        foreach (RoleKey::cases() as $role) {
            Role::query()->updateOrCreate(
                ['key' => $role->value],
                [
                    // English name as a stable fallback label; the displayed
                    // name comes from lang/{locale}/identity.php at runtime.
                    'name' => str_replace('_', ' ', ucwords($role->value, '_')),
                    'is_system' => true,
                ],
            );
        }
    }
}
