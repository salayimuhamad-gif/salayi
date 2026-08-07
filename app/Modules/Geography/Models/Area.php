<?php

declare(strict_types=1);

namespace App\Modules\Geography\Models;

use App\Modules\Geography\Concerns\HasCoordinates;
use App\Modules\Geography\Concerns\HasTrilingualNames;
use App\Modules\Geography\Enums\AreaType;
use App\Modules\Projects\Enums\PublicationStatus;
use App\Modules\Projects\Models\Project;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * A geographic area (spec 10.2, 12.2).
 *
 * Hierarchy is a MATERIALISED PATH, stored as "/1/7/23/". Two reasons:
 *
 *   1. "Every project in this district, including its sectors, zones and
 *      neighbourhoods" is one indexed LIKE '/1/7/%' scan. A recursive CTE would
 *      be cleaner but cannot be relied on across the MySQL versions a shared
 *      host may run.
 *   2. Depth is denormalised alongside it, so an accidental cycle is detectable
 *      rather than producing an infinite query.
 *
 * @property string $path
 * @property int $depth
 *
 * ---- generated model properties (scripts/generate-model-annotations.php)
 * @property int $id
 * @property int|null $parent_id
 * @property string $path
 * @property int $depth
 * @property AreaType $type
 * @property string $slug
 * @property string|null $external_id
 * @property string $name_ckb
 * @property string|null $name_ar
 * @property string|null $name_en
 * @property array<string, mixed>|null $aliases
 * @property string|null $search_key
 * @property string|null $description_ckb
 * @property string|null $description_ar
 * @property string|null $description_en
 * @property string|null $latitude
 * @property string|null $longitude
 * @property string|null $boundary_wkt
 * @property string|null $bbox_min_lat
 * @property string|null $bbox_min_lng
 * @property string|null $bbox_max_lat
 * @property string|null $bbox_max_lng
 * @property int|null $area_sqm
 * @property string|null $lifestyle_classification
 * @property int|null $quality_score
 * @property PublicationStatus $publication_status
 * @property Carbon|null $published_at
 * @property Carbon|null $verified_at
 * @property string|null $source
 * @property string $confidence
 * @property int|null $created_by
 * @property int|null $reviewed_by
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 *
 * ---- end generated model properties
 */
final class Area extends Model
{
    use HasCoordinates;
    use HasTrilingualNames;
    use SoftDeletes;

    protected $table = 'areas';

    protected $fillable = [
        'parent_id', 'type', 'slug', 'external_id',
        'name_ckb', 'name_ar', 'name_en', 'aliases',
        'description_ckb', 'description_ar', 'description_en',
        'latitude', 'longitude', 'boundary_wkt',
        'lifestyle_classification', 'publication_status',
        'source', 'confidence', 'verified_at',
    ];

