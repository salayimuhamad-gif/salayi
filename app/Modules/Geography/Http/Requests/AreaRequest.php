<?php

declare(strict_types=1);

namespace App\Modules\Geography\Http\Requests;

use App\Modules\Geography\Enums\AreaType;
use App\Modules\Geography\Models\Area;
use App\Modules\Geography\Support\Wkt;
use App\Modules\Geography\ValueObjects\Coordinates;
use App\Modules\Projects\Rules\ValidWkt;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * The keys `validated()` can return are exactly the rules above, and every
 * one of them is a column on the model this request feeds. Stating that
 * lets the analyser verify `Model::create($request->validated())` instead of
 * seeing an array of unknown strings — and it fails loudly if a rule is ever
 * added for a field the model does not have.
 *
 * @method array<'name_ckb'|'name_ar'|'name_en'|'slug'|'type'|'parent_id'|'description_ckb'|'description_ar'|'description_en'|'latitude'|'longitude'|'boundary_wkt'|'source'|'confidence', mixed> validated(string|null $key = null, mixed $default = null)
 */
final class AreaRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasPermission(
            $this->route('area') ? 'geography.areas.update' : 'geography.areas.create',
        ) ?? false;
    }

    /**
     * New and updated slugs are stored in their canonical lowercase form, so
     * the row matches the public route constraint (`[a-z0-9\-]+`) from the day
     * it is written. Normalising BEFORE validation means the unique check runs
     * against the value that will actually be stored. Existing rows are left
     * alone — legacy mixed-case slugs (production's `EBL-CITY`) are handled at
     * read time by Area::publicSlug()/scopeCanonicalSlug(), never rewritten.
     */
    protected function prepareForValidation(): void
    {
        $slug = $this->input('slug');

        if (is_string($slug)) {
            $this->merge(['slug' => mb_strtolower($slug)]);
        }
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $id = $this->route('area')?->id;

        return [
            'name_ckb' => ['required', 'string', 'max:191'],
            'name_ar' => ['nullable', 'string', 'max:191'],
            'name_en' => ['nullable', 'string', 'max:191'],
            'slug' => ['required', 'string', 'max:191', 'regex:/^[\p{L}\p{N}\-]+$/u', 'unique:areas,slug'.($id ? ','.$id : '')],
            'type' => ['required', 'string', 'in:'.implode(',', array_map(
                static fn (AreaType $t): string => $t->value,
                AreaType::cases(),
            ))],
            'parent_id' => ['nullable', 'integer', 'exists:areas,id'],
            'description_ckb' => ['nullable', 'string', 'max:20000'],
            'description_ar' => ['nullable', 'string', 'max:20000'],
            'description_en' => ['nullable', 'string', 'max:20000'],
            'latitude' => ['nullable', 'numeric', 'between:-90,90'],
            'longitude' => ['nullable', 'numeric', 'between:-180,180'],
            /*
             * The SAME strict validator projects use. This door accepted
             * self-intersecting rings and overlapping MultiPolygon components
             * that ProjectRequest and the Wizard reject; the boundary columns
             * feed the same map layer, and two doors into one rendering path
             * must not enforce different rules.
             */
            'boundary_wkt' => ['nullable', 'string', 'max:500000', new ValidWkt],
            'source' => ['nullable', 'string', 'max:191'],
            'confidence' => ['nullable', 'string', 'in:low,medium,high'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $this->validateHierarchy($validator);
            $this->validateGeometry($validator);
        });
    }

    /**
     * The hierarchy rules are enforced by the Area model, which throws. Doing
     * the check here as well converts that exception into a field-level message
     * an editor can act on — an unhandled RuntimeException in a form submission
     * is a 500 page, not feedback.
     */
    private function validateHierarchy(Validator $validator): void
    {
        $parentId = $this->input('parent_id');

        if ($parentId === null || $parentId === '') {
            return;
        }

        $parent = Area::query()->find($parentId);
        $type = AreaType::tryFrom((string) $this->input('type'));

        if ($parent === null || $type === null) {
            return;
        }

        if (! $parent->type->canContain($type)) {
            $validator->errors()->add('parent_id', __('geography.errors.parent_too_fine'));
        }

        $current = $this->route('area');

        if ($current !== null) {
            if ((int) $parentId === $current->id) {
                $validator->errors()->add('parent_id', __('geography.errors.self_parent'));
            } elseif (str_starts_with($parent->path, $current->path)) {
                $validator->errors()->add('parent_id', __('geography.errors.would_create_cycle'));
            }
        }
    }

    private function validateGeometry(Validator $validator): void
    {
        $lat = $this->input('latitude');
        $lng = $this->input('longitude');

        if ($lat !== null && $lng !== null && $lat !== '' && $lng !== '') {
            $coordinates = Coordinates::tryMake((string) $lat, (string) $lng);

            if ($coordinates === null) {
                $validator->errors()->add('latitude', __('projects.errors.invalid_coordinates'));
            } elseif ($coordinates->looksSwapped()) {
                $validator->errors()->add('latitude', __('projects.errors.coordinates_look_swapped'));
            } elseif (! $coordinates->withinOperatingArea()) {
                $validator->errors()->add('latitude', __('projects.errors.outside_operating_area'));
            }
        }

        $wkt = $this->input('boundary_wkt');

        if (is_string($wkt) && trim($wkt) !== '') {
            $result = Wkt::validate($wkt);

            if (! $result['valid']) {
                $validator->errors()->add('boundary_wkt', __('projects.errors.invalid_boundary'));
            } elseif (! in_array($result['type'], ['POLYGON', 'MULTIPOLYGON'], true)) {
                $validator->errors()->add('boundary_wkt', __('projects.errors.boundary_must_be_polygon'));
            }
        }
    }
}
