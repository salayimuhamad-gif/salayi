<?php

declare(strict_types=1);

namespace App\Modules\Projects\Http\Requests;

use App\Modules\Geography\Rules\PublishableArea;
use App\Modules\Geography\Support\Wkt;
use App\Modules\Geography\ValueObjects\Coordinates;
use App\Modules\Projects\Enums\ConstructionStatus;
use App\Modules\Projects\Enums\DeliveryStatus;
use App\Modules\Projects\Enums\ProjectType;
use App\Modules\Projects\Rules\ValidWkt;
use App\Modules\Projects\Support\ProjectScope;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Project create and update validation.
 *
 * Deliberately permissive about *completeness* and strict about *correctness*.
 * A draft is saved across a fourteen-step wizard (spec 12.3, "the admin may
 * save a draft at any stage"), so almost everything is nullable here — the
 * completeness gate lives in Project::publicationReadiness() and fires at the
 * publish transition, not on every autosave.
 *
 * What is enforced unconditionally is that whatever WAS entered is valid: a
 * coordinate outside the Erbil operating area, a transposed lat/lng, or
 * malformed WKT are rejected at entry rather than stored and discovered later
 * by the map.
 */
/**
 * The keys `validated()` can return are exactly the rules above, and every
 * one of them is a column on the model this request feeds. Stating that
 * lets the analyser verify `Model::create($request->validated())` instead of
 * seeing an array of unknown strings — and it fails loudly if a rule is ever
 * added for a field the model does not have.
 *
 * @method array<'name_ckb'|'name_ar'|'name_en'|'slug'|'description_ckb'|'description_ar'|'description_en'|'project_type'|'construction_status'|'delivery_status'|'completion_percent'|'developer_id'|'area_id'|'latitude'|'longitude'|'boundary_wkt'|'launch_date'|'expected_delivery'|'actual_delivery'|'land_area_sqm'|'building_count'|'unit_count'|'source'|'official_url'|'confidence', mixed> validated(string|null $key = null, mixed $default = null)
 */
final class ProjectRequest extends FormRequest
{
    public function authorize(): bool
    {
        /*
         * Creation here is the LEGACY unscoped form — it writes no company
         * association — so it must check the same permission its route does.
         * Checking generic `projects.create` meant the request-level guard and
         * the route-level guard disagreed, and a role holding one but not the
         * other would pass one gate and fail the next.
         *
         * Update stays separate: editing an existing project is a different
         * right from creating an unscoped one, and ProjectScope enforces which
         * projects.
         */
        return $this->user()?->hasPermission(
            $this->route('project') ? 'projects.update' : 'projects.create_unscoped',
        ) ?? false;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $id = $this->route('project')?->id;

        return [
            'name_ckb' => ['required', 'string', 'max:191'],
            'name_ar' => ['nullable', 'string', 'max:191'],
            'name_en' => ['nullable', 'string', 'max:191'],
            'slug' => ['required', 'string', 'max:191', 'regex:/^[\p{L}\p{N}\-]+$/u', 'unique:projects,slug'.($id ? ','.$id : '')],

            'description_ckb' => ['nullable', 'string', 'max:20000'],
            'description_ar' => ['nullable', 'string', 'max:20000'],
            'description_en' => ['nullable', 'string', 'max:20000'],

            'project_type' => ['required', 'string', 'in:'.$this->enumValues(ProjectType::class)],
            'construction_status' => ['required', 'string', 'in:'.$this->enumValues(ConstructionStatus::class)],
            'delivery_status' => ['required', 'string', 'in:'.$this->enumValues(DeliveryStatus::class)],
            'completion_percent' => ['nullable', 'integer', 'between:0,100'],

            'developer_id' => [
                'nullable', 'integer', 'exists:developers,id',
                /*
                 * `exists` proves the id is real, not that THIS editor may
                 * assign it. A company user could otherwise craft a developer
                 * id belonging to a competitor and attribute them to their own
                 * project. Null from permittedDeveloperIds() means platform —
                 * unrestricted — so the rule is only added when scoped.
                 */
                ...(ProjectScope::permittedDeveloperIds($this) === null
                    ? []
                    : [Rule::in(ProjectScope::permittedDeveloperIds($this))]),
            ],
            'area_id' => ['nullable', 'integer', new PublishableArea],

            'latitude' => ['nullable', 'numeric', 'between:-90,90'],
            'longitude' => ['nullable', 'numeric', 'between:-180,180'],
            /*
             * The SAME strict validator the Wizard uses. This path used
             * Wkt::validate(), which only checks parseability — so the legacy
             * edit form accepted self-intersecting rings, holes outside their
             * exterior and overlapping MultiPolygon components that the Wizard
             * rejects. Two doors into one table must not enforce different
             * rules.
             */
            'boundary_wkt' => ['nullable', 'string', 'max:200000', new ValidWkt],

            'launch_date' => ['nullable', 'date'],
            'expected_delivery' => ['nullable', 'date'],
            'actual_delivery' => ['nullable', 'date'],

            'land_area_sqm' => ['nullable', 'integer', 'min:0'],
            'building_count' => ['nullable', 'integer', 'min:0'],
            'unit_count' => ['nullable', 'integer', 'min:0'],

            'source' => ['nullable', 'string', 'max:191'],
            'official_url' => ['nullable', 'url', 'max:255'],
            'confidence' => ['nullable', 'string', 'in:low,medium,high'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $lat = $this->input('latitude');
            $lng = $this->input('longitude');

            if ($lat !== null && $lng !== null && $lat !== '' && $lng !== '') {
                $coordinates = Coordinates::tryMake((string) $lat, (string) $lng);

                if ($coordinates === null) {
                    $validator->errors()->add('latitude', __('projects.errors.invalid_coordinates'));
                } elseif ($coordinates->isNullIsland()) {
                    $validator->errors()->add('latitude', __('projects.errors.null_island'));
                } elseif ($coordinates->looksSwapped()) {
                    // Distinguished from "outside Erbil" because this one is
                    // auto-correctable and the message can say so.
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

            // Delivery cannot precede launch. Cheap to check and it is the
            // commonest data-entry inversion in a date pair.
            $launch = $this->input('launch_date');
            $expected = $this->input('expected_delivery');

            if ($launch && $expected && strtotime((string) $expected) < strtotime((string) $launch)) {
                $validator->errors()->add('expected_delivery', __('projects.errors.delivery_before_launch'));
            }
        });
    }

    /** @param class-string $enum */
    private function enumValues(string $enum): string
    {
        return implode(',', array_map(static fn ($case): string => $case->value, $enum::cases()));
    }
}
