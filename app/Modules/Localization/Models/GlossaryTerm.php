<?php

declare(strict_types=1);

namespace App\Modules\Localization\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Controlled terminology (spec 7.5).
 *
 * `blocked_alternatives` is as important as the approved list: it is how an
 * administrator stops the Translation Agent proposing a word the business has
 * decided against, and how review flags a term that slipped past.
 *
 * @property string $key
 *
 * ---- generated model properties (scripts/generate-model-annotations.php)
 * @property int $id
 * @property string $key
 * @property string $term_ckb
 * @property string|null $term_ar
 * @property string|null $term_en
 * @property string|null $description
 * @property array<string, mixed>|null $approved_alternatives
 * @property array<string, mixed>|null $blocked_alternatives
 * @property array<string, mixed>|null $search_aliases
 * @property string|null $capitalization
 * @property bool $is_active
 * @property int|null $updated_by
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 *
 * ---- end generated model properties
 */
final class GlossaryTerm extends Model
{
    protected $table = 'glossary_terms';

    protected $fillable = [
        'key', 'term_ckb', 'term_ar', 'term_en', 'description',
        'approved_alternatives', 'blocked_alternatives', 'search_aliases',
        'capitalization', 'is_active', 'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'approved_alternatives' => 'array',
            'blocked_alternatives' => 'array',
            'search_aliases' => 'array',
            'is_active' => 'boolean',
        ];
    }
}
