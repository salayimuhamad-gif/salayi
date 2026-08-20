<?php

declare(strict_types=1);

namespace App\Modules\Geography\Http\Requests;

use App\Modules\Geography\ValueObjects\Coordinates;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * Input validation for the public location-resolve endpoint (Wave 3).
 *
 * Two mutually exclusive input modes, one contract:
 *
 *   ?lat=…&lng=…   a browser-geolocation point to resolve geometrically;
 *   ?area=slug     a manually chosen published area (the "Choose Area
 *                  Manually" fallback re-uses the SAME endpoint so both paths
 *                  answer under identical publication and price rules).
 *
 * The coordinate ladder is the one the admin FormRequests already climb
 * (PlaceRequest, AreaRequest, ProjectRequest): range validation, then the
 * Coordinates value object's null-island and looks-swapped checks — the same
 * rules, the same error strings. The ONE deliberate difference: a coordinate
 * OUTSIDE the operating area is not a validation error here. On an admin form
 * a Baghdad point is a data-entry mistake; from a visitor's browser it is a
 * true statement about where they are standing, and the honest answer is the
 * controller's `outside_coverage` state, never a 422 that reads as "you typed
 * it wrong".
 */
final class LocationResolveRequest extends FormRequest
{
    /** A public read — there is nothing to authorise. */
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            // Same slug grammar the area profile route accepts.
            'area' => ['sometimes', 'required', 'string', 'max:120', 'regex:/^[a-z0-9\-]+$/', 'prohibits:lat,lng'],
            'lat' => ['required_without:area', 'numeric', 'between:-90,90'],
            'lng' => ['required_without:area', 'numeric', 'between:-180,180'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            // Manual mode carries no coordinates to inspect.
            if ($this->filled('area')) {
                return;
            }

            if ($validator->errors()->hasAny(['lat', 'lng'])) {
                return;
            }

            $coordinates = Coordinates::tryMake(
                (string) $this->input('lat'),
                (string) $this->input('lng'),
            );

            if ($coordinates === null) {
                $validator->errors()->add('lat', __('projects.errors.invalid_coordinates'));

                return;
            }

            if ($coordinates->isNullIsland()) {
                $validator->errors()->add('lat', __('projects.errors.null_island'));
            } elseif ($coordinates->looksSwapped()) {
                $validator->errors()->add('lat', __('projects.errors.coordinates_look_swapped'));
            }
        });
    }

    /** The validated point, for the geolocation mode. */
    public function coordinates(): Coordinates
    {
        return Coordinates::make(
            (string) $this->input('lat'),
            (string) $this->input('lng'),
        );
    }
}
