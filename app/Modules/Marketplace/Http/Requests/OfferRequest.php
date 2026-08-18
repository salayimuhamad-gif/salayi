<?php

declare(strict_types=1);

namespace App\Modules\Marketplace\Http\Requests;

use App\Modules\Geography\ValueObjects\Coordinates;
use App\Modules\Market\Enums\PropertyType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

final class OfferRequest extends FormRequest
{
    public function authorize(): bool
    {
        /*
         * `marketplace.offers.create` and `.update` DO NOT EXIST. Neither is in
         * the catalogue, so `hasPermission()` returned false for every real
         * role and only Super Admin — which bypasses the check entirely —
         * could create or edit an offer. The whole seller workflow was closed
         * and nothing said so.
         *
         * The real model is two permissions: platform moderation, or
         * management of the acting company's own listings. Ownership of THIS
         * offer is settled separately by OfferScope, because a permission
         * cannot know which company a row belongs to.
         */
        $user = $this->user();

        return ($user?->hasPermission('marketplace.offers.moderate') ?? false)
            || ($user?->hasPermission('marketplace.offers.manage_own') ?? false);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'title_ckb' => ['required', 'string', 'max:191'],
            'title_ar' => ['nullable', 'string', 'max:191'],
            'title_en' => ['nullable', 'string', 'max:191'],
            'description_ckb' => ['nullable', 'string', 'max:20000'],
            'offer_type' => ['required', 'string', 'in:sale,rent,investment,pre_launch'],
            'property_type' => ['required', 'string', 'in:'.implode(',', array_map(
                static fn (PropertyType $t): string => $t->value,
                PropertyType::cases(),
            ))],
            'unit_type' => ['nullable', 'string', 'max:64'],
            'company_id' => ['nullable', 'integer', 'exists:companies,id'],
            'project_id' => ['nullable', 'integer', 'exists:projects,id'],
            'area_id' => ['nullable', 'integer', 'exists:areas,id'],
            'location_precision' => ['required', 'string', 'in:exact,approximate,area_only'],
            'latitude' => ['nullable', 'numeric', 'between:-90,90'],
            'longitude' => ['nullable', 'numeric', 'between:-180,180'],
            'size_sqm' => ['nullable', 'numeric', 'min:0'],
            'rooms' => ['nullable', 'integer', 'between:0,50'],
            'bathrooms' => ['nullable', 'integer', 'between:0,50'],
            'floor' => ['nullable', 'integer', 'between:-5,200'],
            'price' => ['nullable', 'numeric', 'min:0'],
            'currency' => ['required', 'string', 'in:USD,IQD,EUR'],
            'availability' => ['required', 'string', 'in:available,reserved,sold,rented'],
            'contact_method' => ['required', 'string', 'in:platform,phone,whatsapp,telegram'],
            'source' => ['nullable', 'string', 'max:191'],
            'expires_at' => ['nullable', 'date', 'after:today'],
            'is_sponsored' => ['boolean'],
            'disclosure_label' => ['nullable', 'string', 'max:191'],
            'terms' => ['nullable', 'string', 'max:20000'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            // Spec 19.5 and 18.4. The model throws on this too; catching it
            // here makes it a field message rather than a 500.
            if ($this->boolean('is_sponsored') && trim((string) $this->input('disclosure_label')) === '') {
                $validator->errors()->add('disclosure_label', __('marketplace.errors.missing_disclosure'));
            }

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

            // An exact location claim needs an exact location. Publishing
            // "exact" with no coordinate would put a pin nowhere and label it
            // precise.
            if ($this->input('location_precision') === 'exact'
                && (trim((string) $lat) === '' || trim((string) $lng) === '')) {
                $validator->errors()->add('location_precision', __('marketplace.errors.exact_needs_coordinates'));
            }

            // An offer with neither a company nor an owner belongs to nobody
            // and no one can be asked about it (spec 19.1).
            if ($this->input('company_id') === null && $this->route('offer') === null) {
                $validator->errors()->add('company_id', __('marketplace.errors.owner_required'));
            }
        });
    }
}
