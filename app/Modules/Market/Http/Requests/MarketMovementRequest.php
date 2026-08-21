<?php

declare(strict_types=1);

namespace App\Modules\Market\Http\Requests;

use App\Modules\Market\Enums\PropertyType;
use App\Modules\Market\Services\MarketMovementService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Query contract for the public movement endpoint (Wave 4).
 *
 * Every parameter is validated against the closed vocabularies the domain
 * already declares — the service's window list, its transaction list, the
 * PropertyType enum — so an unknown value is a 422 at the boundary, never a
 * silent empty answer that reads as "the market did not move". The category
 * list is derived from the enum at call time: a case added to PropertyType
 * is accepted here without anyone editing this file.
 */
final class MarketMovementRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'transaction' => ['sometimes', 'string', Rule::in(MarketMovementService::TRANSACTIONS)],
            'period' => ['sometimes', 'string', Rule::in(MarketMovementService::WINDOWS)],
            'property_types' => ['sometimes', 'array', 'max:'.count(PropertyType::cases())],
            'property_types.*' => ['string', Rule::enum(PropertyType::class)],
        ];
    }

    public function transactionMode(): string
    {
        return (string) $this->validated('transaction', 'sale');
    }

    public function window(): string
    {
        return (string) $this->validated('period', 'all');
    }

    /**
     * @return list<string>
     */
    public function propertyTypes(): array
    {
        /** @var list<string> $types */
        $types = array_values((array) $this->validated('property_types', []));

        return array_values(array_unique($types));
    }
}