    protected function casts(): array
    {
        return [
            'type' => AreaType::class,
            'publication_status' => PublicationStatus::class,
            'aliases' => 'array',
            'latitude' => 'decimal:7',
            'longitude' => 'decimal:7',
            'depth' => 'integer',
            'area_sqm' => 'integer',
            'quality_score' => 'integer',
            'verified_at' => 'datetime',
            'published_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<self, $this>
     */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    /**
     * @return HasMany<self, $this>
     */
    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id');
    }

    /**
     * @return HasMany<Place, $this>
     */
    public function places(): HasMany
    {
        return $this->hasMany(Place::class);
    }

    /**
     * Projects directly in this area.
     *
     * Direct children only, not the whole subtree — a district's count that
     * silently included its neighbourhoods would not reconcile with the list
     * below it.
     *
     * @return HasMany<Project, $this>
     */
    public function projects(): HasMany
    {
        return $this->hasMany(Project::class);
    }

    /**
     * Every descendant at any depth, via the materialised path.
     *
     * @param  Builder<Area>  $query
     * @return Builder<Area>
     */
    public function scopeDescendantsOf(Builder $query, self $area): Builder
    {
        return $query->where('path', 'like', $area->path.'%')->where('id', '!=', $area->id);
    }

    /**
     * @param  Builder<Area>  $query
     * @return Builder<Area>
     */
    public function scopePublished(Builder $query): Builder
    {
        return $query->where('publication_status', PublicationStatus::Published->value);
    }

    /**
     * @param  Builder<Area>  $query
     * @return Builder<Area>
     */
    public function scopeOfType(Builder $query, AreaType $type): Builder
    {
        return $query->where('type', $type->value);
    }

    /** @return list<int> ancestor ids, outermost first */
    public function ancestorIds(): array
    {
        return array_values(array_filter(array_map(
            'intval',
            explode('/', trim($this->path, '/')),
        ), fn (int $id): bool => $id !== 0 && $id !== $this->id));
    }

    protected static function booted(): void
    {
        self::saving(function (self $area): void {
            $area->syncSearchKey();
            $area->syncDerivedGeometry();

            $parent = $area->parent_id !== null
                ? self::query()->find($area->parent_id)
                : null;

            // A parent must be strictly coarser. Without this a district could
            // be filed under a neighbourhood and every containment query in the
            // product would quietly return the wrong set.
            if ($parent !== null && ! $parent->type->canContain($area->type)) {
                throw new RuntimeException(sprintf(
                    'An area of type "%s" cannot contain one of type "%s".',
                    $parent->type->value,
                    $area->type->value,
                ));
            }

            /*
             * CYCLE DETECTION ON THE COMPLETE PATH, NOT A FRAGMENT.
             *
             * The old test asked whether the proposed parent's path began with
             * '/{id}/'. That is only ever true for a child of the ROOT. For an
             * area at '/1/7/23/', a descendant at '/1/7/23/44/' does not begin
             * with '/23/', so moving 23 under 44 passed the check and built a
             * cycle: two rows each inside the other's subtree, every ancestor
             * query looping or returning nonsense, and no way back without
             * manual SQL. Self-parenting was invisible for the same reason.
             *
             * A descendant is anything whose path begins with THIS area's
             * complete path, so that is what gets compared.
             */
            if ($parent !== null) {
                if ($area->exists && (int) $parent->id === (int) $area->id) {
                    throw new RuntimeException('An area cannot be its own parent.');
                }

                /*
                 * `getAttribute()` rather than `is_string($area->path)`: the
                 * column is NOT NULL, so the string check protected nothing,
                 * while an UNSAVED area has no `path` attribute at all and
                 * reading it directly throws before any guard runs. Asking for
                 * the attribute returns null in that case, which is the state
                 * this branch actually has to survive.
                 */
                $currentPath = (string) ($area->getAttribute('path') ?? '');

                // An unsaved area has no subtree yet, so nothing can be inside
                // it and no comparison is meaningful.
                if ($area->exists && $currentPath !== '' && $currentPath !== '/') {
                    $parentPath = (string) ($parent->getAttribute('path') ?? '');

                    if ($parentPath === $currentPath || str_starts_with($parentPath, $currentPath)) {
                        throw new RuntimeException(
                            'Moving this area under that parent would create a cycle: the proposed '
                            .'parent is inside this area\'s own subtree.'
                        );
                    }
                }
            }

            $area->depth = $parent === null ? 0 : $parent->depth + 1;

            /*
             * A PROVISIONAL PATH, because `path` is NOT NULL with no default.
             *
             * The final path needs the id, which does not exist until after
             * the insert — but leaving the column unset made every single
             * Area::create() a guaranteed integrity-constraint violation on
             * SQLite and on MySQL in strict mode. The ancestor prefix IS known
             * before insert; only the row's own id is missing, and `created`
             * appends it immediately below. Writing the prefix keeps the
             * column non-null and keeps the value truthful rather than
             * inventing a placeholder.
             */
            // A new area has no path attribute yet, so it is asked for rather
            // than read directly; the column itself is NOT NULL.
            if ((string) ($area->getAttribute('path') ?? '') === '') {
                $area->path = $parent === null ? '/' : $parent->path;
            }
        });

        // The path needs the id, which does not exist until after insert.
        self::created(function (self $area): void {
            $parent = $area->parent_id !== null ? self::query()->find($area->parent_id) : null;
            $area->path = ($parent === null ? '/' : $parent->path).$area->id.'/';
            $area->saveQuietly();
        });

        self::updated(function (self $area): void {
            if (! $area->wasChanged('parent_id')) {
                return;
            }

            $parent = $area->parent_id !== null ? self::query()->find($area->parent_id) : null;
            $newPath = ($parent === null ? '/' : $parent->path).$area->id.'/';
            $oldPath = (string) ($area->getAttribute('path') ?? '');

            if ($newPath === $oldPath || $oldPath === '') {
                return;
            }

            /*
             * THE ORIGINAL DEPTH, NOT THE CURRENT ONE.
             *
             * `saving()` has already written the moved node's new depth by the
             * time this fires, so subtracting the live value always yields a
             * delta of zero and every descendant keeps its old level while its
             * path moves. The pre-save value is the only correct baseline.
             */
            $newDepth = $parent === null ? 0 : (int) $parent->depth + 1;
            $depthDelta = $newDepth - (int) $area->getOriginal('depth');

            /*
             * THE WHOLE SUBTREE MOVES ATOMICALLY, PATH AND DEPTH TOGETHER.
             *
             * Three defects lived in the statement this replaces.
             *
             * 1. Descendant `depth` was never updated. A subtree moved deeper
             *    or shallower ended with paths and depths disagreeing, so any
             *    query filtering on depth silently returned the wrong level.
             *
             * 2. `REPLACE(path, old, new)` rewrites EVERY occurrence of the
             *    substring, not the leading prefix. A descendant at '/1/11/1/'
             *    contains '/1/' twice, so moving area 1 corrupted the tail of
             *    the path as well as its head.
             *
             * 3. Both values were interpolated straight into SQL with sprintf.
             *
             * Rewriting each descendant in PHP with bound values is correct on
             * every engine, cannot be altered by a SQL-significant character,
             * and lets path and depth move in one pass. Subtrees here are
             * administrative geography — hundreds of rows at most — so the
             * chunked loop costs nothing a bulk statement would save.
             */
            DB::transaction(function () use ($area, $oldPath, $newPath, $depthDelta): void {
                $area->path = $newPath;
                $area->saveQuietly();

                self::query()
                    ->where('path', 'like', $oldPath.'%')
                    ->where('id', '!=', $area->id)
                    ->orderBy('id')
                    ->chunkById(200, function ($descendants) use ($oldPath, $newPath, $depthDelta): void {
                        foreach ($descendants as $descendant) {
                            $path = (string) ($descendant->getAttribute('path') ?? '');

                            // Prefix-only, verified before rewriting.
                            if (! str_starts_with($path, $oldPath)) {
                                continue;
                            }

                            self::query()->whereKey($descendant->id)->update([
                                'path' => $newPath.substr($path, strlen($oldPath)),
                                'depth' => (int) $descendant->depth + $depthDelta,
                            ]);
                        }
                    });
            });
        });
    }
}
