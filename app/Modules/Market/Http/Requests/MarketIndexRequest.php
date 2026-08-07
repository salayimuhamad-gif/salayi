<?php

declare(strict_types=1);

namespace App\Modules\Market\Http\Requests;

use App\Modules\Market\Enums\PriceType;
use App\Modules\Market\Enums\PropertyType;
use App\Modules\Market\Enums\ScopeType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * The keys `validated()` can return are exactly the rules above, and every
 * one of them is a column on the model this request feeds. Stating that
 * lets the analyser verify `Model::create($request->validated())` instead of
 * seeing an array of unknown strings — and it fails loudly if a rule is ever
 * added for a field the model does not have.
 *
 * @method array<'key'|'name_ckb'|'name_ar'|'name_en'|'scope_type'|'scope_id'|'property_type'|'price_type'|'basis'|'currency'|'methodology_version'|'methodology_ckb'|'methodology_ar'|'methodology_en'|'minimum_sample', mixed> validated(string|null $key = null, mixed $default = null)
 */
final class MarketIndexRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasPermission('market.indices.configure') ?? false;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $id = $this->route('index')?->id;

        return [
            'key' => ['required', 'string', 'max:64', 'regex:/^[a-z0-9\-]+$/', 'unique:market_indices,key'.($id ? ','.$id : '')],
            'name_ckb' => ['required', 'string', 'max:191'],
            'name_ar' => ['nullable', 'string', 'max:191'],
            'name_en' => ['nullable', 'string', 'max:191'],
            'scope_type' => ['required', 'string', 'in:'.$this->values(ScopeType::class)],
            'scope_id' => ['nullable', 'integer'],
            'property_type' => ['nullable', 'string', 'in:'.$this->values(PropertyType::class)],
            'price_type' => ['required', 'string', 'in:'.$this->values(PriceType::class)],
            'basis' => ['required', 'string', 'in:median,mean,weighted_mean,per_sqm'],
            'currency' => ['required', 'string', 'in:USD,IQD,EUR'],
            'methodology_version' => ['required', 'string', 'max:16'],
            'methodology_ckb' => ['nullable', 'string', 'max:20000'],
            'methodology_ar' => ['nullable', 'string', 'max:20000'],
            'methodology_en' => ['nullable', 'string', 'max:20000'],
            'minimum_sample' => ['required', 'integer', 'between:3,500'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $index = $this->route('index');

            // Changing the price type of a published index would silently
            // redefine every historical value beneath it. The model throws;
            // catching it here makes it a field message instead of a 500.
            if ($index !== null
                && $index->publication_status === 'published'
                && $this->input('price_type') !== $index->price_type->value) {
                $validator->errors()->add('price_type', __('market.indices.price_type_locked'));
            }

            // A methodology change without a version bump makes historical
            // values incomparable while claiming they are comparable —
            // IndexCalculator::change() refuses across versions, and that
            // refusal only works if the version actually moves.
            if ($index !== null
                && $this->input('methodology_version') === $index->methodology_version
                && $this->methodologyChanged($index)) {
                $validator->errors()->add('methodology_version', __('market.indices.version_must_change'));
            }
        });
    }

    private function methodologyChanged(mixed $index): bool
    {
        foreach (['basis', 'minimum_sample', 'currency'] as $field) {
            if ((string) $this->input($field) !== (string) $index->{$field}) {
                return true;
            }
        }

        return false;
    }

    /** @param class-string $enum */
    private function values(string $enum): string
    {
        return implode(',', array_map(static fn ($c): string => $c->value, $enum::cases()));
    }
}
