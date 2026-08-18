<?php

declare(strict_types=1);

namespace App\Modules\Branding\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * A stored feature-flag override (spec Appendix D).
 *
 * A row here means "an administrator made a deliberate decision". Absence of
 * a row means "use the config default". The distinction matters for the audit
 * trail: we can always answer who turned advertising on and when.
 *
 * @property string $flag
 * @property bool $enabled
 * @property int|null $updated_by
 *
 * ---- generated model properties (scripts/generate-model-annotations.php)
 * @property int $id
 * @property string $flag
 * @property bool $enabled
 * @property string|null $note
 * @property int|null $updated_by
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 *
 * ---- end generated model properties
 */
final class FeatureFlag extends Model
{
    protected $table = 'feature_flags';

    protected $fillable = ['flag', 'enabled', 'note', 'updated_by'];

    protected function casts(): array
    {
        return ['enabled' => 'boolean'];
    }
}
