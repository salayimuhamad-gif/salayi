<?php

declare(strict_types=1);

namespace App\Modules\Geography\Http\Requests;

use App\Modules\Geography\ValueObjects\Coordinates;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * Place validation (spec 11.2).
 *
 * Coordinates are REQUIRED here, unlike on a project. A project draft
 * legitimately exists before its location is known; a place without a location
 * has no purpose — it cannot be near anything.
 */
/**
 * The keys `validated()` can return are exactly the rules above, and every
 * one of them is a column on the model this request feeds. Stating that
 * lets the analyser verify `Model::create($request->validated())` instead of
 * seeing an array of unknown strings — and it fails loudly if a rule is ever
 * added for a field the model does not have.
 *
 * @method array<'name_ckb'|'name_ar'|'name_en'|'place_category_id'|'subcategory'|'area_id'|'latitude'|'longitude'|'address_ckb'|'address_ar'|'address_en'|'website'|'operational_status'|'is_public'|'source'|'source_url'|'confidence', mixed> validated(string|null $key = null, mixed $default = null)
 */
final class PlaceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasPermission(
            $this->route('place') ? 'geography.places.update' : 'geography.places.create',
        ) ?? false;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'name_ckb' => ['required', 'string', 'max:191'],
            'name_ar' => ['nullable', 'string', 'max:191'],
            'name_en' => ['nullable', 'string', 'max:191'],
            'place_category_id' => ['required', 'integer', 'exists:place_categories,id'],
            'subcategory' => ['nullable', 'string', 'max:64'],
            'area_id' => ['nullable', 'integer', 'exists:areas,id'],
            'latitude' => ['required', 'numeric', 'between:-90,90'],
            'longitude' => ['required', 'numeric', 'between:-180,180'],
            'address_ckb' => ['nullable', 'string', 'max:255'],
            'address_ar' => ['nullable', 'string', 'max:255'],
            'address_en' => ['nullable', 'string', 'max:255'],
            'website' => ['nullable', 'url', 'max:255'],
            'operational_status' => ['required', 'string', 'in:operating,temporarily_closed,permanently_closed,under_construction,planned'],
            'is_public' => ['boolean'],
            'source' => ['nullable', 'string', 'max:191'],
            'source_url' => ['nullable', 'url', 'max:255'],
            'confidence' => ['nullable', 'string', 'in:low,medium,high'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $coordinates = Coordinates::tryMake(
                (string) $this->input('latitude'),
                (string) $this->input('longitude'),
            );

            if ($coordinates === null) {
                $validator->errors()->add('latitude', __('projects.errors.invalid_coordinates'));

                return;
            }

            if ($coordinates->isNullIsland()) {
                $validator->errors()->add('latitude', __('projects.errors.null_island'));
            } elseif ($coordinates->looksSwapped()) {
                $validator->errors()->add('latitude', __('projects.errors.coordinates_look_swapped'));
            } elseif (! $coordinates->withinOperatingArea()) {
                $validator->errors()->add('latitude', __('projects.errors.outside_operating_area'));
            }
        });
    }
}
